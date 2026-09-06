<?php

namespace App\Kd3\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RaceResolver
{
    /** @var array<string, string> */
    private const CENTRAL_VENUES = ['00' => '京都', '01' => '阪神', '02' => '中京', '03' => '小倉', '04' => '東京', '05' => '中山', '06' => '福島', '07' => '新潟', '08' => '札幌', '09' => '函館'];

    /** @var array<string, int> */
    private array $venueCache = [];

    /** @var array<string, array{id:int,meeting_no:?int,meeting_day:?int}> */
    private array $calendarCache = [];

    /** @var array<string, int> */
    private array $raceCache = [];

    /** @var array<string, int> */
    private array $mappingCache = [];

    public function resetCache(): void
    {
        $this->venueCache = [];
        $this->calendarCache = [];
        $this->raceCache = [];
        $this->mappingCache = [];
    }

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
        $raceDate = $date->format('Y-m-d');
        $meetingNo = $this->numberOrNull($fields['meeting_no'] ?? null);
        $meetingDay = $this->numberOrNull($fields['meeting_day'] ?? null);
        $calendarId = $this->calendar($venueId, $raceDate, $meetingNo, $meetingDay, $create, RaceKey::from($fields));
        if ($calendarId === null) {
            return null;
        }

        $raceNo = (int) $fields['race_no'];
        $raceCacheKey = $calendarId.':'.$raceNo;
        if (isset($this->raceCache[$raceCacheKey])) {
            return $this->raceCache[$raceCacheKey];
        }

        $race = DB::table('races')->where(['race_calendar_id' => $calendarId, 'race_no' => $raceNo])->lockForUpdate()->first();
        $now = CarbonImmutable::now('UTC');
        if ($race === null) {
            if (! $create) {
                return null;
            }
            $raceId = (int) DB::table('races')->insertGetId([
                'race_calendar_id' => $calendarId,
                'race_no' => $raceNo,
                'status' => 'scheduled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $raceId = (int) $race->id;
        }
        $this->map('race', $raceId, 'race_key', RaceKey::from($fields), $now);
        $this->raceCache[$raceCacheKey] = $raceId;

        return $raceId;
    }

    private function calendar(
        int $venueId,
        string $raceDate,
        ?int $meetingNo,
        ?int $meetingDay,
        bool $create,
        string $raceKey,
    ): ?int {
        $cacheKey = $raceDate.':'.$venueId;
        if (isset($this->calendarCache[$cacheKey])) {
            $calendar = $this->calendarCache[$cacheKey];
            $this->assertMeetingCompatible($calendar['meeting_no'], $meetingNo, $calendar['meeting_day'], $meetingDay, $raceKey);
            $updates = [];
            if ($calendar['meeting_no'] === null && $meetingNo !== null) {
                $updates['meeting_no'] = $meetingNo;
                $this->calendarCache[$cacheKey]['meeting_no'] = $meetingNo;
            }
            if ($calendar['meeting_day'] === null && $meetingDay !== null) {
                $updates['meeting_day'] = $meetingDay;
                $this->calendarCache[$cacheKey]['meeting_day'] = $meetingDay;
            }
            if ($updates !== []) {
                $updates['updated_at'] = CarbonImmutable::now('UTC');
                DB::table('race_calendars')->where('id', $calendar['id'])->update($updates);
            }

            return $calendar['id'];
        }

        $calendar = DB::table('race_calendars')->where(['race_date' => $raceDate, 'venue_id' => $venueId])->lockForUpdate()->first();
        $now = CarbonImmutable::now('UTC');
        if ($calendar === null) {
            if (! $create) {
                return null;
            }
            $calendarId = (int) DB::table('race_calendars')->insertGetId([
                'venue_id' => $venueId,
                'race_date' => $raceDate,
                'meeting_no' => $meetingNo,
                'meeting_day' => $meetingDay,
                'status' => 'scheduled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $resolvedMeetingNo = $meetingNo;
            $resolvedMeetingDay = $meetingDay;
        } else {
            $resolvedMeetingNo = $calendar->meeting_no === null ? null : (int) $calendar->meeting_no;
            $resolvedMeetingDay = $calendar->meeting_day === null ? null : (int) $calendar->meeting_day;
            $this->assertMeetingCompatible($resolvedMeetingNo, $meetingNo, $resolvedMeetingDay, $meetingDay, $raceKey);
            $updates = [];
            if ($resolvedMeetingNo === null && $meetingNo !== null) {
                $updates['meeting_no'] = $meetingNo;
                $resolvedMeetingNo = $meetingNo;
            }
            if ($resolvedMeetingDay === null && $meetingDay !== null) {
                $updates['meeting_day'] = $meetingDay;
                $resolvedMeetingDay = $meetingDay;
            }
            if ($updates !== []) {
                $updates['updated_at'] = $now;
                DB::table('race_calendars')->where('id', $calendar->id)->update($updates);
            }
            $calendarId = (int) $calendar->id;
        }

        $this->calendarCache[$cacheKey] = [
            'id' => $calendarId,
            'meeting_no' => $resolvedMeetingNo,
            'meeting_day' => $resolvedMeetingDay,
        ];

        return $calendarId;
    }

    private function assertMeetingCompatible(?int $existingNo, ?int $incomingNo, ?int $existingDay, ?int $incomingDay, string $raceKey): void
    {
        if ($incomingNo !== null && $existingNo !== null && $incomingNo !== $existingNo) {
            throw new Kd3ImportException('KD3 meeting data conflicts with the canonical calendar.', 'reconciliation', 'race_calendar', $raceKey);
        }
        if ($incomingDay !== null && $existingDay !== null && $incomingDay !== $existingDay) {
            throw new Kd3ImportException('KD3 meeting data conflicts with the canonical calendar.', 'reconciliation', 'race_calendar', $raceKey);
        }
    }

    private function venue(string $code, bool $create): ?int
    {
        if (isset($this->venueCache[$code])) {
            return $this->venueCache[$code];
        }
        $mapping = DB::table('source_identifiers')->where([
            'source_system' => 'kd3',
            'entity_type' => 'venue',
            'identifier_type' => 'venue_code',
            'identifier_value' => $code,
        ])->first();
        if ($mapping !== null) {
            return $this->venueCache[$code] = (int) $mapping->entity_id;
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

        return $this->venueCache[$code] = (int) $venue->id;
    }

    private function map(string $entity, int $id, string $type, string $value, CarbonImmutable $now): void
    {
        $cacheKey = implode(':', [$entity, $type, $value]);
        if (isset($this->mappingCache[$cacheKey])) {
            if ($this->mappingCache[$cacheKey] !== $id) {
                throw new Kd3ImportException('External identifier conflicts with an existing canonical entity.', 'identity_conflict', $entity, $value);
            }

            return;
        }

        $mapping = DB::table('source_identifiers')->where([
            'source_system' => 'kd3',
            'entity_type' => $entity,
            'identifier_type' => $type,
            'identifier_value' => $value,
        ])->lockForUpdate()->first();
        if ($mapping !== null && (int) $mapping->entity_id !== $id) {
            throw new Kd3ImportException('External identifier conflicts with an existing canonical entity.', 'identity_conflict', $entity, $value);
        }
        if ($mapping === null) {
            DB::table('source_identifiers')->insert([
                'source_system' => 'kd3',
                'entity_type' => $entity,
                'entity_id' => $id,
                'identifier_type' => $type,
                'identifier_value' => $value,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->mappingCache[$cacheKey] = $id;
    }

    private function numberOrNull(mixed $value): ?int
    {
        return $value === null || (int) $value === 0 ? null : (int) $value;
    }
}
