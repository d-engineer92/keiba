<?php

namespace Tests\Unit;

use App\Kd3\Kd3RecordDatePolicy;
use PHPUnit\Framework\TestCase;

final class Kd3RecordDatePolicyTest extends TestCase
{
    public function test_regular_artifacts_require_record_date_to_match_artifact_date(): void
    {
        $policy = new Kd3RecordDatePolicy;

        $this->assertTrue($policy->accepts('hb', 'kol_den1.kd3', '20260906', '20260906'));
        $this->assertFalse($policy->accepts('hb', 'kol_den1.kd3', '20260906', '20260907'));
    }

    public function test_comments_keep_their_existing_cross_date_exception(): void
    {
        $policy = new Kd3RecordDatePolicy;

        $this->assertTrue($policy->accepts('lb', 'kol_com1.kd3', '20260906', '20260830'));
    }

    public function test_confirmed_odds_before_cutover_can_include_later_race_dates_in_the_legacy_pack(): void
    {
        $policy = new Kd3RecordDatePolicy;

        $this->assertTrue($policy->accepts('kd', 'kol_kod.kd3', '20071006', '20071006'));
        $this->assertTrue($policy->accepts('kd', 'kol_kod.kd3', '20071006', '20071007'));
        $this->assertTrue($policy->accepts('kd', 'kol_kod2.kd3', '20071006', '20071008'));
        $this->assertTrue($policy->accepts('kd', 'kol_kod3.kd3', '20071006', '20071012'));
    }

    public function test_legacy_confirmed_odds_exception_does_not_cross_the_daily_cutover(): void
    {
        $policy = new Kd3RecordDatePolicy;

        $this->assertFalse($policy->accepts('kd', 'kol_kod.kd3', '20071006', '20071013'));
        $this->assertFalse($policy->accepts('kd', 'kol_kod.kd3', '20071013', '20071014'));
        $this->assertFalse($policy->accepts('kd', 'kol_kod.kd3', '20071006', '20071005'));
    }
}
