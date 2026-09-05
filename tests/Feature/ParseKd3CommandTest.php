<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ParseKd3CommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_source_records_a_failed_parse_audit_run(): void
    {
        $id = DB::table('source_files')->insertGetId([
            'source_system' => 'kd3', 'artifact_type' => 'hb', 'race_date' => '2026-09-05',
            'original_filename' => 'synthetic.lzh', 'storage_disk' => 'local', 'storage_path' => 'missing.lzh',
            'sha256' => str_repeat('a', 64), 'size_bytes' => 1, 'source_url' => 'https://example.test/kd3',
            'downloaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('kd3:parse', ['--source-file' => $id])->assertExitCode(1);
        $this->assertDatabaseHas('kd3_parse_runs', [
            'source_file_id' => $id, 'status' => 'failed', 'error_category' => 'integrity',
            'parser_version' => config('kd3.parser_version'), 'spec_version' => config('kd3.spec_version'),
        ]);
    }

    public function test_synthetic_lzh_records_a_successful_parse_audit_run(): void
    {
        if (! is_executable('/usr/bin/lha')) {
            $this->markTestSkipped('lhasa is exercised by the Docker CI image.');
        }
        Storage::fake('parse-audit');
        $directory = sys_get_temp_dir().'/kd3-audit-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        file_put_contents($directory.'/kol_den1.kd3', $this->raceRecord(848, 337, null, 1));
        file_put_contents($directory.'/kol_den2.kd3', $this->raceRecord(1000, null, 25));
        file_put_contents($directory.'/kol_uma.kd3', substr_replace(str_repeat(' ', 5164), '0000001', 0, 7)."\r\n");
        $archive = $directory.'/synthetic.lzh';
        (new Process(['/usr/bin/lha', 'a', $archive, $directory.'/kol_den1.kd3', $directory.'/kol_den2.kd3', $directory.'/kol_uma.kd3']))->mustRun();
        $contents = file_get_contents($archive);
        Storage::disk('parse-audit')->put('synthetic.lzh', $contents);
        config(['kd3.lzh_command' => '/usr/bin/lha']);
        $id = DB::table('source_files')->insertGetId([
            'source_system' => 'kd3', 'artifact_type' => 'hb', 'race_date' => '2026-09-05', 'original_filename' => 'synthetic.lzh', 'storage_disk' => 'parse-audit', 'storage_path' => 'synthetic.lzh', 'sha256' => hash('sha256', $contents), 'size_bytes' => strlen($contents), 'source_url' => 'https://example.test/kd3', 'downloaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->artisan('kd3:parse', ['--source-file' => $id])->assertSuccessful();
        $this->assertDatabaseHas('kd3_parse_runs', ['source_file_id' => $id, 'status' => 'succeeded', 'record_count' => 3]);
    }

    private function raceRecord(int $length, ?int $countOffset, ?int $horseOffset, int $count = 0): string
    {
        $body = substr_replace(str_repeat(' ', $length - 2), '01202601010520260905', 0, 20);
        if ($countOffset !== null) {
            $body = substr_replace($body, str_pad((string) $count, 2, '0', STR_PAD_LEFT), $countOffset, 2);
        }
        if ($horseOffset !== null) {
            $body = substr_replace($body, '0000001', $horseOffset, 7);
        }

        return $body."\r\n";
    }
}
