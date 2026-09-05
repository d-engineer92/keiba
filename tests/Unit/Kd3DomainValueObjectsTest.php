<?php

namespace Tests\Unit;

use App\Kd3\Domain\OddsNormalizer;
use App\Kd3\Domain\RaceKey;
use App\Kd3\Domain\SpeedCalculator;
use PHPUnit\Framework\TestCase;

final class Kd3DomainValueObjectsTest extends TestCase
{
    public function test_race_and_history_keys_are_deterministic(): void
    {
        $fields = ['venue_code' => '04', 'year' => 2026, 'meeting_no' => '01', 'meeting_day' => '02', 'race_no' => '03', 'race_date' => '20260905'];
        $this->assertSame('04:2026:01:02:03', RaceKey::from($fields));
        $this->assertSame(RaceKey::history($fields), RaceKey::history($fields));
        $this->assertNotSame(RaceKey::history($fields), RaceKey::history(array_replace($fields, ['race_no' => '04'])));
    }

    public function test_odds_combinations_preserve_order_only_for_ordered_markets(): void
    {
        $normalizer = new OddsNormalizer;
        $this->assertSame([[1, 2], [1, 3]], array_slice($normalizer->combinations('quinella'), 0, 2));
        $this->assertSame([[1, 2], [1, 3]], array_slice($normalizer->combinations('exacta'), 0, 2));
        $this->assertContains([2, 1], $normalizer->combinations('exacta'));
        $this->assertNotContains([2, 1], $normalizer->combinations('quinella'));
        $this->assertCount(816, $normalizer->combinations('trio'));
        $this->assertCount(4896, $normalizer->combinations('trifecta'));
    }

    public function test_odds_special_values_and_ranges_are_not_coerced_to_zero(): void
    {
        $normalizer = new OddsNormalizer;
        $this->assertSame(1.5, $normalizer->value('00015')['odds']);
        $this->assertSame('missing', $normalizer->value('     ')['status']);
        $this->assertSame('cancelled', $normalizer->value('/    ')['status']);
        $this->assertSame('not_offered', $normalizer->value('-    ')['status']);
        $this->assertSame('above_limit', $normalizer->value('*    ')['status']);
        $this->assertSame([1.2, 1.4], array_values(array_intersect_key($normalizer->value('012014', true), ['odds_min' => true, 'odds_max' => true])));
    }

    public function test_speed_statistics_use_population_stddev_and_handle_zero_variance(): void
    {
        $calculator = new SpeedCalculator;
        $result = $calculator->calculate([40, 50, 60]);
        $this->assertSame(50.0, $result['mean']);
        $this->assertSame(50.0, $result['median']);
        $this->assertEqualsWithDelta(8.1649658, $result['stddev'], 0.000001);
        $this->assertSame(10.0, $result['mad']);
        $this->assertSame(3, $result['valid_count']);
        $zero = $calculator->calculate([50, 50]);
        $this->assertNull($zero['metrics'][0]['zscore']);
        $this->assertNull($zero['metrics'][0]['deviation_score']);
        $this->assertNull($zero['metrics'][0]['robust_zscore']);
    }
}
