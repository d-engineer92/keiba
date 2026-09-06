<?php

namespace App\Kd3;

interface Kd3SourceImportRunner
{
    /** @param list<int> $sourceFileIds */
    public function run(array $sourceFileIds, string $memoryLimit): Kd3SourceImportProcessResult;
}
