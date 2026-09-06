<?php

namespace Tests\Feature;

use App\Kd3\Kd3LzhExtractor;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ImportKd3CommandTest extends TestCase
{
    use DatabaseTransactions;

    public function test_failed_parse_leaves_a_safe_failed_import_audit(): void
    {
        $id = DB::table('source_files')->insertGetId(['source_system' => 'kd3', 'artifact_type' => 'hb', 'race_date' => '2026-09-05',
            'original_filename' => 'synthetic-private-name.lzh', 'storage_disk' => 'local', 'storage_path' => 'private/missing-file.lzh',
            'sha256' => str_repeat('a', 64), 'size_bytes' => 1, 'source_url' => 'https://example.test/private', 'downloaded_at' => now()]);

        $this->artisan('kd3:import', ['--source-file' => $id])
            ->expectsOutputToContain('KD3 import failed: integrity')
            ->doesntExpectOutputToContain('private/missing-file.lzh')
            ->assertFailed();

        $this->assertDatabaseHas('kd3_import_runs', ['source_file_id' => $id, 'status' => 'failed', 'error_category' => 'integrity']);
        $this->assertDatabaseCount('race_entries', 0);
    }

    public function test_synthetic_hb_runs_parser_to_domain_and_reruns_idempotently(): void
    {
        Storage::fake('import-synthetic');
        Storage::disk('import-synthetic')->put('source.lzh', 'synthetic');
        $den1 = substr_replace(str_repeat(' ', 846), '01202601010520260905', 0, 20);
        foreach ([[28, 14, 'Synthetic Race'], [105, 1, '1'], [106, 1, '0'], [109, 4, '1600'], [332, 5, '12:30'], [337, 2, '01']] as [$offset, $length, $value]) {
            $den1 = substr_replace($den1, str_pad($value, $length), $offset, $length);
        }
        $den2 = substr_replace(str_repeat(' ', 998), '01202601010520260905', 0, 20);
        foreach ([[22, 1, '1'], [23, 2, '01'], [25, 7, '0000001'], [32, 15, 'SYNTHETIC HORSE'], [148, 3, '550'],
            [151, 5, '00001'], [156, 16, 'SYNTHETIC RIDER'], [206, 5, '00001'], [211, 17, 'SYNTHETIC TRAINER'], [735, 4, '2020']] as [$offset, $length, $value]) {
            $den2 = substr_replace($den2, str_pad($value, $length), $offset, $length);
        }
        foreach (range(0, 4) as $index) {
            $den2 = substr_replace($den2, ' 80.0', 742 + ($index * 5), 5);
        }
        $uma = substr_replace(str_repeat(' ', 5164), '0000001', 0, 7);
        foreach ([[7, 15, 'SYNTHETIC HORSE'], [77, 4, '2020'], [81, 4, '0102'], [85, 2, '03'], [87, 2, '01'], [94, 1, '0'],
            [488, 5, '00001'], [493, 17, 'SYNTHETIC TRAINER']] as [$offset, $length, $value]) {
            $uma = substr_replace($uma, str_pad($value, $length), $offset, $length);
        }
        $this->bindExtractor(['kol_den1.kd3' => $den1."\r\n", 'kol_den2.kd3' => $den2."\r\n", 'kol_uma.kd3' => $uma."\r\n"]);
        $id = DB::table('source_files')->insertGetId(['source_system' => 'kd3', 'artifact_type' => 'hb', 'race_date' => '2026-09-05',
            'original_filename' => 'synthetic.lzh', 'storage_disk' => 'import-synthetic', 'storage_path' => 'source.lzh',
            'sha256' => hash('sha256', 'synthetic'), 'size_bytes' => 9, 'source_url' => 'https://example.test/synthetic', 'downloaded_at' => now()]);

        $this->artisan('kd3:import', ['--source-file' => $id])->expectsOutputToContain('artifact=hb')->assertSuccessful();
        $this->artisan('kd3:import', ['--source-file' => $id])->expectsOutputToContain('unchanged=')->assertSuccessful();

        $this->assertDatabaseCount('race_entries', 1);
        $this->assertDatabaseCount('race_entry_runners', 1);
        $this->assertDatabaseCount('runner_speed_indices', 5);
        $this->assertSame(2, DB::table('kd3_import_runs')->where(['source_file_id' => $id, 'status' => 'succeeded'])->count());
    }

    public function test_mapping_failure_rolls_back_domain_but_keeps_failed_audit(): void
    {
        Storage::fake('import-rollback');
        Storage::disk('import-rollback')->put('source.lzh', 'rollback');
        $den1 = substr_replace(str_repeat(' ', 846), '99202601010520260905', 0, 20);
        $den1 = substr_replace($den1, '01', 337, 2);
        $den2 = substr_replace(str_repeat(' ', 998), '99202601010520260905', 0, 20);
        $den2 = substr_replace($den2, '0000009', 25, 7);
        $uma = substr_replace(str_repeat(' ', 5164), '0000009', 0, 7);
        $this->bindExtractor(['kol_den1.kd3' => $den1."\r\n", 'kol_den2.kd3' => $den2."\r\n", 'kol_uma.kd3' => $uma."\r\n"]);
        $id = DB::table('source_files')->insertGetId(['source_system' => 'kd3', 'artifact_type' => 'hb', 'race_date' => '2026-09-05',
            'original_filename' => 'synthetic.lzh', 'storage_disk' => 'import-rollback', 'storage_path' => 'source.lzh',
            'sha256' => hash('sha256', 'rollback'), 'size_bytes' => 8, 'source_url' => 'https://example.test/rollback', 'downloaded_at' => now()]);

        $this->artisan('kd3:import', ['--source-file' => $id])->expectsOutputToContain('KD3 import failed: mapping entity=race')->assertFailed();

        $this->assertDatabaseCount('horses', 0);
        $this->assertDatabaseHas('kd3_import_runs', ['source_file_id' => $id, 'status' => 'failed', 'error_category' => 'mapping', 'error_entity' => 'race']);
    }

    public function test_batch_import_requires_complete_range_and_is_exclusive_with_source_file(): void
    {
        $this->artisan('kd3:import')->assertExitCode(2);
        $this->artisan('kd3:import', ['--from' => '2026-09-01'])->assertExitCode(2);
        $this->artisan('kd3:import', ['--source-file' => 1, '--from' => '2026-09-01', '--to' => '2026-09-05'])->assertExitCode(2);
        $this->artisan('kd3:import', ['--from' => '2026-09-06', '--to' => '2026-09-05'])->assertExitCode(2);
    }

    public function test_batch_import_orders_oldest_source_first_and_stops_on_failure(): void
    {
        $later = DB::table('source_files')->insertGetId([
            'source_system' => 'kd3', 'artifact_type' => 'hb', 'race_date' => '2026-09-06',
            'original_filename' => 'later.lzh', 'storage_disk' => 'local', 'storage_path' => 'private/later-missing.lzh',
            'sha256' => str_repeat('b', 64), 'size_bytes' => 1, 'source_url' => 'https://example.test/later', 'downloaded_at' => now(),
        ]);
        $earlier = DB::table('source_files')->insertGetId([
            'source_system' => 'kd3', 'artifact_type' => 'hb', 'race_date' => '2026-09-05',
            'original_filename' => 'earlier.lzh', 'storage_disk' => 'local', 'storage_path' => 'private/earlier-missing.lzh',
            'sha256' => str_repeat('c', 64), 'size_bytes' => 1, 'source_url' => 'https://example.test/earlier', 'downloaded_at' => now(),
        ]);

        $this->artisan('kd3:import', ['--from' => '2026-09-05', '--to' => '2026-09-06'])
            ->expectsOutputToContain("source_file={$earlier}")
            ->expectsOutputToContain('Batch import stopped after 0 successful source files.')
            ->assertFailed();

        $this->assertDatabaseHas('kd3_import_runs', ['source_file_id' => $earlier, 'status' => 'failed']);
        $this->assertDatabaseMissing('kd3_import_runs', ['source_file_id' => $later]);
    }

    /** @param array<string, string> $files */
    private function bindExtractor(array $files): void
    {
        $this->app->bind(Kd3LzhExtractor::class, fn () => new class($files) implements Kd3LzhExtractor
        {
            /** @param array<string, string> $files */
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
}
