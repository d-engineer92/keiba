<?php

namespace App\Kd3;

interface Kd3SourceImportRunner
{
    public function run(int $sourceFileId, string $memoryLimit): Kd3SourceImportProcessResult;
}
