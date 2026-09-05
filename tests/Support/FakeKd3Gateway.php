<?php

namespace Tests\Support;

use App\Kd3\Kd3FetchResult;
use App\Kd3\Kd3Gateway;
use Carbon\CarbonImmutable;
use RuntimeException;

final class FakeKd3Gateway implements Kd3Gateway
{
    /** @var array<string, list<Kd3FetchResult|\Throwable>> */
    public array $responses = [];

    /** @var list<string> */
    public array $requests = [];

    public function fetch(CarbonImmutable $raceDate, string $artifactType): Kd3FetchResult
    {
        $key = $raceDate->toDateString().':'.$artifactType;
        $this->requests[] = $key;
        $response = array_shift($this->responses[$key]);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (! $response instanceof Kd3FetchResult) {
            throw new RuntimeException("No fake response for $key");
        }

        return $response;
    }

    public function queue(string $date, string $type, Kd3FetchResult|\Throwable ...$responses): void
    {
        $this->responses["$date:$type"] = $responses;
    }
}
