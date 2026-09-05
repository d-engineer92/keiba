<?php

namespace App\Kd3\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RaceResolver
{
    /** @var array<string, string> */
    private const CENTRAL_VENUES = ['00' => '京都', '01' => '阪神', '02' => '中京', '03' => '小倉', '04' => '東京', '05' => '中山', '06' => '福島', '07' => '新潟', '08' => '札幌', '09' => '函館'];

    /** @param array<string, mixed> $fields */
    public function resolve(array $fields, bool $create = true): ?int
    {
        $venueId = $this->venue((string) $fields['venue_code'], $create);
        if ($venueId === null) {
            return null;
        }
        $date = CarbonImmutable::createFromFormat('!Ymd', (string) $fields['race_date'], 'UTC');
        if ($date === null) {
            throw new Kd3ImportException('Invalid race date.', 'mapping', 'race', RaceKey::from($fields));
        }
        $calendar = DB::table('race_calendars')->where(['race_date' => $date->format('Y-m-d'), 'venue_id' => $venueId])->lockForUpdate()->first();
        $meetingNo = $this->numberOrNull($fields['meeting_no'] ?? null);
        $meetingDay = $this->numberOrNull($fields['meeting_day'] ?? null);
        $now = CarbonImmutable::now('UTC');
        if ($calendar === null) {
            if (! $create) {
                return null;
            }
            $calendarId = DB::table('race_calendars')->insertGetId(['venue_id' => $venueId, 'race_date' => $date->format('Y-m-d'),
                'meeting_no' => $meetingNo, 'meeting_day' => $meetingDay, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now]);
        } else {
            foreach (['meeting_no' => $meetingNo, 'meeting_day' => $meetingDay] as $field => $incoming) {
                if ($incoming !== null && $calendar->{$field} !== null && (int) $calendar->{$field} !== $incoming) {
                    throw new Kd3ImportException('KD3 meeting data conflicts with the canonical calendar.', 'reconciliation', 'race_calendar', RaceKey::from($fields));
                }
            }
            $updates = [];
            if ($calendar->meeting_no === null && $meetingNo !== null) {
                $updates['meeting_no'] = $meetingNo;
            }
            if ($calendar->meeting_day === null && $meetingDay !== null) {
                $updates['meeting_day'] = $meetingDay;
            }
            if ($updates !== []) {
                $updates['updated_at'] = $now;
                DB::table('race_calendars')->where('id', $calendar->id)->update($updates);
            }
            $calendarId = (int) $calendar->id;
        }
        $raceNo = (int) $fields['race_no'];
        $race = DB::table('races')->where(['race_calendar_id' => $calendarId, 'race_no' => $raceNo])->lockForUpdate()->first();
        if ($race === null) {
            if (! $create) {
                return null;
            }
            $raceId = DB::table('races')->insertGetId(['race_calendar_id' => $calendarId, 'race_no' => $raceNo, 'status' => 'scheduled', 'created_at' => $now, 'updated_at' => $now]);
        } else {
            $raceId = (int) $race->id;
        }
        $this->map('race', $raceId, 'race_key', RaceKey::from($fields), $now);

        return $raceId;
    }

    private function venue(string $code, bool $create): ?int
    {
        $mapping = DB::table('source_identifiers')->where(['source_system' => 'kd3', 'entity_type' => 'venue', 'identifier_type' => 'venue_code', 'identifier_value' => $code])->first();
        if ($mapping !== null) {
            return (int) $mapping->entity_id;
        }
        $name = self::CENTRAL_VENUES[$code] ?? null;
        if (! $create || $name === null) {
            return null;
        }
        $now = CarbonImmutable::now('UTC');
        DB::table('venues')->insertOrIgnore(['name' => $name, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        $venue = DB::table('venues')->where('name', $name)->lockForUpdate()->first();
        if ($venue === null) {
            throw new Kd3ImportException('Venue could not be resolved.', 'mapping', 'venue', $code);
        }
        $this->map('venue', (int) $venue->id, 'venue_code', $code, $now);

        return (int) $venue->id;
    }

    private function map(string $entity, int $id, string $type, string $value, CarbonImmutable $now): void
    {
        $mapping = DB::table('source_identifiers')->where(['source_system' => 'kd3', 'entity_type' => $entity, 'identifier_type' => $type, 'identifier_value' => $value])->lockForUpdate()->first();
        if ($mapping !== null && (int) $mapping->entity_id !== $id) {
            throw new Kd3ImportException('External identifier conflicts with an existing canonical entity.', 'identity_conflict', $entity, $value);
        }
        if ($mapping === null) {
            DB::table('source_identifiers')->insert(['source_system' => 'kd3', 'entity_type' => $entity, 'entity_id' => $id,
                'identifier_type' => $type, 'identifier_value' => $value, 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        } else {
            DB::table('source_identifiers')->where('id', $mapping->id)->update(['last_seen_at' => $now, 'updated_at' => $now]);
        }
    }

    private function numberOrNull(mixed $value): ?int
    {
        return $value === null || (int) $value === 0 ? null : (int) $value;
    }
}
