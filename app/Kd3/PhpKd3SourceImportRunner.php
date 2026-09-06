<?php

namespace App\Kd3;

use RuntimeException;

final class PhpKd3SourceImportRunner implements Kd3SourceImportRunner
{
    public function run(int $sourceFileId, string $memoryLimit): Kd3SourceImportProcessResult
    {
        $process = proc_open([
            PHP_BINARY,
            '-d',
            "memory_limit={$memoryLimit}",
            base_path('artisan'),
            'kd3:import',
            "--source-file={$sourceFileId}",
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ], $pipes, base_path());

        if (! is_resource($process)) {
            throw new RuntimeException("Failed to start KD3 import subprocess for source_file={$sourceFileId}.");
        }

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        return new Kd3SourceImportProcessResult(
            exitCode: $exitCode,
            output: is_string($output) ? $output : '',
        );
    }
}
