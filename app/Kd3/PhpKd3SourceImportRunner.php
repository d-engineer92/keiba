<?php

namespace App\Kd3;

use Symfony\Component\Process\Process;

final class PhpKd3SourceImportRunner implements Kd3SourceImportRunner
{
    /** @param list<int> $sourceFileIds */
    public function run(array $sourceFileIds, string $memoryLimit): Kd3SourceImportProcessResult
    {
        $process = new Process([
            PHP_BINARY,
            '-d',
            "memory_limit={$memoryLimit}",
            base_path('artisan'),
            'kd3:import',
            '--worker-sources='.implode(',', $sourceFileIds),
        ], base_path());
        $process->setTimeout(null);
        $process->run();

        return new Kd3SourceImportProcessResult(
            exitCode: $process->getExitCode() ?? 1,
            output: $process->getOutput().$process->getErrorOutput(),
        );
    }
}
