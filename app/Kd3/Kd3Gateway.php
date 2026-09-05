<?php

namespace App\Kd3;

use Carbon\CarbonImmutable;

interface Kd3Gateway
{
    public function fetch(CarbonImmutable $raceDate, string $artifactType): Kd3FetchResult;
}
