<?php

namespace App\Kd3;

final readonly class Kd3SourceImportProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
