<?php

namespace App\Kd3;

use Illuminate\Support\Facades\Storage;

final class Kd3Parser
{
    private readonly Kd3RecordDatePolicy $recordDates;

    public function __construct(
        private readonly Kd3LzhExtractor $extractor,
        private readonly Kd3FixedWidthReader $reader,
        private readonly Kd3LayoutRegistry $layouts,
        private readonly Kd3FieldDecoder $decoder,
        ?Kd3RecordDatePolicy $recordDates = null,
    ) {
        $this->recordDates = $recordDates ?? new Kd3RecordDatePolicy;
    }

    public function parse(object $sourceFile): Kd3ParsedPackage
    {
        try {
            return $this->parseSource($sourceFile);
        } catch (Kd3ParseException $exception) {
            throw $exception->withSourceContext(
                (int) ($sourceFile->id ?? 0),
                (string) ($sourceFile->artifact_type ?? ''),
                basename((string) ($sourceFile->original_filename ?? '')),
            );
        }
    }

    private function parseSource(object $sourceFile): Kd3ParsedPackage
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
            }
            $size += strlen($chunk);
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
            /** @var array<string, list<Kd3ParsedRecord>> $parsed */
            $parsed = [];
            foreach ($files as $file) {
                $name = basename($file);
                $layout = $this->layouts->get($name);
                foreach ($this->reader->records($dir.'/out/'.$file, $layout['record_length'], $name) as $number => $record) {
                    $fields = [];
                    foreach ($layout['fields'] as $field => $definition) {
                        $value = $this->decoder->typed($record, $field, $definition, $name, $number);
                        $fields[$field] = $value;
                        if ($field === 'race_date' && ! $this->recordDates->accepts(
                            (string) $sourceFile->artifact_type,
                            $name,
                            str_replace('-', '', (string) $sourceFile->race_date),
                            (string) $value,
                        )) {
                            throw new Kd3ParseException('Record race date differs from artifact date.', 'field_validation', $name, $number, $definition['offset'], $field);
                        }
                    }
                    foreach ($layout['groups'] as $groupName => $group) {
                        $items = [];
                        for ($index = 0; $index < $group['count']; $index++) {
                            $groupOffset = $group['offset'] + ($index * $group['stride']);
                            if (isset($group['skip_when_blank']) && trim(substr($record, $groupOffset + $group['skip_when_blank'][0], $group['skip_when_blank'][1])) === '') {
                                continue;
                            }
                            if (($group['skip_blank'] ?? false) && trim(substr($record, $groupOffset, $group['stride'])) === '') {
                                continue;
                            }
                            $item = ['sequence' => $index + 1];
                            foreach ($group['fields'] as $field => $definition) {
                                $definition['offset'] += $groupOffset;
                                $item[$field] = $this->decoder->typed($record, $groupName.'.'.$field, $definition, $name, $number);
                            }
                            $items[] = $item;
                        }
                        $fields[$groupName] = $items;
                    }
                    $parsed[$name][] = new Kd3ParsedRecord((int) $sourceFile->id, $sourceFile->original_filename, $sourceFile->artifact_type, $name, $number, $fields);
                    $count++;
                }
            }
            $this->validatePackage($sourceFile->artifact_type, $parsed);

            return new Kd3ParsedPackage((int) $sourceFile->id, $sourceFile->original_filename, $sourceFile->artifact_type, $files, $parsed, $count);
        } finally {
            $this->remove($dir);
        }
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $this->remove($path.'/'.$item);
            }
        }
        @rmdir($path);
    }

    /** @param list<string> $files */
    private function assertExpectedFiles(string $artifactType, array $files): void
    {
        $set = [
            'hb' => ['required' => ['kol_den1.kd3', 'kol_den2.kd3', 'kol_uma.kd3'], 'optional' => []],
            'ib' => ['required' => ['kol_sei1.kd3', 'kol_sei2.kd3', 'kol_uma.kd3'], 'optional' => ['kol_sei3.kd3']],
            'jb' => ['required' => ['kol_ods.kd3', 'kol_ods2.kd3'], 'optional' => []],
            'kd' => ['required' => ['kol_kod.kd3', 'kol_kod2.kd3', 'kol_kod3.kd3'], 'optional' => []],
            'lb' => ['required' => ['kol_com1.kd3'], 'optional' => []],
            'mb' => ['required' => ['kol_com1.kd3'], 'optional' => []],
        ][$artifactType] ?? null;
        if ($set === null) {
            throw new Kd3ParseException('Unknown KD3 artifact type.', 'lzh');
        }
        $required = $set['required'];
        $optional = $set['optional'];
        $names = array_map('basename', $files);
        if (count($names) !== count(array_unique($names)) || array_diff($required, $names) !== [] || array_diff($names, [...$required, ...$optional]) !== []) {
            throw new Kd3ParseException('Artifact internal file set is invalid.', 'lzh');
        }
    }

    /** @param array<string, list<Kd3ParsedRecord>> $parsed */
    private function validatePackage(string $artifactType, array $parsed): void
    {
        if ($artifactType !== 'hb' && $artifactType !== 'ib') {
            return;
        }
        [$header, $runner] = $artifactType === 'hb' ? ['kol_den1.kd3', 'kol_den2.kd3'] : ['kol_sei1.kd3', 'kol_sei2.kd3'];
        $raceKey = static fn (Kd3ParsedRecord $record): string => implode(':', array_map(
            static fn (string $field): string => (string) ($record->fields[$field] ?? ''),
            ['venue_code', 'year', 'meeting_no', 'meeting_day', 'race_no'],
        ));
        $horseCodes = [];
        foreach ($parsed['kol_uma.kd3'] as $row) {
            $code = $row->fields['horse_code'] ?? null;
            if (! is_string($code) || isset($horseCodes[$code])) {
                throw new Kd3ParseException('Duplicate or invalid horse key.', 'cross_file_validation', 'kol_uma.kd3', $row->recordNumber, null, 'horse_code');
            }
            $horseCodes[$code] = true;
        }
        $headerCounts = [];
        foreach ($parsed[$header] as $row) {
            $key = $raceKey($row);
            if (isset($headerCounts[$key])) {
                throw new Kd3ParseException('Duplicate race key.', 'cross_file_validation', $header, $row->recordNumber, null, 'race_no');
            }
            $headerCounts[$key] = $row->fields['runner_count'] ?? null;
        }
        $runnerKeys = [];
        $runnerCounts = [];
        foreach ($parsed[$runner] as $row) {
            $race = $raceKey($row);
            $key = $race.':'.($row->fields['horse_code'] ?? '');
            if (isset($runnerKeys[$key])) {
                throw new Kd3ParseException('Duplicate runner key.', 'cross_file_validation', $runner, $row->recordNumber, null, 'horse_code');
            }
            $runnerKeys[$key] = true;
            if (! isset($horseCodes[$row->fields['horse_code'] ?? ''])) {
                throw new Kd3ParseException('Runner horse is absent from pack.', 'cross_file_validation', $runner, $row->recordNumber, null, 'horse_code');
            }
            if (! array_key_exists($race, $headerCounts)) {
                throw new Kd3ParseException('Runner race is absent from pack.', 'cross_file_validation', $runner, $row->recordNumber, null, 'race_no');
            }
            $runnerCounts[$race] = ($runnerCounts[$race] ?? 0) + 1;
        }
        foreach ($parsed[$header] as $row) {
            $race = $raceKey($row);
            if ($headerCounts[$race] !== ($runnerCounts[$race] ?? 0)) {
                throw new Kd3ParseException('Header runner count differs from runner records.', 'cross_file_validation', $header, $row->recordNumber, null, 'runner_count');
            }
        }
    }
}
