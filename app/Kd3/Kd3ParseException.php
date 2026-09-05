<?php

namespace App\Kd3;

use RuntimeException;

final class Kd3ParseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly ?string $fileName = null,
        public readonly ?int $recordNumber = null,
        public readonly ?int $byteOffset = null,
        public readonly ?string $field = null,
        public readonly ?int $sourceFileId = null,
        public readonly ?string $artifactType = null,
        public readonly ?string $originalFilename = null,
    ) {
        parent::__construct($message);
    }

    public function withSourceContext(int $sourceFileId, string $artifactType, string $originalFilename): self
    {
        return new self(
            $this->getMessage(),
            $this->category,
            $this->fileName,
            $this->recordNumber,
            $this->byteOffset,
            $this->field,
            $sourceFileId,
            $artifactType,
            $originalFilename,
        );
    }
}
