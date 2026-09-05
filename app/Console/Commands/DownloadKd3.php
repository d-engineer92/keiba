<?php

namespace App\Console\Commands;

use App\Kd3\Kd3ArtifactCatalog;
use App\Kd3\Kd3Downloader;
use App\Kd3\Kd3DownloadPlanner;
use App\Kd3\Kd3Exception;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class DownloadKd3 extends Command
{
    protected $signature = 'kd3:download
        {--date= : One race date (YYYY-MM-DD)}
        {--from= : First race date (YYYY-MM-DD)}
        {--to= : Last race date (YYYY-MM-DD)}
        {--type=* : Artifact type (hb, ib, jb, kd, lb, mb)}';

    protected $description = 'Download immutable KD3 artifacts for dates present in race_calendars';

    public function handle(Kd3DownloadPlanner $planner, Kd3ArtifactCatalog $catalog, Kd3Downloader $downloader): int
    {
        try {
            [$from, $to] = $this->range();
            $types = $this->types($catalog);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $dates = $planner->dates($from, $to);
        if ($dates === []) {
            $this->info('No eligible race dates were found.');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($dates as $date) {
            foreach ($types as $type) {
                try {
                    $result = $downloader->download($date, $type);
                    $this->line("{$date->toDateString()} $type {$result['status']}");
                } catch (Kd3Exception $exception) {
                    $failed++;
                    $this->error("{$date->toDateString()} $type failed ({$exception->category})");
                }
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function range(): array
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');
        if ($date !== null && ($from !== null || $to !== null)) {
            throw new Kd3Exception('input', null, '--date cannot be combined with --from or --to.');
        }
        $today = CarbonImmutable::today('Asia/Tokyo');
        $first = $this->parseDate(is_string($date) ? $date : (is_string($from) ? $from : $today->toDateString()));
        $last = $this->parseDate(is_string($date) ? $date : (is_string($to) ? $to : $first->toDateString()));
        if ($first->greaterThan($last)) {
            throw new Kd3Exception('input', null, '--from must not be later than --to.');
        }

        return [$first, $last];
    }

    private function parseDate(string $value): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Tokyo');
        if ($date === null || $date->format('Y-m-d') !== $value) {
            throw new Kd3Exception('input', null, "Invalid date: $value");
        }

        return $date;
    }

    /** @return list<string> */
    private function types(Kd3ArtifactCatalog $catalog): array
    {
        $selected = $this->option('type');
        $selected = array_values(array_filter($selected, is_string(...)));
        $types = $selected !== [] ? array_values(array_unique($selected)) : $catalog->types();
        $unknown = array_diff($types, $catalog->types());
        if ($unknown !== []) {
            throw new Kd3Exception('input', null, 'Unknown artifact type: '.implode(', ', $unknown));
        }

        return $types;
    }
}
