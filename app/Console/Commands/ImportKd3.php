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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportKd3 extends Command
{
    private const WORKER_CHUNK_SIZE = 50;

    protected $signature = 'kd3:import
        {--source-file= : Import one source_files id}
        {--from= : First race date for batch import (YYYY-MM-DD)}
        {--to= : Last race date for batch import (YYYY-MM-DD)}
        {--worker-sources= : Internal comma-separated source_files ids for a batch worker}';

    protected $description = 'Parse, normalize and transactionally import KD3 source files';

    public function handle(
        Kd3Parser $parser,
        Kd3DomainImporter $importer,
        Kd3SourceImportRunner $sourceRunner,
    ): int {
        try {
            $sourceFileId = $this->sourceFileId();
            $workerSourceIds = $this->workerSourceIds();
            $range = $this->range();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($workerSourceIds !== null) {
            return $this->importWorker($workerSourceIds, $parser, $importer);
        }

        if ($sourceFileId !== null) {
            $source = DB::table('source_files')->find($sourceFileId);
            if (! is_object($source) || $source->source_system !== 'kd3') {
                $this->error('KD3 source file was not found.');

                return self::FAILURE;
            }

            return $this->importSource($source, $parser, $importer, true, true) === null
                ? self::FAILURE
                : self::SUCCESS;
        }

        if ($range === null) {
            $this->error('Specify either --source-file or both --from and --to.');

            return self::INVALID;
        }

        return $this->importRange($range[0], $range[1], $sourceRunner, $importer);
    }

    private function importRange(
        CarbonImmutable $from,
        CarbonImmutable $to,
        Kd3SourceImportRunner $sourceRunner,
        Kd3DomainImporter $importer,
    ): int {
        /** @var list<object> $sources */
        $sources = DB::table('source_files')
            ->where('source_system', 'kd3')
            ->whereBetween('race_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('race_date')
            ->orderBy('downloaded_at')
            ->orderBy('id')
            ->get(['id', 'race_date', 'artifact_type'])
            ->all();

        $total = count($sources);
        if ($total === 0) {
            $this->info('No KD3 source files were found in the requested range.');

            return self::SUCCESS;
        }

        $memoryLimit = $this->memoryLimit();
        $startedAt = microtime(true);
        $this->info("Starting KD3 batch import: sources={$total} from={$from->toDateString()} to={$to->toDateString()} memory_limit={$memoryLimit} chunk_size=".self::WORKER_CHUNK_SIZE);

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $counted = [];

        /** @var array<int, object> $sourceMap */
        $sourceMap = [];
        foreach ($sources as $source) {
            $sourceMap[(int) $source->id] = $source;
        }

        foreach (array_chunk($sources, self::WORKER_CHUNK_SIZE) as $chunk) {
            /** @var list<object> $pending */
            $pending = array_values($chunk);

            while ($pending !== []) {
                $ids = array_map(static fn (object $source): int => (int) $source->id, $pending);
                $previousRunId = (int) (DB::table('kd3_import_runs')->max('id') ?? 0);

                try {
                    $result = $sourceRunner->run($ids, $memoryLimit);
                } catch (Throwable) {
                    $this->error('KD3 batch worker could not be started. Batch import aborted because this is not source-specific.');

                    return self::FAILURE;
                }

                $output = trim($result->output);
                if ($output !== '') {
                    foreach (preg_split('/\R/', $output) ?: [] as $line) {
                        if ($line !== '') {
                            $this->error($line);
                        }
                    }
                }

                $runs = $this->runsAfter($previousRunId, $ids);
                if (! $result->successful()) {
                    $running = $runs->first(static fn (object $run): bool => $run->status === 'running');
                    if (is_object($running)) {
                        $source = $sourceMap[(int) $running->source_file_id];
                        $this->recordProcessFailure(
                            $source,
                            $previousRunId,
                            $this->processFailureCategory($result),
                            "exit_code:{$result->exitCode}",
                        );
                        $runs = $this->runsAfter($previousRunId, $ids);
                    } elseif ($runs->isEmpty()) {
                        $this->error("KD3 batch worker exited before starting a source file (exit={$result->exitCode}). Batch import aborted.");

                        return self::FAILURE;
                    } else {
                        $represented = $runs->pluck('source_file_id')->map('intval')->all();
                        $unstarted = array_values(array_filter($ids, static fn (int $id): bool => ! in_array($id, $represented, true)));
                        if ($unstarted !== []) {
                            $source = $sourceMap[$unstarted[0]];
                            $this->recordProcessFailure(
                                $source,
                                $previousRunId,
                                $this->processFailureCategory($result),
                                "exit_code:{$result->exitCode}",
                            );
                            $runs = $this->runsAfter($previousRunId, $ids);
                        }
                    }
                }

                if ($result->successful()) {
                    $represented = $runs->pluck('source_file_id')->map('intval')->all();
                    foreach ($ids as $id) {
                        if (! in_array($id, $represented, true)) {
                            $this->recordProcessFailure($sourceMap[$id], $previousRunId, 'missing_audit', 'exit_code:0');
                        }
                    }
                    $runs = $this->runsAfter($previousRunId, $ids);
                }

                foreach ($runs as $run) {
                    $sourceId = (int) $run->source_file_id;
                    if (isset($counted[$sourceId]) || ! in_array($run->status, ['succeeded', 'failed'], true)) {
                        continue;
                    }
                    $counted[$sourceId] = true;
                    $processed++;
                    if ($run->status === 'succeeded') {
                        $succeeded++;
                        $inserted += (int) ($run->inserted_count ?? 0);
                        $updated += (int) ($run->updated_count ?? 0);
                        $unchanged += (int) ($run->unchanged_count ?? 0);
                        $skipped += (int) ($run->skipped_count ?? 0);
                    } else {
                        $failed++;
                    }
                }

                $pending = array_values(array_filter(
                    $pending,
                    static fn (object $source): bool => !isset($counted[(int) $source->id]),
                ));

                $lastSource = $processed > 0
                    ? $sourceMap[(int) array_key_last($counted)] ?? null
                    : null;
                $this->reportProgress(
                    $processed,
                    $total,
                    $succeeded,
                    $failed,
                    $startedAt,
                    is_object($lastSource) ? (string) $lastSource->race_date : null,
                );
            }
        }

        $this->info("sources={$processed} succeeded={$succeeded} failed={$failed} inserted={$inserted} updated={$updated} unchanged={$unchanged} skipped={$skipped}");

        if ($failed > 0) {
            $this->warn('KD3 batch import reached the end with failures. Reconciliation was skipped so the failed sources can be fixed first.');

            return self::FAILURE;
        }

        $this->info('Starting KD3 reconciliation after all source files completed successfully.');
        $reconcileStartedAt = microtime(true);
        $importer->reconcile();
        $this->info('reconciliation=completed elapsed='.$this->formatDuration(microtime(true) - $reconcileStartedAt));

        return self::SUCCESS;
    }

    /** @param list<int> $sourceFileIds */
    private function importWorker(array $sourceFileIds, Kd3Parser $parser, Kd3DomainImporter $importer): int
    {
        foreach ($sourceFileIds as $sourceFileId) {
            $source = DB::table('source_files')->find($sourceFileId);
            if (! is_object($source) || $source->source_system !== 'kd3') {
                $this->error("KD3 worker source file was not found: source_file={$sourceFileId}");

                return self::FAILURE;
            }

            // Ordinary parser/importer failures are audited by importSource and the worker keeps
            // going. A PHP fatal/OOM kills only this bounded worker process; the parent marks the
            // current source failed and starts another worker for the remaining sources.
            $this->importSource($source, $parser, $importer, false, false);
        }

        return self::SUCCESS;
    }

    /** @param list<int> $sourceFileIds */
    private function runsAfter(int $previousRunId, array $sourceFileIds): Collection
    {
        return DB::table('kd3_import_runs')
            ->where('id', '>', $previousRunId)
            ->whereIn('source_file_id', $sourceFileIds)
            ->orderBy('id')
            ->get();
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

    private function reportProgress(
        int $processed,
        int $total,
        int $succeeded,
        int $failed,
        float $startedAt,
        ?string $raceDate,
    ): void {
        $elapsed = max(microtime(true) - $startedAt, 0.001);
        $rate = $processed / $elapsed;
        $eta = $rate > 0 ? ($total - $processed) / $rate : 0.0;
        $percent = $total > 0 ? ($processed / $total) * 100 : 100.0;
        $date = $raceDate === null ? '-' : $raceDate;

        $this->line(sprintf(
            'progress=%d/%d (%.1f%%) succeeded=%d failed=%d elapsed=%s rate=%.2f files/s eta=%s race_date=%s',
            $processed,
            $total,
            $percent,
            $succeeded,
            $failed,
            $this->formatDuration($elapsed),
            $rate,
            $this->formatDuration($eta),
            $date,
        ));
    }

    private function formatDuration(float $seconds): string
    {
        $value = max(0, (int) round($seconds));
        $hours = intdiv($value, 3600);
        $minutes = intdiv($value % 3600, 60);
        $remaining = $value % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining);
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
        bool $reconcile,
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
            $summary = $importer->import($package, $source, $reconcile);
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
        if ($this->option('from') !== null || $this->option('to') !== null || $this->option('worker-sources') !== null) {
            throw new \InvalidArgumentException('--source-file cannot be combined with --from, --to or --worker-sources.');
        }

        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            throw new \InvalidArgumentException('--source-file must be a positive integer.');
        }

        return (int) $id;
    }

    /** @return list<int>|null */
    private function workerSourceIds(): ?array
    {
        $value = $this->option('worker-sources');
        if ($value === null) {
            return null;
        }
        if ($this->option('source-file') !== null || $this->option('from') !== null || $this->option('to') !== null) {
            throw new \InvalidArgumentException('--worker-sources cannot be combined with --source-file, --from or --to.');
        }
        if (! is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('--worker-sources must contain at least one source_files id.');
        }

        $ids = [];
        foreach (explode(',', $value) as $part) {
            $id = filter_var(trim($part), FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                throw new \InvalidArgumentException('--worker-sources must contain only positive integers.');
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /** @return array{CarbonImmutable, CarbonImmutable}|null */
    private function range(): ?array
    {
        if ($this->option('source-file') !== null || $this->option('worker-sources') !== null) {
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
