<?php

namespace Tests\Feature;

use App\Console\Commands\DownloadKd3;
use App\Kd3\Kd3FetchResult;
use App\Kd3\Kd3Gateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeKd3Gateway;
use Tests\Support\SyntheticKd3;
use Tests\TestCase;

class DownloadKd3CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_intersects_range_with_calendar_and_filters_artifact_types(): void
    {
        Storage::fake('kd3-command');
        config(['kd3.storage_disk' => 'kd3-command']);
        $gateway = new FakeKd3Gateway;
        $gateway->queue('2026-09-05', 'hb', Kd3FetchResult::available(
            SyntheticKd3::zip('kd3_hb260905.lzh', 'synthetic'), 'hb.zip', 'https://www.keibado.net/kdata/download', 200,
        ));
        $this->app->instance(Kd3Gateway::class, $gateway);
        $venue = DB::table('venues')->insertGetId(['name' => 'Synthetic venue']);
        DB::table('race_calendars')->insert(['venue_id' => $venue, 'race_date' => '2026-09-05', 'status' => 'scheduled']);

        $this->artisan('kd3:download', [
            '--from' => '2026-09-04', '--to' => '2026-09-06', '--type' => ['hb'], '--workers' => '1',
        ])->expectsOutput('2026-09-05 hb downloaded')->assertSuccessful();

        $this->assertSame(['2026-09-05:hb'], $gateway->requests);
        $this->assertDatabaseCount('source_files', 1);
    }

    public function test_workers_default_to_four(): void
    {
        $command = $this->app->make(DownloadKd3::class);

        $this->assertEquals('4', $command->getDefinition()->getOption('workers')->getDefault());
    }

    public function test_unknown_or_conflicting_options_are_rejected(): void
    {
        $this->artisan('kd3:download', ['--type' => ['unknown'], '--workers' => '1'])->assertExitCode(2);
        $this->artisan('kd3:download', ['--date' => '2026-09-05', '--from' => '2026-09-01', '--workers' => '1'])->assertExitCode(2);
        $this->artisan('kd3:download', ['--workers' => '0'])->assertExitCode(2);
    }
}
