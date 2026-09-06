<?php

namespace App\Kd3;

use DateTimeImmutable;

final class Kd3RecordDatePolicy
{
    private const CONFIRMED_ODDS_DAILY_CUTOVER = '20071013';

    public function accepts(string $artifactType, string $internalFile, string $artifactDate, string $recordDate): bool
    {
        if ($internalFile === 'kol_com1.kd3') {
            return true;
        }

        if ($recordDate === $artifactDate) {
            return true;
        }

        // KD3 forecast-odds packs may contain the next day's race when advance sales are
        // published on the previous day (the official spec gives Saturday G1 sales as an example).
        if ($artifactType === 'jb' && in_array($internalFile, ['kol_ods.kd3', 'kol_ods2.kd3'], true)) {
            $date = DateTimeImmutable::createFromFormat('!Ymd', $artifactDate);

            return $date !== false && $date->modify('+1 day')->format('Ymd') === $recordDate;
        }

        return $artifactType === 'kd'
            && $artifactDate < self::CONFIRMED_ODDS_DAILY_CUTOVER
            && $recordDate > $artifactDate
            && $recordDate < self::CONFIRMED_ODDS_DAILY_CUTOVER;
    }
}
