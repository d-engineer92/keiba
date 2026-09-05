<?php

namespace App\Kd3;

use InvalidArgumentException;

final class Kd3DownloadSharder
{
    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function select(array $items, int $workerIndex, int $workerCount): array
    {
        if ($workerCount < 1) {
            throw new InvalidArgumentException('Worker count must be positive.');
        }
        if ($workerIndex < 0 || $workerIndex >= $workerCount) {
            throw new InvalidArgumentException('Worker index is outside the worker count.');
        }

        $selected = [];
        foreach ($items as $index => $item) {
            if ($index % $workerCount === $workerIndex) {
                $selected[] = $item;
            }
        }

        return $selected;
    }
}
