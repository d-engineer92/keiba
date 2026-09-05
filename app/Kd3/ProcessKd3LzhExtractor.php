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
        $listing = new Process([$command, 'l', $archive]);
        $listing->run();
        if (! $listing->isSuccessful()) {
            throw new Kd3ParseException('LZH listing failed.', 'lzh');
        }
        $entries = $this->entries($listing->getOutput());
        foreach ($entries as $entry) {
            if (preg_match('/^[A-Za-z0-9_.-]+$/', $entry) !== 1 || str_contains($entry, '..')) {
                throw new Kd3ParseException('Unsafe LZH entry path.', 'lzh', $entry);
            }
        }
        $process = new Process([$command, 'xw='.$directory, $archive]);
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

    /** @return list<string> */
    private function entries(string $output): array
    {
        $entries = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/\s([^\s]+)$/', $line, $matches) === 1 && str_contains($line, '[MS-DOS]')) {
                $entries[] = $matches[1];
            }
        }
        if ($entries === []) {
            throw new Kd3ParseException('LZH archive has no recognized entries.', 'lzh');
        }

        return $entries;
    }
}
