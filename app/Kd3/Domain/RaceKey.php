<?php

namespace App\Kd3\Domain;

final class RaceKey
{
    /** @param array<string, mixed> $fields */
    public static function from(array $fields): string
    {
        return implode(':', array_map(static fn (string $field): string => (string) ($fields[$field] ?? ''), ['venue_code', 'year', 'meeting_no', 'meeting_day', 'race_no']));
    }

    /** @param array<string, mixed> $fields */
    public static function history(array $fields): string
    {
        return hash('sha256', implode('|', array_map(static fn (string $field): string => (string) ($fields[$field] ?? ''), ['venue_code', 'year', 'meeting_no', 'meeting_day', 'race_no', 'race_date'])));
    }
}
