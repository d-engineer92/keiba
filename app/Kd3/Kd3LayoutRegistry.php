<?php

namespace App\Kd3;

final class Kd3LayoutRegistry
{
    /** @return array<string, mixed> */
    public function get(string $file): array
    {
        $lengths = ['kol_den1.kd3' => 848, 'kol_den2.kd3' => 1000, 'kol_uma.kd3' => 5166, 'kol_sei1.kd3' => 3200, 'kol_sei2.kd3' => 600, 'kol_sei3.kd3' => 1050, 'kol_ods.kd3' => 1504, 'kol_ods2.kd3' => 9043, 'kol_kod.kd3' => 1504, 'kol_kod2.kd3' => 9043, 'kol_kod3.kd3' => 49123, 'kol_com1.kd3' => 3010];
        if (! isset($lengths[$file])) {
            throw new Kd3ParseException('Unknown KD3 internal file.', 'physical_layout', $file);
        }

        $field = static fn (int $offset, int $length, string $type = 'code', bool $nullable = true, string $trim = 'right'): array => compact('offset', 'length', 'type', 'nullable', 'trim');
        $race = [
            'venue_code' => $field(0, 2, 'code', false), 'year' => $field(2, 4, 'numeric', false),
            'meeting_no' => $field(6, 2, 'code', false), 'meeting_day' => $field(8, 2, 'code', false),
            'race_no' => $field(10, 2, 'code', false), 'race_date' => $field(12, 8, 'date', false),
        ];
        $fields = match ($file) {
            'kol_den1.kd3' => $race + [
                'source_category_code' => $field(22, 1), 'discipline_code' => $field(24, 1),
                'race_name' => $field(28, 30, 'text'), 'grade_code' => $field(72, 1), 'weight_condition_code' => $field(74, 2),
                'age_condition_code' => $field(99, 1), 'class_code' => $field(100, 5), 'surface_code' => $field(105, 1),
                'course_direction_code' => $field(106, 1), 'course_code' => $field(108, 1),
                'distance' => $field(109, 4, 'numeric'), 'scheduled_start' => $field(332, 5, 'text'),
                'runner_count' => $field(337, 2, 'numeric', false),
            ],
            'kol_den2.kd3' => $race + [
                'frame_no' => $field(22, 1, 'numeric'), 'horse_no' => $field(23, 2, 'numeric'),
                'horse_code' => $field(25, 7, 'code', false), 'horse_name' => $field(32, 30, 'text'), 'sex_code' => $field(64, 1),
                'assigned_weight_tenths' => $field(148, 3, 'numeric'), 'jockey_code' => $field(151, 5), 'jockey_name' => $field(156, 32, 'text'),
                'trainer_code' => $field(206, 5), 'trainer_name' => $field(211, 32, 'text'), 'entry_mark_code' => $field(254, 1),
                'birth_year' => $field(735, 4, 'numeric'),
            ],
            'kol_sei1.kd3' => $race + [
                'source_category_code' => $field(23, 1), 'discipline_code' => $field(25, 1),
                'runner_count' => $field(366, 2, 'numeric', false), 'cancelled_runner_count' => $field(368, 2, 'numeric'),
                'pace_code' => $field(378, 1), 'weather_code' => $field(379, 1), 'track_condition_code' => $field(380, 1),
            ],
            'kol_sei2.kd3' => $race + [
                'horse_no' => $field(23, 2, 'numeric'), 'horse_code' => $field(27, 7, 'code', false),
                'horse_name' => $field(34, 30, 'text'), 'sex_code' => $field(66, 1), 'assigned_weight_tenths' => $field(150, 3, 'numeric'),
                'body_weight' => $field(153, 3, 'numeric'), 'body_weight_delta' => $field(156, 3, 'signed_numeric'),
                'jockey_code' => $field(162, 5), 'jockey_name' => $field(167, 32, 'text'), 'trainer_code' => $field(217, 5),
                'trainer_name' => $field(222, 32, 'text'), 'popularity' => $field(267, 2, 'numeric'),
                'final_odds_tenths' => $field(269, 5, 'numeric'), 'finish_position' => $field(274, 2, 'numeric'),
                'finish_status_code' => $field(276, 2), 'cancellation_type_code' => $field(280, 1), 'finish_time' => $field(282, 4),
                'margin_whole' => $field(286, 2), 'margin_code' => $field(288, 1), 'last_3f_tenths' => $field(295, 3, 'numeric'),
                'passing_1' => $field(298, 2), 'passing_2' => $field(300, 2), 'passing_3' => $field(302, 2), 'passing_4' => $field(304, 2),
                'birth_year' => $field(432, 4, 'numeric'),
            ],
            'kol_sei3.kd3' => $race + ['sanction_description' => $field(44, 960, 'text')],
            'kol_uma.kd3' => [
                'horse_code' => $field(0, 7, 'code', false), 'horse_name' => $field(7, 30, 'text'),
                'birth_year' => $field(77, 4, 'numeric'), 'birth_month_day' => $field(81, 4), 'color_code' => $field(85, 2),
                'breed_code' => $field(87, 2), 'sex_code' => $field(94, 1), 'trainer_code' => $field(488, 5),
                'trainer_name' => $field(493, 32, 'text'),
            ],
            'kol_com1.kd3' => $race + [
                'horse_code' => $field(20, 7), 'horse_name' => $field(27, 18, 'text'),
                'jockey_code' => $field(78, 5), 'jockey_name' => $field(83, 8, 'text'),
                'connections_comment' => $field(91, 960, 'text'), 'next_race_memo' => $field(1051, 960, 'text'),
                'previous_comment' => $field(2011, 960, 'text'),
            ],
            default => $race,
        };
        $groups = match ($file) {
            'kol_den2.kd3' => [
                'workouts' => ['count' => 3, 'offset' => 257, 'stride' => 117, 'fields' => [
                    'rider' => $field(0, 8, 'text'), 'training_date' => $field(8, 8, 'date'), 'place' => $field(16, 6, 'text'),
                    'course_code' => $field(22, 2, 'text'), 'track_condition' => $field(24, 2, 'text'),
                    'clock_8f' => $field(26, 6, 'text'), 'clock_7f' => $field(32, 6, 'text'), 'clock_6f' => $field(38, 6, 'text'),
                    'clock_5f' => $field(44, 6, 'text'), 'clock_4f' => $field(50, 6, 'text'), 'clock_3f' => $field(56, 6, 'text'),
                    'clock_1f' => $field(62, 6, 'text'), 'position_code' => $field(68, 1), 'evaluation' => $field(69, 6, 'text'),
                    'exception_text' => $field(76, 40, 'text'),
                ]],
                'speed_indices' => ['count' => 5, 'offset' => 742, 'stride' => 5, 'reverse_slots' => true, 'fields' => ['speed_index' => $field(0, 5, 'signed_decimal', true, 'both')]],
            ],
            'kol_ods.kd3', 'kol_kod.kd3' => [
                'win' => ['count' => 18, 'offset' => 161, 'stride' => 5, 'market' => 'win', 'fields' => ['odds_raw' => $field(0, 5, 'text', false)]],
                'bracket_quinella' => ['count' => 36, 'offset' => 251, 'stride' => 5, 'market' => 'bracket_quinella', 'fields' => ['odds_raw' => $field(0, 5, 'text', false)]],
                'quinella' => ['count' => 153, 'offset' => 431, 'stride' => 7, 'market' => 'quinella', 'fields' => ['odds_raw' => $field(0, 7, 'text', false)]],
            ],
            'kol_ods2.kd3' => [
                'exacta' => ['count' => 306, 'offset' => 1799, 'stride' => 5, 'market' => 'exacta', 'fields' => ['odds_raw' => $field(0, 5, 'text', false)]],
                'trio' => ['count' => 816, 'offset' => 3329, 'stride' => 7, 'market' => 'trio', 'fields' => ['odds_raw' => $field(0, 7, 'text', false)]],
            ],
            'kol_kod2.kd3' => [
                'place' => ['count' => 18, 'offset' => 161, 'stride' => 6, 'market' => 'place', 'fields' => ['odds_raw' => $field(0, 6, 'text', false)]],
                'wide' => ['count' => 153, 'offset' => 269, 'stride' => 10, 'market' => 'wide', 'fields' => ['odds_raw' => $field(0, 10, 'text', false)]],
                'exacta' => ['count' => 306, 'offset' => 1799, 'stride' => 5, 'market' => 'exacta', 'fields' => ['odds_raw' => $field(0, 5, 'text', false)]],
                'trio' => ['count' => 816, 'offset' => 3329, 'stride' => 7, 'market' => 'trio', 'fields' => ['odds_raw' => $field(0, 7, 'text', false)]],
            ],
            'kol_kod3.kd3' => ['trifecta' => ['count' => 4896, 'offset' => 161, 'stride' => 10, 'market' => 'trifecta', 'fields' => ['odds_raw' => $field(0, 10, 'text', false)]]],
            default => [],
        };

        return ['record_length' => $lengths[$file], 'spec_version' => (string) config('kd3.spec_version'), 'fields' => $fields, 'groups' => $groups];
    }
}
