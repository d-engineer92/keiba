<?php

namespace App\Console\Commands;

use App\Kd3\Domain\ImportSummary;
use App\Kd3\Domain\Kd3DomainImporter;
use App\Kd3\Domain\Kd3ImportException;
use App\Kd3\Kd3ParseException;
use App\Kd3\Kd3Parser;
use App\Kd3\Kd3SourceImportProcessResult;
use App\Kd3\Kd3SourceImportRunner;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportKd3 extends Command
{
    protected $signature = 'kd3:import
        {--source-file= : Import one source_files id}
        {--from= : First race date for batch import (YYYY-MM-DD)}
        {--to= : Last race date for batch import (YYYY-MM-DD)}';

    protected $description = 'Parse, normalize and transactionally import KD3 source files';

    public function handle(
        Kd3Parser $parser,
        Kd3DomainImporter $importer,
        Kd3SourceImportRunner $sourceRunner,
    ): int {
        try {
            $sourceFileId = $this->sourceFileId();
            $range = $this->range();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($sourceFileId !== null) {
            $source = DB::table('source_files')->find($sourceFileId);
            if (! is_object($source) || $source->source_system !== 'kd3') {
                $this->error('KD3 source file was not found.');

                return self::FAILURE;
            }

            return $this->importSource($source, $parser, $importer, true) === null
                ? self::FAILURE
                : self::SUCCESS;
        }

        if ($range === null) {
            $this->error('Specify either --source-file or both --from and --to.');

            return self::INVALID;
        }

        return $this->importRange($range[0], $range[1], $sourceRunner);
    }

    private function importRange(
        CarbonImmutable $from,
        CarbonImmutable $to,
        Kd3SourceImportRunner $sourceRunner,
    ): int {
        $query = DB::table('source_files')
            ->where('source_system', 'kd3')
            ->whereBetween('race_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('race_date')
            ->orderBy('downloaded_at')
            ->orderBy('id');

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No KD3 source files were found in the requested range.');

            return self::SUCCESS;
        }

        $memoryLimit = $this->memoryLimit();
        $this->info("Starting KD3 batch import: sources={$total} from={$from->toDateString()} to={$to->toDateString()} memory_limit={$memoryLimit}");

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($this->sources($query) as $source) {
            $processed++;
            $id = (int) $source->id;
            $previousRunId = (int) (DB::table('kd3_import_runs')->where('source_file_id', $id)->max('id') ?? 0);

            try {
                $result = $sourceRunner->run($id, $memoryLimit);
            } catch (Throwable) {
                $failed++;
                $this->recordProcessFailure($source, $previousRunId, 'process_launch', 'runner');
                $this->error("KD3 import process failed: process_launch source_file={$id} race_date={$source->race_date} artifact={$source->artifact_type}");
                $this->reportProgress($processed, $total);

                continue;
            }

            $run = $this->latestRun($id, $previousRunId);
            if (! $result->successful()) {
                $failed++;
                $category = $this->processFailureCategory($result);
                $this->recordProcessFailure($source, $previousRunId, $category, "exit_code:{$result->exitCode}");
                $output = trim($result->output);
                if ($output !== '') {
                    $this->error($output);
                } else {
                    $this->error("KD3 import process failed: {$category} source_file={$id} race_date={$source->race_date} artifact={$source->artifact_type} exit={$result->exitCode}");
                }
                $this->reportProgress($processed, $total);

                continue;
            }

            if (! is_object($run) || $run->status !== 'succeeded') {
                $failed++;
                $this->recordProcessFailure($source, $previousRunId, 'missing_audit', 'exit_code:0');
                $this->error("KD3 import process completed without a succeeded audit: source_file={$id} race_date={$source->race_date} artifact={$source->artifact_type}");
                $this->reportProgress($processed, $total);

                continue;
            }

            $succeeded++;
            $inserted += (int) $run->inserted_count;
            $updated += (int) $run->updated_count;
            $unchanged += (int) $run->unchanged_count;
            $skipped += (int) $run->skipped_count;
            $this->reportProgress($processed, $total);
        }

        $this->info("sources={$processed} succeeded={$succeeded} failed={$failed} inserted={$inserted} updated={$updated} unchanged={$unchanged} skipped={$skipped}");

        if ($failed > 0) {
            $this->warn('KD3 batch import reached the end with failures. See kd3_import_runs for every failed source file.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return iterable<object> */
    private function sources(Builder $query): iterable
    {
        // Chunk the source-file scan without keeping a PDO cursor open while child processes
        // independently read and write through their own database connections.
        foreach ($query->lazy(100) as $source) {
            yield $source;
        }
    }

    private function latestRun(int $sourceFileId, int $previousRunId): ?object
    {
        $run = DB::table('kd3_import_runs')
            ->where('source_file_id', $sourceFileId)
            ->where('id', '>', $previousRunId)
            ->orderByDesc('id')
            ->first();

        return is_object($run) ? $run : null;
    }

    private function recordProcessFailure(
        object $source,
        int $previousRunId,
        string $category,
        string $key,
    ): object {
        $id = (int) $source->id;
        $run = $this->latestRun($id, $previousRunId);
        if (is_object($run) && $run->status === 'failed') {
            return $run;
        }

        $now = CarbonImmutable::now('UTC');
        if (is_object($run) && $run->status === 'running') {
            DB::table('kd3_import_runs')->where('id', $run->id)->update([
                'status' => 'failed',
                'error_category' => $category,
                'error_entity' => 'source_file',
                'error_key' => $key,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

            return (object) array_merge((array) $run, [
                'status' => 'failed',
                'error_category' => $category,
                'error_entity' => 'source_file',
                'error_key' => $key,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $runId = DB::table('kd3_import_runs')->insertGetId([
            'source_file_id' => $id,
            'importer_version' => config('kd3.importer_version'),
            'parser_version' => config('kd3.parser_version'),
            'spec_version' => config('kd3.spec_version'),
            'status' => 'failed',
            'inserted_count' => 0,
            'updated_count' => 0,
            'unchanged_count' => 0,
            'skipped_count' => 0,
            'started_at' => $now,
            'finished_at' => $now,
            'error_category' => $category,
            'error_entity' => 'source_file',
            'error_key' => $key,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (object) [
            'id' => $runId,
            'source_file_id' => $id,
            'status' => 'failed',
            'error_category' => $category,
            'error_entity' => 'source_file',
            'error_key' => $key,
        ];
    }

    private function processFailureCategory(Kd3SourceImportProcessResult $result): string
    {
        $output = strtolower($result->output);
        if (str_contains($output, 'allowed memory size') || str_contains($output, 'out of memory')) {
            return 'memory_limit';
        }

        return 'process_exit';
    }

    private function reportProgress(int $processed, int $total): void
    {
        if ($processed % 100 === 0 || $processed === $total) {
            $this->line("progress={$processed}/{$total}");
        }
    }

    private function memoryLimit(): string
    {
        $limit = (string) ini_get('memory_limit');

        return $limit !== '' ? $limit : '-1';
    }

    private function importSource(
        object $source,
        Kd3Parser $parser,
        Kd3DomainImporter $importer,
        bool $reportSuccess,
    ): ?ImportSummary {
        $id = (int) $source->id;
        $now = CarbonImmutable::now('UTC');
        $runId = DB::table('kd3_import_runs')->insertGetId([
            'source_file_id' => $id,
            'importer_version' => config('kd3.importer_version'),
            'parser_version' => config('kd3.parser_version'),
            'spec_version' => config('kd3.spec_version'),
            'status' => 'running',
            'started_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            $package = $parser->parse($source);
            $summary = $importer->import($package, $source);
            DB::table('kd3_import_runs')->where('id', $runId)->update(array_merge($summary->counts(), [
                'status' => 'succeeded',
                'finished_at' => CarbonImmutable::now('UTC'),
                'updated_at' => CarbonImmutable::now('UTC'),
            ]));

            if ($reportSuccess) {
                $this->info("artifact={$source->artifact_type} inserted={$summary->inserted} updated={$summary->updated} unchanged={$summary->unchanged} skipped={$summary->skipped}");
            }

            return $summary;
        } catch (Throwable $exception) {
            $category = $exception instanceof Kd3ImportException
                ? $exception->category
                : ($exception instanceof Kd3ParseException ? $exception->category : 'unexpected');
            DB::table('kd3_import_runs')->where('id', $runId)->update([
                'status' => 'failed',
                'error_category' => $category,
                'error_entity' => $exception instanceof Kd3ImportException ? $exception->entity : null,
                'error_key' => $exception instanceof Kd3ImportException ? $exception->key : null,
                'finished_at' => CarbonImmutable::now('UTC'),
                'updated_at' => CarbonImmutable::now('UTC'),
            ]);
            $entity = $exception instanceof Kd3ImportException && $exception->entity !== null
                ? ' entity='.$exception->entity
                : '';
            $raceDate = is_string($source->race_date ?? null) ? ' race_date='.$source->race_date : '';
            $artifact = is_string($source->artifact_type ?? null) ? ' artifact='.$source->artifact_type : '';
            $this->error("KD3 import failed: {$category}{$entity} source_file={$id}{$raceDate}{$artifact}");

            return null;
        }
    }

    private function sourceFileId(): ?int
    {
        $value = $this->option('source-file');
        if ($value === null) {
            return null;
        }
        if ($this->option('from') !== null || $this->option('to') !== null) {
            throw new \InvalidArgumentException('--source-file cannot be combined with --from or --to.');
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new \InvalidArgumentException('--source-file must be a positive integer.');
        }

        return (int) $id;
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    private function range(): ?array
    {
        if ($this->option('source-file') !== null) {
            return null;
        }

        $from = $this->option('from');
        $to = $this->option('to');
        if ($from === null && $to === null) {
            return null;
        }
        if (! is_string($from) || ! is_string($to)) {
            throw new \InvalidArgumentException('Batch import requires both --from and --to.');
        }

        $first = $this->parseDate($from);
        $last = $this->parseDate($to);
        if ($first->greaterThan($last)) {
            throw new \InvalidArgumentException('--from must not be later than --to.');
        }

        return [$first, $last];
    }

    private function parseDate(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Tokyo');
        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Invalid date: {$value}");
        }

        return $date;
    }
}
