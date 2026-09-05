<?php

namespace Tests\Integration;

use App\Kd3\Kd3Downloader;
use App\Kd3\Kd3Exception;
use App\Kd3\Kd3FetchResult;
use App\Kd3\Kd3Gateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeKd3Gateway;
use Tests\Support\SyntheticKd3;
use Tests\TestCase;

class Kd3DownloaderTest extends TestCase
{
    use RefreshDatabase;

    private FakeKd3Gateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('kd3-test');
        config([
            'kd3.storage_disk' => 'kd3-test',
        ]);
        $this->gateway = new FakeKd3Gateway;
        $this->app->instance(Kd3Gateway::class, $this->gateway);
    }

    public function test_same_content_is_idempotent_and_changed_content_creates_a_new_version(): void
    {
        $date = CarbonImmutable::parse('2026-09-05', 'Asia/Tokyo');
        $this->gateway->queue('2026-09-05', 'hb',
            $this->available('first'), $this->available('first'), $this->available('changed'));
        $downloader = $this->app->make(Kd3Downloader::class);

        $first = $downloader->download($date, 'hb');
        $again = $downloader->download($date, 'hb');
        $changed = $downloader->download($date, 'hb');

        $this->assertSame($first['source_file_id'], $again['source_file_id']);
        $this->assertNotSame($first['source_file_id'], $changed['source_file_id']);
        $this->assertDatabaseCount('source_files', 2);
        $this->assertDatabaseHas('kd3_artifact_statuses', [
            'race_date' => '2026-09-05', 'artifact_type' => 'hb', 'status' => 'downloaded',
            'latest_source_file_id' => $changed['source_file_id'], 'attempt_count' => 3,
        ]);
        Storage::disk('kd3-test')->assertExists('kd3/raw/2026/09/2026-09-05/hb/'.hash('sha256', 'changed').'.lzh');
    }

    public function test_not_available_and_failure_do_not_erase_the_latest_success(): void
    {
        $date = CarbonImmutable::parse('2026-09-05', 'Asia/Tokyo');
        $this->gateway->queue('2026-09-05', 'hb',
            $this->available('first'),
            Kd3FetchResult::notAvailable('https://www.keibado.ne.jp/download/hb', 404),
            new Kd3Exception('network'));
        $downloader = $this->app->make(Kd3Downloader::class);
        $success = $downloader->download($date, 'hb');
        $this->assertSame('not_available', $downloader->download($date, 'hb')['status']);
        $this->assertDatabaseHas('kd3_artifact_statuses', [
            'status' => 'not_available', 'latest_source_file_id' => $success['source_file_id'],
        ]);

        try {
            $downloader->download($date, 'hb');
            $this->fail('Expected network failure.');
        } catch (Kd3Exception $exception) {
            $this->assertSame('network', $exception->category);
        }
        $this->assertDatabaseHas('kd3_artifact_statuses', [
            'status' => 'failed', 'latest_source_file_id' => $success['source_file_id'],
            'last_error_category' => 'network', 'attempt_count' => 3,
        ]);
        $this->assertDatabaseCount('source_files', 1);
    }

    public function test_statuses_are_independent_per_artifact(): void
    {
        $date = CarbonImmutable::parse('2026-09-05', 'Asia/Tokyo');
        $this->gateway->queue('2026-09-05', 'hb', $this->available('hb'));
        $this->gateway->queue('2026-09-05', 'ib', Kd3FetchResult::notAvailable('https://www.keibado.ne.jp/download/ib', 404));
        $downloader = $this->app->make(Kd3Downloader::class);

        $downloader->download($date, 'hb');
        $downloader->download($date, 'ib');

        $this->assertDatabaseHas('kd3_artifact_statuses', ['artifact_type' => 'hb', 'status' => 'downloaded']);
        $this->assertDatabaseHas('kd3_artifact_statuses', ['artifact_type' => 'ib', 'status' => 'not_available']);
    }

    public function test_new_file_is_cleaned_up_when_database_recording_fails(): void
    {
        $date = CarbonImmutable::parse('2026-09-05', 'Asia/Tokyo');
        $this->gateway->queue('2026-09-05', 'hb', $this->available('orphan-candidate'));
        DB::statement('DROP TABLE source_files CASCADE');
        $path = 'kd3/raw/2026/09/2026-09-05/hb/'.hash('sha256', 'orphan-candidate').'.lzh';

        try {
            $this->app->make(Kd3Downloader::class)->download($date, 'hb');
            $this->fail('Expected database failure.');
        } catch (Kd3Exception $exception) {
            $this->assertSame('unexpected', $exception->category);
        }

        Storage::disk('kd3-test')->assertMissing($path);
        $this->assertDatabaseHas('kd3_artifact_statuses', [
            'race_date' => '2026-09-05', 'artifact_type' => 'hb', 'status' => 'failed',
            'last_error_category' => 'unexpected',
        ]);
    }

    private function available(string $contents): Kd3FetchResult
    {
        return Kd3FetchResult::available(
            SyntheticKd3::zip('kd3_hb260905.lzh', $contents),
            'hb.zip',
            'https://www.keibado.ne.jp/download/hb?date=20260905',
            200,
        );
    }
}
