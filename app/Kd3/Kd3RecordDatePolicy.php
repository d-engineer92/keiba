<?php

namespace App\Kd3;

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

        return $artifactType === 'kd'
            && $artifactDate < self::CONFIRMED_ODDS_DAILY_CUTOVER
            && $recordDate > $artifactDate
            && $recordDate < self::CONFIRMED_ODDS_DAILY_CUTOVER;
    }
}
