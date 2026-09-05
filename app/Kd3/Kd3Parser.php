<?php

namespace App\Kd3;

use Illuminate\Support\Facades\Storage;

final class Kd3Parser
{
    public function __construct(
        private readonly Kd3LzhExtractor $extractor,
        private readonly Kd3FixedWidthReader $reader,
        private readonly Kd3LayoutRegistry $layouts,
        private readonly Kd3FieldDecoder $decoder,
    ) {}

    /** @return array{artifact_type:string, files:list<string>, record_count:int} */
    public function parse(object $sourceFile): array
    {
        if ($sourceFile->source_system !== 'kd3') {
            throw new Kd3ParseException('Source file is not a KD3 artifact.', 'integrity');
        }
        $disk = Storage::disk($sourceFile->storage_disk);
        if (! $disk->exists($sourceFile->storage_path)) {
            throw new Kd3ParseException('Source file is missing.', 'integrity');
        }
        $stream = $disk->readStream($sourceFile->storage_path);
        if (! is_resource($stream)) {
            throw new Kd3ParseException('Source file cannot be opened.', 'integrity');
        }
        $hash = hash_init('sha256');
        $size = 0;
        while (! feof($stream)) {
            $chunk = fread($stream, 1048576);
            if ($chunk === false) {
                break;
            } $size += strlen($chunk);
            hash_update($hash, $chunk);
        }
        fclose($stream);
        if ($size !== (int) $sourceFile->size_bytes || hash_final($hash) !== $sourceFile->sha256) {
            throw new Kd3ParseException('Source file integrity check failed.', 'integrity');
        }
        $dir = sys_get_temp_dir().'/kd3-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        mkdir($dir.'/out', 0700);
        $local = $dir.'/source.lzh';
        file_put_contents($local, $disk->get($sourceFile->storage_path));
        try {
            $files = $this->extractor->extract($local, $dir.'/out');
            $this->assertExpectedFiles($sourceFile->artifact_type, $files);
            $count = 0;
            foreach ($files as $file) {
                $name = basename($file);
                $layout = $this->layouts->get($name);
                foreach ($this->reader->records($dir.'/out/'.$file, $layout['record_length'], $name) as $number => $record) {
                    foreach ($layout['fields'] as $field => $definition) {
                        $value = $this->decoder->typed($record, $field, $definition, $name, $number);
                        if ($field === 'race_date' && $value !== str_replace('-', '', (string) $sourceFile->race_date)) {
                            throw new Kd3ParseException('Record race date differs from artifact date.', 'field_validation', $name, $number, $definition['offset'], $field);
                        }
                    }
                    $count++;
                }
            }

            return ['artifact_type' => $sourceFile->artifact_type, 'files' => $files, 'record_count' => $count];
        } finally {
            $this->remove($dir);
        }
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        } foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->remove($path.'/'.$item);
            }
        } @rmdir($path);
    }

    /** @param list<string> $files */
    private function assertExpectedFiles(string $artifactType, array $files): void
    {
        $expected = [
            'hb' => ['kol_den1.kd3', 'kol_den2.kd3', 'kol_uma.kd3'],
            'ib' => ['kol_sei1.kd3', 'kol_sei2.kd3', 'kol_uma.kd3'],
            'jb' => ['kol_ods.kd3', 'kol_ods2.kd3'],
            'kd' => ['kol_kod.kd3', 'kol_kod2.kd3', 'kol_kod3.kd3'],
            'lb' => ['kol_com1.kd3'], 'mb' => ['kol_com1.kd3'],
        ][$artifactType] ?? [];
        $names = array_map('basename', $files);
        if (count($names) !== count(array_unique($names)) || array_diff($expected, $names) !== [] || array_diff($names, $expected) !== []) {
            throw new Kd3ParseException('Artifact internal file set is invalid.', 'lzh');
        }
    }
}
