<?php

namespace App\Console\Commands;

use App\Kd3\Domain\Kd3DomainImporter;
use App\Kd3\Domain\Kd3ImportException;
use App\Kd3\Kd3ParseException;
use App\Kd3\Kd3Parser;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportKd3 extends Command
{
    protected $signature = 'kd3:import {--source-file= : source_files id}';

    protected $description = 'Parse, normalize and transactionally import one immutable KD3 source file';

    public function handle(Kd3Parser $parser, Kd3DomainImporter $importer): int
    {
        $id = filter_var($this->option('source-file'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            $this->error('--source-file must be a positive integer.');

            return self::INVALID;
        }
        $source = DB::table('source_files')->find($id);
        if (! is_object($source) || $source->source_system !== 'kd3') {
            $this->error('KD3 source file was not found.');

            return self::FAILURE;
        }
        $now = CarbonImmutable::now('UTC');
        $runId = DB::table('kd3_import_runs')->insertGetId(['source_file_id' => $id, 'importer_version' => config('kd3.importer_version'),
            'parser_version' => config('kd3.parser_version'), 'spec_version' => config('kd3.spec_version'), 'status' => 'running',
            'started_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        try {
            $package = $parser->parse($source);
            $summary = $importer->import($package, $source);
            DB::table('kd3_import_runs')->where('id', $runId)->update(array_merge($summary->counts(), ['status' => 'succeeded',
                'finished_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC')]));
            $this->info("artifact={$source->artifact_type} inserted={$summary->inserted} updated={$summary->updated} unchanged={$summary->unchanged} skipped={$summary->skipped}");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $category = $exception instanceof Kd3ImportException ? $exception->category : ($exception instanceof Kd3ParseException ? $exception->category : 'unexpected');
            DB::table('kd3_import_runs')->where('id', $runId)->update(['status' => 'failed', 'error_category' => $category,
                'error_entity' => $exception instanceof Kd3ImportException ? $exception->entity : null,
                'error_key' => $exception instanceof Kd3ImportException ? $exception->key : null,
                'finished_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC')]);
            $this->error('KD3 import failed: '.$category.($exception instanceof Kd3ImportException && $exception->entity !== null ? ' entity='.$exception->entity : ''));

            return self::FAILURE;
        }
    }
}
