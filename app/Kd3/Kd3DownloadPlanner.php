<?php

namespace App\Kd3;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Kd3DownloadPlanner
{
    /** @return list<CarbonImmutable> */
    public function dates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('race_calendars')
            ->whereBetween('race_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotIn('status', ['cancelled', 'deleted'])
            ->distinct()
            ->orderBy('race_date')
            ->pluck('race_date')
            ->map(fn (string $date): CarbonImmutable => CarbonImmutable::parse($date, 'Asia/Tokyo')->startOfDay())
            ->values()
            ->all();
    }
}
