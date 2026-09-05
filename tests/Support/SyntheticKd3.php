<?php

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

final class SyntheticKd3
{
    public static function zip(string $entry, string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'kd3-test-');
        if ($path === false) {
            throw new RuntimeException('Unable to create test ZIP.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to open test ZIP.');
        }
        $zip->addFromString($entry, $contents);
        $zip->close();
        $bytes = file_get_contents($path);
        unlink($path);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read test ZIP.');
        }

        return $bytes;
    }
}
