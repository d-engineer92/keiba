<?php

namespace App\Console\Commands;

use App\Kd3\Kd3ParseException;
use App\Kd3\Kd3Parser;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ParseKd3 extends Command
{
    protected $signature = 'kd3:parse {--source-file= : source_files id}';

    protected $description = 'Parse and validate one immutable KD3 source file';

    public function handle(Kd3Parser $parser): int
    {
        $id = filter_var($this->option('source-file'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            $this->error('--source-file must be a positive integer.');

            return self::INVALID;
        }
        $source = DB::table('source_files')->find($id);
        if ($source === null) {
            $this->error('Source file was not found.');

            return self::FAILURE;
        }
        $started = CarbonImmutable::now('UTC');
        $run = DB::table('kd3_parse_runs')->insertGetId(['source_file_id' => $id, 'parser_version' => config('kd3.parser_version'), 'spec_version' => config('kd3.spec_version'), 'status' => 'running', 'started_at' => $started, 'created_at' => $started, 'updated_at' => $started]);
        try {
            $result = $parser->parse($source);
            DB::table('kd3_parse_runs')->where('id', $run)->update(['status' => 'succeeded', 'record_count' => $result['record_count'], 'finished_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC')]);
            $this->info("source_file={$id} files=".count($result['files'])." records={$result['record_count']}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $category = $e instanceof Kd3ParseException ? $e->category : 'unexpected';
            DB::table('kd3_parse_runs')->where('id', $run)->update([
                'status' => 'failed', 'error_category' => $category,
                'error_file' => $e instanceof Kd3ParseException ? $e->fileName : null,
                'error_record_number' => $e instanceof Kd3ParseException ? $e->recordNumber : null,
                'error_field' => $e instanceof Kd3ParseException ? $e->field : null,
                'finished_at' => CarbonImmutable::now('UTC'), 'updated_at' => CarbonImmutable::now('UTC'),
            ]);
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
