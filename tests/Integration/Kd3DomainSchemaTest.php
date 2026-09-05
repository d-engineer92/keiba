<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class Kd3DomainSchemaTest extends TestCase
{
    use DatabaseTransactions;

    public function test_domain_natural_keys_checks_and_restrict_foreign_keys_are_enforced(): void
    {
        $venue = DB::table('venues')->insertGetId(['name' => 'Domain schema venue']);
        $calendar = DB::table('race_calendars')->insertGetId(['venue_id' => $venue, 'race_date' => '2026-09-05']);
        $race = DB::table('races')->insertGetId(['race_calendar_id' => $calendar, 'race_no' => 1]);
        $source = DB::table('source_files')->insertGetId(['source_system' => 'kd3', 'artifact_type' => 'hb', 'race_date' => '2026-09-05',
            'original_filename' => 'synthetic.lzh', 'storage_disk' => 'local', 'storage_path' => 'synthetic', 'sha256' => str_repeat('a', 64),
            'size_bytes' => 0, 'source_url' => 'https://example.test/synthetic', 'downloaded_at' => now()]);
        $horse = DB::table('horses')->insertGetId(['name' => 'Synthetic horse']);
        $entry = DB::table('race_entries')->insertGetId(['race_id' => $race, 'source_file_id' => $source, 'source_record_number' => 1, 'declared_runner_count' => 1]);
        DB::table('race_entry_runners')->insert(['race_entry_id' => $entry, 'horse_id' => $horse, 'horse_no' => 1, 'source_file_id' => $source, 'source_record_number' => 1]);
        $runner = DB::table('race_entry_runners')->where('race_entry_id', $entry)->firstOrFail();
        DB::table('runner_speed_indices')->insert(['race_entry_runner_id' => $runner->id, 'target_race_id' => $race, 'horse_id' => $horse,
            'central_flat_run_back' => 1, 'mapping_status' => 'unresolved', 'source_file_id' => $source, 'source_record_number' => 1]);

        $this->expectException(QueryException::class);
        DB::table('runner_speed_indices')->insert(['race_entry_runner_id' => $runner->id, 'target_race_id' => $race, 'horse_id' => $horse,
            'central_flat_run_back' => 6, 'mapping_status' => 'unresolved', 'source_file_id' => $source, 'source_record_number' => 1]);
    }
}
