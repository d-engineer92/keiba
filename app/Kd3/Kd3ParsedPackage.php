<?php

namespace App\Kd3;

final readonly class Kd3ParsedPackage
{
    /**
     * @param  list<string>  $files
     * @param  array<string, list<Kd3ParsedRecord>>  $records
     */
    public function __construct(
        public int $sourceFileId,
        public string $originalFilename,
        public string $artifactType,
        public array $files,
        public array $records,
        public int $recordCount,
    ) {}

    /** @return iterable<Kd3ParsedRecord> */
    public function records(): iterable
    {
        foreach ($this->records as $records) {
            foreach ($records as $record) {
                yield $record;
            }
        }
    }
}
