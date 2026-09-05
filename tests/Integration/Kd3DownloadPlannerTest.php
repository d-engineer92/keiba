<?php

namespace Tests\Integration;

use App\Kd3\Kd3DownloadPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Kd3DownloadPlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_distinct_eligible_calendar_dates_within_range(): void
    {
        $venue1 = DB::table('venues')->insertGetId(['name' => 'Synthetic 1']);
        $venue2 = DB::table('venues')->insertGetId(['name' => 'Synthetic 2']);
        DB::table('race_calendars')->insert([
            ['venue_id' => $venue1, 'race_date' => '2026-09-05', 'status' => 'scheduled'],
            ['venue_id' => $venue2, 'race_date' => '2026-09-05', 'status' => 'completed'],
            ['venue_id' => $venue1, 'race_date' => '2026-09-06', 'status' => 'cancelled'],
            ['venue_id' => $venue2, 'race_date' => '2026-09-06', 'status' => 'deleted'],
            ['venue_id' => $venue1, 'race_date' => '2026-09-07', 'status' => 'scheduled'],
        ]);

        $dates = (new Kd3DownloadPlanner)->dates(
            CarbonImmutable::parse('2026-09-05', 'Asia/Tokyo'),
            CarbonImmutable::parse('2026-09-06', 'Asia/Tokyo'),
        );

        $this->assertSame(['2026-09-05'], array_map(fn (CarbonImmutable $date): string => $date->toDateString(), $dates));
    }
}
