<?php

namespace App\Kd3;

final readonly class Kd3FetchResult
{
    private function __construct(
        public bool $available,
        public ?string $body,
        public ?string $filePath,
        public ?string $filename,
        public string $sourceUrl,
        public int $httpStatus,
    ) {}

    public static function available(string $body, string $filename, string $sourceUrl, int $httpStatus): self
    {
        return new self(true, $body, null, $filename, $sourceUrl, $httpStatus);
    }

    public static function availableFile(string $filePath, string $filename, string $sourceUrl, int $httpStatus): self
    {
        return new self(true, null, $filePath, $filename, $sourceUrl, $httpStatus);
    }

    public static function notAvailable(string $sourceUrl, int $httpStatus): self
    {
        return new self(false, null, null, null, $sourceUrl, $httpStatus);
    }
}
