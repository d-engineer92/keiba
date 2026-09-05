<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class IngestJvLinkEvents
{
    /** @param array{events: array<int, array<string, mixed>>} $batch */
    public function ingest(array $batch): array
    {
        return DB::transaction(function () use ($batch): array {
            DB::select("SELECT pg_advisory_xact_lock(hashtext('keiba.jvlink.event_ingest'))");
            $counts = ['received' => count($batch['events']), 'inserted' => 0, 'unchanged' => 0, 'conflicted' => 0];
            foreach ($batch['events'] as $event) {
                $existing = DB::table('jvlink_events')->where('source_event_id', $event['source_event_id'])->lockForUpdate()->first();
                if ($existing !== null) {
                    if (! hash_equals($existing->payload_sha256, $event['payload_sha256'])) {
                        throw new ConflictHttpException('A source event ID was reused with a different payload hash.');
                    }
                    $counts['unchanged']++;

                    continue;
                }
                $now = CarbonImmutable::now('UTC');
                $eventId = DB::table('jvlink_events')->insertGetId([
                    'source_event_id' => $event['source_event_id'],
                    'event_type' => $event['event_type'],
                    'source_data_spec' => $event['source_data_spec'],
                    'source_record_type' => $event['source_record_type'],
                    'source_published_at' => $this->time($event['source_published_at']),
                    'effective_at' => $this->time($event['effective_at']),
                    'captured_at' => $this->time($event['captured_at']),
                    'received_at' => $now,
                    'payload_sha256' => $event['payload_sha256'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                /** @var array<string, mixed> $payload */
                $payload = $event['payload'];
                $this->storeTypedEvent($eventId, $event['event_type'], $payload, $now);
                $counts['inserted']++;
            }

            return $counts;
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function storeTypedEvent(int $eventId, string $type, array $payload, CarbonImmutable $now): void
    {
        if ($type === 'weather_track') {
            [$calendarId, $venueId] = $this->calendar($payload['race_date'], $payload['venue_code']);
            DB::table('weather_track_events')->insert([
                'jvlink_event_id' => $eventId, 'race_calendar_id' => $calendarId, 'venue_id' => $venueId,
                'change_type' => $payload['change_type'], 'weather' => $payload['weather'],
                'turf_condition' => $payload['turf_condition'], 'dirt_condition' => $payload['dirt_condition'],
                'created_at' => $now, 'updated_at' => $now,
            ]);

            return;
        }

        $raceId = $this->race($payload, $now);
        if ($type === 'odds_snapshot') {
            $snapshotId = DB::table('race_odds_snapshots')->insertGetId([
                'jvlink_event_id' => $eventId, 'race_id' => $raceId, 'source_kind' => $payload['source_kind'],
                'snapshot_at' => $this->time($payload['snapshot_at']), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $seen = [];
            foreach ($payload['items'] as $item) {
                $key = $item['bet_type'].':'.$item['horse_no'];
                if (isset($seen[$key])) {
                    throw new ConflictHttpException('An odds snapshot contains a duplicate market item.');
                }
                $seen[$key] = true;
                DB::table('race_odds_snapshot_items')->insert([
                    'snapshot_id' => $snapshotId, 'bet_type' => $item['bet_type'], 'horse_no' => $item['horse_no'],
                    'horse_id' => $this->horse($raceId, $item['horse_no']), 'odds' => $item['odds'],
                    'odds_min' => $item['odds_min'], 'odds_max' => $item['odds_max'], 'status' => $item['status'],
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            return;
        }

        $horseId = $this->horse($raceId, $payload['horse_no']);
        if ($type === 'runner_status') {
            DB::table('runner_status_events')->insert([
                'jvlink_event_id' => $eventId, 'race_id' => $raceId, 'horse_no' => $payload['horse_no'],
                'horse_id' => $horseId, 'status_type' => $payload['status_type'], 'reason_code' => $payload['reason_code'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ($type === 'jockey_change') {
            DB::table('jockey_change_events')->insert([
                'jvlink_event_id' => $eventId, 'race_id' => $raceId, 'horse_no' => $payload['horse_no'],
                'horse_id' => $horseId,
                'old_jockey_id' => $this->jockey($payload['old_jockey_code'], $payload['old_jockey_name'], $now),
                'new_jockey_id' => $this->jockey($payload['new_jockey_code'], $payload['new_jockey_name'], $now),
                'created_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ($type === 'body_weight') {
            DB::table('body_weight_snapshots')->insert([
                'jvlink_event_id' => $eventId, 'race_id' => $raceId, 'horse_no' => $payload['horse_no'],
                'horse_id' => $horseId, 'body_weight' => $payload['body_weight'],
                'body_weight_delta' => $payload['body_weight_delta'], 'source_status_code' => $payload['source_status_code'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /** @param array<string, mixed> $payload */
    private function race(array $payload, CarbonImmutable $now): int
    {
        [$calendarId] = $this->calendar($payload['race_date'], $payload['venue_code']);
        $race = DB::table('races')->where(['race_calendar_id' => $calendarId, 'race_no' => $payload['race_no']])->lockForUpdate()->first();
        if ($race === null) {
            throw new ConflictHttpException('The JV-Link event cannot be resolved to an existing canonical race.');
        }
        $external = $payload['jvlink_race_id'];
        if ($external !== null) {
            $key = ['source_system' => 'jvlink', 'entity_type' => 'race', 'identifier_type' => 'race_code', 'identifier_value' => $external];
            $mapping = DB::table('source_identifiers')->where($key)->lockForUpdate()->first();
            if ($mapping !== null && $mapping->entity_id !== $race->id) {
                throw new ConflictHttpException('The JV-Link race identifier conflicts with an existing canonical mapping.');
            }
            if ($mapping === null) {
                DB::table('source_identifiers')->insert($key + [
                    'entity_id' => $race->id, 'first_seen_at' => $now, 'last_seen_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        return $race->id;
    }

    /** @return array{int, int} */
    private function calendar(string $date, string $venueCode): array
    {
        $mapping = DB::table('source_identifiers')->where([
            'source_system' => 'jvlink', 'entity_type' => 'venue', 'identifier_type' => 'venue_code',
            'identifier_value' => $venueCode,
        ])->lockForUpdate()->first();
        if ($mapping === null || ! DB::table('venues')->where('id', $mapping->entity_id)->exists()) {
            throw new ConflictHttpException('The JV-Link venue identifier is not mapped.');
        }
        $calendar = DB::table('race_calendars')->where(['venue_id' => $mapping->entity_id, 'race_date' => $date])->lockForUpdate()->first();
        if ($calendar === null) {
            throw new ConflictHttpException('The JV-Link event cannot be resolved to an existing race calendar.');
        }

        return [$calendar->id, $mapping->entity_id];
    }

    private function horse(int $raceId, int $horseNo): ?int
    {
        $ids = DB::table('race_entry_runners')->join('race_entries', 'race_entries.id', '=', 'race_entry_runners.race_entry_id')
            ->where(['race_entries.race_id' => $raceId, 'race_entry_runners.horse_no' => $horseNo])
            ->pluck('race_entry_runners.horse_id')
            ->merge(DB::table('race_result_runners')->join('race_results', 'race_results.id', '=', 'race_result_runners.race_result_id')
                ->where(['race_results.race_id' => $raceId, 'race_result_runners.horse_no' => $horseNo])
                ->pluck('race_result_runners.horse_id'))
            ->unique()->values();
        if ($ids->count() > 1) {
            throw new ConflictHttpException('The race runner mappings disagree for this horse number.');
        }

        return $ids->isEmpty() ? null : (int) $ids->first();
    }

    private function jockey(?string $code, ?string $name, CarbonImmutable $now): ?int
    {
        if ($code === null || trim($code, ' 0') === '') {
            return null;
        }
        $key = ['source_system' => 'jvlink', 'entity_type' => 'jockey', 'identifier_type' => 'jockey_code', 'identifier_value' => $code];
        $mapping = DB::table('source_identifiers')->where($key)->lockForUpdate()->first();
        if ($mapping !== null) {
            $jockey = DB::table('jockeys')->where('id', $mapping->entity_id)->first();
            if ($jockey === null || ($name !== null && $jockey->name !== null && $name !== $jockey->name)) {
                throw new ConflictHttpException('The JV-Link jockey identifier conflicts with an existing mapping.');
            }

            return $mapping->entity_id;
        }
        $id = DB::table('jockeys')->insertGetId(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('source_identifiers')->insert($key + [
            'entity_id' => $id, 'first_seen_at' => $now, 'last_seen_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $id;
    }

    private function time(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }
}
