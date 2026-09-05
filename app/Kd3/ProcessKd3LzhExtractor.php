<?php

namespace App\Kd3;

use Symfony\Component\Process\Process;

final class ProcessKd3LzhExtractor implements Kd3LzhExtractor
{
    public function extract(string $archive, string $directory): array
    {
        $command = config('kd3.lzh_command');
        if (! is_string($command) || $command === '') {
            throw new Kd3ParseException('No LZH decoder is configured.', 'lzh');
        }
        if (! is_file($archive) || realpath($archive) === false) {
            throw new Kd3ParseException('Invalid archive path.', 'lzh');
        }
        $process = new Process([$command, 'x', '-y', $archive, $directory]);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new Kd3ParseException('LZH extraction failed.', 'lzh');
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
            if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '../') || str_contains($relative, ':')) {
                throw new Kd3ParseException('Unsafe extracted path.', 'lzh');
            }
            $files[] = $relative;
        }

        return $files;
    }
}
