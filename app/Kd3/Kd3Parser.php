<?php

namespace App\Kd3;

use Illuminate\Support\Facades\Storage;

final class Kd3Parser
{
    public function __construct(private readonly Kd3LzhExtractor $extractor, private readonly Kd3FixedWidthReader $reader, private readonly Kd3LayoutRegistry $layouts) {}

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
            $count = 0;
            foreach ($files as $file) {
                $name = basename($file);
                $layout = $this->layouts->get($name);
                $count += iterator_count($this->reader->records($dir.'/out/'.$file, $layout['record_length'], $name));
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
}
