<?php

namespace Tests\Unit;

use App\Kd3\Kd3LayoutRegistry;
use Tests\TestCase;

final class Kd3SpeedLayoutTest extends TestCase
{
    public function test_race_classification_and_cancellation_fields_match_kd3_layout(): void
    {
        $layouts = new Kd3LayoutRegistry;

        $entry = $layouts->get('kol_den1.kd3');
        $this->assertSame(22, $entry['fields']['source_category_code']['offset']);
        $this->assertSame(24, $entry['fields']['discipline_code']['offset']);

        $result = $layouts->get('kol_sei1.kd3');
        $this->assertSame(23, $result['fields']['source_category_code']['offset']);
        $this->assertSame(25, $result['fields']['discipline_code']['offset']);

        $runner = $layouts->get('kol_sei2.kd3');
        $this->assertSame(280, $runner['fields']['cancellation_type_code']['offset']);
    }

    public function test_kol_uma_past_race_snapshots_are_not_decoded(): void
    {
        $layout = (new Kd3LayoutRegistry)->get('kol_uma.kd3');

        $this->assertSame(5166, $layout['record_length']);
        $this->assertSame([], $layout['groups']);
        $this->assertArrayNotHasKey('recent_histories', $layout['groups']);
        $this->assertArrayNotHasKey('older_histories', $layout['groups']);
    }
}
