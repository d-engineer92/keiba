<?php

namespace Tests\Unit;

use App\Kd3\Kd3LayoutRegistry;
use App\Kd3\Kd3ParseException;
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

    public function test_initial_2007_forecast_odds2_layout_is_fingerprinted_from_physical_size(): void
    {
        $layout = (new Kd3LayoutRegistry)->resolve('kol_ods2.kd3', 8070 * 12);

        $this->assertSame(8070, $layout['record_length']);
        $this->assertSame('initial-2007-10-01', $layout['layout_version']);
        $this->assertSame(214, $layout['groups']['exacta']['offset']);
        $this->assertSame(7, $layout['groups']['exacta']['stride']);
        $this->assertSame(2356, $layout['groups']['trio']['offset']);
    }

    public function test_corrected_forecast_odds2_layout_is_fingerprinted_from_physical_size(): void
    {
        $layout = (new Kd3LayoutRegistry)->resolve('kol_ods2.kd3', 9043 * 12);

        $this->assertSame(9043, $layout['record_length']);
        $this->assertSame(1799, $layout['groups']['exacta']['offset']);
        $this->assertSame(5, $layout['groups']['exacta']['stride']);
        $this->assertSame(3329, $layout['groups']['trio']['offset']);
    }

    public function test_initial_2007_confirmed_odds_layouts_are_fingerprinted(): void
    {
        $layouts = new Kd3LayoutRegistry;

        $kod2 = $layouts->resolve('kol_kod2.kd3', 9050 * 12);
        $this->assertSame(168, $kod2['groups']['place']['offset']);
        $this->assertSame(276, $kod2['groups']['wide']['offset']);
        $this->assertSame(1806, $kod2['groups']['exacta']['offset']);
        $this->assertSame(3336, $kod2['groups']['trio']['offset']);

        $kod3 = $layouts->resolve('kol_kod3.kd3', 49124 * 12);
        $this->assertSame(49124, $kod3['record_length']);
        $this->assertSame(162, $kod3['groups']['trifecta']['offset']);
    }

    public function test_unknown_physical_width_is_rejected_instead_of_guessed(): void
    {
        $this->expectException(Kd3ParseException::class);

        (new Kd3LayoutRegistry)->resolve('kol_ods2.kd3', 9000);
    }
}
