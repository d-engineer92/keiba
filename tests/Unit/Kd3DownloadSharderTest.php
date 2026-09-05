<?php

namespace Tests\Unit;

use App\Kd3\Kd3DownloadSharder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class Kd3DownloadSharderTest extends TestCase
{
    public function test_items_are_distributed_across_workers_without_overlap_or_gaps(): void
    {
        $items = range(0, 10);
        $sharder = new Kd3DownloadSharder;

        $shards = [];
        for ($worker = 0; $worker < 4; $worker++) {
            $shards[] = $sharder->select($items, $worker, 4);
        }

        $this->assertSame([0, 4, 8], $shards[0]);
        $this->assertSame([1, 5, 9], $shards[1]);
        $this->assertSame([2, 6, 10], $shards[2]);
        $this->assertSame([3, 7], $shards[3]);

        $combined = array_merge(...$shards);
        sort($combined);
        $this->assertSame($items, $combined);
    }

    public function test_invalid_worker_context_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Kd3DownloadSharder)->select(['a'], 1, 1);
    }
}
