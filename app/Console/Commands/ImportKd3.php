<?php

namespace App\Console\Commands;

use App\Kd3\Domain\ImportSummary;
use App\Kd3\Domain\Kd3DomainImporter;
use App\Kd3\Domain\Kd3ImportException;
use App\Kd3\Kd3ParseException;
use App\Kd3\Kd3Parser;
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

    public function handle(Kd3Parser $parser, Kd3DomainImporter $importer): int
    {
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

        return $this->importRange($range[0], $range[1], $parser, $importer);
    }

    private function importRange(
        CarbonImmutable $from,
        CarbonImmutable $to,
        Kd3Parser $parser,
        Kd3DomainImporter $importer,
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

        $this->info("Starting KD3 batch import: sources={$total} from={$from->toDateString()} to={$to->toDateString()}");

        $processed = 0;
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($this->sources($query) as $source) {
            $summary = $this->importSource($source, $parser, $importer, false);
            if ($summary === null) {
                $this->error("Batch import stopped after {$processed} successful source files.");

                return self::FAILURE;
            }

            $processed++;
            $inserted += $summary->inserted;
            $updated += $summary->updated;
            $unchanged += $summary->unchanged;
            $skipped += $summary->skipped;

            if ($processed % 100 === 0 || $processed === $total) {
                $this->line("progress={$processed}/{$total}");
            }
        }

        $this->info("sources={$processed} inserted={$inserted} updated={$updated} unchanged={$unchanged} skipped={$skipped}");

        return self::SUCCESS;
    }

    /** @return iterable<object> */
    private function sources(Builder $query): iterable
    {
        // Chunk the source-file scan so each import can freely issue reads/writes on the same
        // database connection without keeping a PDO cursor open for the whole backfill.
        foreach ($query->lazy(100) as $source) {
            yield $source;
        }
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
