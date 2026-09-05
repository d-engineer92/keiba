<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class IngestJvLinkSchedules
{
    /** @param array{captured_at: string, schedules: array<int, array<string, mixed>>} $payload */
    public function ingest(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            // Snapshot PoC: serialize this endpoint's batches, including missing mappings/rows.
            DB::select("SELECT pg_advisory_xact_lock(hashtext('keiba.jvlink.schedule_ingest'))");
            $captured = CarbonImmutable::parse($payload['captured_at'])->utc()->startOfSecond();
            $now = CarbonImmutable::now('UTC')->startOfSecond();
            $counts = ['received' => count($payload['schedules']), 'inserted' => 0, 'updated' => 0, 'unchanged' => 0];
            foreach ($payload['schedules'] as $schedule) {
                $venue = $this->venue($schedule, $captured, $now);
                $key = ['venue_id' => $venue, 'race_date' => $schedule['race_date']];
                $existing = DB::table('race_calendars')->where($key)->lockForUpdate()->first();
                $sourceUpdated = $schedule['source_updated_at'] === null ? null
                    : CarbonImmutable::parse($schedule['source_updated_at'])->utc()->startOfSecond();
                $snapshot = [
                    'meeting_no' => $schedule['meeting_no'], 'meeting_day' => $schedule['meeting_day'],
                    'status' => $schedule['status'], 'source_updated_at' => $sourceUpdated,
                ];
                if ($existing === null) {
                    DB::table('race_calendars')->insert($key + $snapshot + [
                        'first_seen_at' => $captured, 'last_seen_at' => $captured,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $counts['inserted']++;

                    continue;
                }
                $oldSource = $existing->source_updated_at === null ? null : CarbonImmutable::parse($existing->source_updated_at);
                $oldSeen = $existing->last_seen_at === null ? null : CarbonImmutable::parse($existing->last_seen_at);
                $stale = $sourceUpdated !== null && $oldSource !== null
                    ? $sourceUpdated->lessThan($oldSource)
                    : ($oldSeen !== null && $captured->lessThan($oldSeen));
                $lastSeen = $oldSeen !== null && $oldSeen->greaterThan($captured) ? $oldSeen : $captured;
                $changes = ['last_seen_at' => $lastSeen];
                if (! $stale) {
                    $changes += $snapshot;
                }
                $changed = false;
                foreach ($changes as $column => $value) {
                    $previous = $existing->{$column};
                    $equal = $value instanceof CarbonImmutable
                        ? ($previous !== null && $value->equalTo(CarbonImmutable::parse($previous)))
                        : $value === $previous;
                    $changed = $changed || ! $equal;
                }
                if ($changed) {
                    DB::table('race_calendars')->where('id', $existing->id)->update($changes + ['updated_at' => $now]);
                }
                $counts[$changed ? 'updated' : 'unchanged']++;
            }

            return $counts;
        }, 3);
    }

    /** @param array<string, mixed> $schedule */
    private function venue(array $schedule, CarbonImmutable $captured, CarbonImmutable $now): int
    {
        $key = [
            'source_system' => 'jvlink', 'entity_type' => 'venue',
            'identifier_type' => 'venue_code', 'identifier_value' => $schedule['venue_code'],
        ];
        $mapping = DB::table('source_identifiers')->where($key)->lockForUpdate()->first();
        $name = $schedule['venue_name'];
        if ($mapping !== null) {
            $venue = DB::table('venues')->where('id', $mapping->entity_id)->lockForUpdate()->first();
            if ($venue === null || ($name !== null && $name !== $venue->name)) {
                throw new ConflictHttpException('Venue mapping conflicts with the supplied name or references a missing venue.');
            }
            if ($mapping->last_seen_at === null || $captured->greaterThan(CarbonImmutable::parse($mapping->last_seen_at))) {
                DB::table('source_identifiers')->where('id', $mapping->id)->update(['last_seen_at' => $captured, 'updated_at' => $now]);
            }

            return $venue->id;
        }
        if ($name === null) {
            throw new ConflictHttpException('An unknown venue code requires a venue name.');
        }
        DB::table('venues')->insertOrIgnore(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
        $venue = DB::table('venues')->where('name', $name)->lockForUpdate()->first();
        if ($venue === null) {
            throw new ConflictHttpException('Unable to resolve venue.');
        }
        DB::table('source_identifiers')->insert($key + [
            'entity_id' => $venue->id, 'first_seen_at' => $captured, 'last_seen_at' => $captured,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        return $venue->id;
    }
}
