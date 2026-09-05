<?php

namespace App\Kd3;

final readonly class Kd3ParsedRecord
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        public int $sourceFileId,
        public string $originalFilename,
        public string $artifactType,
        public string $fileName,
        public int $recordNumber,
        public array $fields,
    ) {}
}
