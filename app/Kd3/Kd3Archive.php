<?php

namespace App\Kd3;

use ZipArchive;

final class Kd3Archive
{
    /** @return array{name: string, contents: string}|null */
    public function extract(string $zipBytes, string $entryPattern): ?array
    {
        if (! str_starts_with($zipBytes, "PK\x03\x04") && ! str_starts_with($zipBytes, "PK\x05\x06")) {
            throw new Kd3Exception('invalid_archive', null, 'Response does not have a ZIP signature.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'keiba-kd3-');
        if ($temporary === false || file_put_contents($temporary, $zipBytes) === false) {
            throw new Kd3Exception('storage', null, 'Unable to create the temporary ZIP file.');
        }

        $zip = new ZipArchive;
        $opened = false;
        try {
            if ($zip->open($temporary) !== true) {
                throw new Kd3Exception('invalid_archive', null, 'Unable to open the ZIP response.');
            }
            $opened = true;
            $matches = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if ($name === false || str_ends_with($name, '/') || str_contains($name, '\\') || str_contains($name, '/') || str_contains($name, '..')) {
                    continue;
                }
                if (preg_match($entryPattern, $name) === 1) {
                    $matches[] = $index;
                }
            }
            if ($matches === []) {
                return null;
            }
            if (count($matches) !== 1) {
                throw new Kd3Exception('invalid_archive', null, 'ZIP contains multiple expected LZH entries.');
            }
            $name = $zip->getNameIndex($matches[0]);
            $contents = $zip->getFromIndex($matches[0]);
            if ($name === false || $contents === false || $contents === '') {
                throw new Kd3Exception('invalid_archive', null, 'Expected LZH entry is empty or unreadable.');
            }

            return ['name' => $name, 'contents' => $contents];
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($temporary);
        }
    }
}
