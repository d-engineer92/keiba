<?php

namespace Tests\Feature;

use App\Kd3\Kd3LzhExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

    public function test_sha_mismatch_is_rejected_before_extraction_and_audited(): void
    {
        Storage::fake('parse-integrity');
        Storage::disk('parse-integrity')->put('source.lzh', 'actual');
        $id = $this->insertSource('parse-integrity', 'source.lzh', 'hb', 'different');

        $this->artisan('kd3:parse', ['--source-file' => $id])->assertFailed();
        $this->assertDatabaseHas('kd3_parse_runs', [
            'source_file_id' => $id, 'status' => 'failed', 'error_category' => 'integrity',
        ]);
    }

    public function test_field_failure_position_is_saved_in_failed_audit(): void
    {
        Storage::fake('parse-diagnostic');
        Storage::disk('parse-diagnostic')->put('source.lzh', 'archive');
        $files = [
            'kol_ods.kd3' => $this->raceRecord(1504, null, null),
            'kol_ods2.kd3' => substr_replace(str_repeat(' ', 9041), '01202601010520260230', 0, 20)."\r\n",
        ];
        $this->bindExtractor($files);
        $id = $this->insertSource('parse-diagnostic', 'source.lzh', 'jb', 'archive');

        $this->artisan('kd3:parse', ['--source-file' => $id])->assertFailed();
        $this->assertDatabaseHas('kd3_parse_runs', [
            'source_file_id' => $id, 'status' => 'failed', 'error_category' => 'field_validation',
            'error_file' => 'kol_ods2.kd3', 'error_record_number' => 1, 'error_field' => 'race_date',
        ]);
    }

    public function test_synthetic_lzh_records_a_successful_parse_audit_run(): void
    {
        Storage::fake('parse-audit');
        $files = [
            'kol_den1.kd3' => $this->raceRecord(848, 337, null, 1),
            'kol_den2.kd3' => $this->raceRecord(1000, null, 25),
            'kol_uma.kd3' => substr_replace(str_repeat(' ', 5164), '0000001', 0, 7)."\r\n",
        ];
        $this->bindExtractor($files);
        $contents = 'synthetic';
        Storage::disk('parse-audit')->put('synthetic.lzh', $contents);
        $id = $this->insertSource('parse-audit', 'synthetic.lzh', 'hb', $contents);
        $this->artisan('kd3:parse', ['--source-file' => $id])->assertSuccessful();
        $this->assertDatabaseHas('kd3_parse_runs', ['source_file_id' => $id, 'status' => 'succeeded', 'record_count' => 3]);
        $run = DB::table('kd3_parse_runs')->where('source_file_id', $id)->first();
        $this->assertNotNull($run->finished_at);
        $this->assertSame(config('kd3.parser_version'), $run->parser_version);
        $this->assertSame(config('kd3.spec_version'), $run->spec_version);
    }

    /** @param array<string, string> $files */
    private function bindExtractor(array $files): void
    {
        $this->app->bind(Kd3LzhExtractor::class, fn () => new class($files) implements Kd3LzhExtractor
        {
            public function __construct(private readonly array $files) {}

            public function extract(string $archive, string $directory): array
            {
                foreach ($this->files as $name => $contents) {
                    file_put_contents($directory.'/'.$name, $contents);
                }

                return array_keys($this->files);
            }
        });
    }

    private function insertSource(string $disk, string $path, string $artifact, string $expectedContents): int
    {
        return DB::table('source_files')->insertGetId([
            'source_system' => 'kd3', 'artifact_type' => $artifact, 'race_date' => '2026-09-05',
            'original_filename' => 'synthetic.lzh', 'storage_disk' => $disk, 'storage_path' => $path,
            'sha256' => hash('sha256', $expectedContents), 'size_bytes' => strlen($expectedContents),
            'source_url' => 'https://example.test/kd3', 'downloaded_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
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
