<?php

namespace App\Console\Commands;

use App\Kd3\Kd3ArtifactCatalog;
use App\Kd3\Kd3Downloader;
use App\Kd3\Kd3DownloadPlanner;
use App\Kd3\Kd3DownloadSharder;
use App\Kd3\Kd3Exception;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Throwable;

final class DownloadKd3 extends Command
{
    private const WORKER_INDEX_ENV = 'KEIBA_KD3_WORKER_INDEX';

    private const WORKER_COUNT_ENV = 'KEIBA_KD3_WORKER_COUNT';

    protected $signature = 'kd3:download
        {--date= : One race date (YYYY-MM-DD)}
        {--from= : First race date (YYYY-MM-DD)}
        {--to= : Last race date (YYYY-MM-DD)}
        {--type=* : Artifact type (hb, ib, jb, kd, lb, mb)}
        {--workers=4 : Number of parallel date workers}';

    protected $description = 'Download immutable KD3 artifacts for dates present in race_calendars';

    public function handle(
        Kd3DownloadPlanner $planner,
        Kd3ArtifactCatalog $catalog,
        Kd3Downloader $downloader,
        Kd3DownloadSharder $sharder,
    ): int {
        try {
            [$from, $to] = $this->range();
            $types = $this->types($catalog);
            $workers = $this->workers();
            $workerContext = $this->workerContext();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        $dates = $planner->dates($from, $to);
        if ($workerContext !== null) {
            [$workerIndex, $workerCount] = $workerContext;
            $dates = $sharder->select($dates, $workerIndex, $workerCount);
        }

        if ($dates === []) {
            $this->info('No eligible race dates were found.');

            return self::SUCCESS;
        }

        if ($workerContext !== null || $workers === 1 || count($dates) === 1) {
            return $this->downloadDates($dates, $types, $downloader);
        }

        return $this->runParallel($from, $to, $types, min($workers, count($dates)));
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     * @param  list<string>  $types
     */
    private function downloadDates(array $dates, array $types, Kd3Downloader $downloader): int
    {
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

    /** @param list<string> $types */
    private function runParallel(CarbonImmutable $from, CarbonImmutable $to, array $types, int $workerCount): int
    {
        $this->info("Starting $workerCount KD3 download workers.");

        /** @var list<Process> $processes */
        $processes = [];
        for ($workerIndex = 0; $workerIndex < $workerCount; $workerIndex++) {
            $command = [
                PHP_BINARY,
                base_path('artisan'),
                'kd3:download',
                '--from='.$from->toDateString(),
                '--to='.$to->toDateString(),
                '--workers=1',
            ];
            foreach ($types as $type) {
                $command[] = '--type='.$type;
            }

            $process = new Process(
                $command,
                base_path(),
                [
                    self::WORKER_INDEX_ENV => (string) $workerIndex,
                    self::WORKER_COUNT_ENV => (string) $workerCount,
                ],
                null,
                null,
            );
            $process->start(function (string $type, string $buffer): void {
                $this->output->write($buffer, false, OutputInterface::OUTPUT_RAW);
            });
            $processes[] = $process;
        }

        do {
            $running = false;
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $running = true;
                }
            }
            if ($running) {
                usleep(10_000);
            }
        } while ($running);

        foreach ($processes as $process) {
            if (! $process->isSuccessful()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
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

    private function workers(): int
    {
        $value = $this->option('workers');
        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            throw new Kd3Exception('input', null, '--workers must be a positive integer.');
        }

        return (int) $value;
    }

    /** @return array{int, int}|null */
    private function workerContext(): ?array
    {
        $index = getenv(self::WORKER_INDEX_ENV);
        $count = getenv(self::WORKER_COUNT_ENV);
        if ($index === false && $count === false) {
            return null;
        }
        if (! is_string($index) || ! is_string($count)
            || preg_match('/^[0-9]+$/', $index) !== 1
            || preg_match('/^[1-9][0-9]*$/', $count) !== 1) {
            throw new Kd3Exception('input', null, 'Invalid internal KD3 worker context.');
        }

        $workerIndex = (int) $index;
        $workerCount = (int) $count;
        if ($workerIndex >= $workerCount) {
            throw new Kd3Exception('input', null, 'Invalid internal KD3 worker context.');
        }

        return [$workerIndex, $workerCount];
    }
}
