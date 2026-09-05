<?php
namespace App\Kd3;

interface Kd3LzhExtractor { /** @return list<string> */ public function extract(string $archive, string $directory): array; }
