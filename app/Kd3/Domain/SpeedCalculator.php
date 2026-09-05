<?php

namespace App\Kd3\Domain;

final class SpeedCalculator
{
    /** @param list<float|int> $input
     * @return array{valid_count:int,excluded_count:int,mean:?float,median:?float,stddev:?float,min:?float,max:?float,mad:?float,metrics:list<array{rank:int,percentile:float,zscore:?float,deviation_score:?float,robust_zscore:?float,robust_deviation_score:?float}>}
     */
    public function calculate(array $input): array
    {
        $values = array_map('floatval', $input);
        sort($values, SORT_NUMERIC);
        $count = count($values);
        if ($count === 0) {
            return ['valid_count' => 0, 'excluded_count' => 0, 'mean' => null, 'median' => null, 'stddev' => null, 'min' => null, 'max' => null, 'mad' => null, 'metrics' => []];
        }
        $mean = array_sum($values) / $count;
        $median = $this->median($values);
        $variance = array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $values)) / $count;
        $stddev = sqrt($variance);
        $deviations = array_map(static fn (float $value): float => abs($value - $median), $values);
        sort($deviations, SORT_NUMERIC);
        $mad = $this->median($deviations);
        $descending = $values;
        rsort($descending, SORT_NUMERIC);
        $metrics = [];
        foreach ($values as $value) {
            $z = $stddev > 0 ? ($value - $mean) / $stddev : null;
            $robust = $mad > 0 ? 0.67448975 * ($value - $median) / $mad : null;
            $metrics[] = ['rank' => array_search($value, $descending, true) + 1, 'percentile' => $count === 1 ? 1.0 : array_search($value, $values, true) / ($count - 1),
                'zscore' => $z, 'deviation_score' => $z === null ? null : 50 + 10 * $z,
                'robust_zscore' => $robust, 'robust_deviation_score' => $robust === null ? null : 50 + 10 * $robust];
        }

        return ['valid_count' => $count, 'excluded_count' => 0, 'mean' => $mean, 'median' => $median, 'stddev' => $stddev,
            'min' => min($values), 'max' => max($values), 'mad' => $mad, 'metrics' => $metrics];
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
