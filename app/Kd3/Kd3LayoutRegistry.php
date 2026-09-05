<?php

namespace App\Kd3;

final class Kd3LayoutRegistry
{
    /** @return array{record_length:int,spec_version:string,fields:array<string,array{offset:int,length:int,type:string,nullable:bool,trim:string}>} */
    public function get(string $file): array
    {
        $lengths = ['kol_den1.kd3' => 848, 'kol_den2.kd3' => 1000, 'kol_uma.kd3' => 5166, 'kol_sei1.kd3' => 3200, 'kol_sei2.kd3' => 600, 'kol_sei3.kd3' => 1050, 'kol_ods.kd3' => 1504, 'kol_ods2.kd3' => 9043, 'kol_kod.kd3' => 1504, 'kol_kod2.kd3' => 9043, 'kol_kod3.kd3' => 49123, 'kol_com1.kd3' => 3010];
        if (! isset($lengths[$file])) {
            throw new Kd3ParseException('Unknown KD3 internal file.', 'physical_layout', $file);
        }

        $race = [
            'venue_code' => ['offset' => 0, 'length' => 2, 'type' => 'code', 'nullable' => false, 'trim' => 'right'],
            'year' => ['offset' => 2, 'length' => 4, 'type' => 'numeric', 'nullable' => false, 'trim' => 'right'],
            'meeting_no' => ['offset' => 6, 'length' => 2, 'type' => 'code', 'nullable' => false, 'trim' => 'right'],
            'meeting_day' => ['offset' => 8, 'length' => 2, 'type' => 'code', 'nullable' => false, 'trim' => 'right'],
            'race_no' => ['offset' => 10, 'length' => 2, 'type' => 'code', 'nullable' => false, 'trim' => 'right'],
            'race_date' => ['offset' => 12, 'length' => 8, 'type' => 'date', 'nullable' => false, 'trim' => 'right'],
        ];
        $fields = match ($file) {
            'kol_den1.kd3' => $race + ['runner_count' => ['offset' => 337, 'length' => 2, 'type' => 'numeric', 'nullable' => false, 'trim' => 'right']],
            'kol_den2.kd3' => $race + ['horse_code' => ['offset' => 25, 'length' => 7, 'type' => 'code', 'nullable' => false, 'trim' => 'right']],
            'kol_sei1.kd3' => $race + ['runner_count' => ['offset' => 366, 'length' => 2, 'type' => 'numeric', 'nullable' => false, 'trim' => 'right']],
            'kol_sei2.kd3' => $race + ['horse_code' => ['offset' => 27, 'length' => 7, 'type' => 'code', 'nullable' => false, 'trim' => 'right']],
            'kol_uma.kd3' => ['horse_code' => ['offset' => 0, 'length' => 7, 'type' => 'code', 'nullable' => false, 'trim' => 'right']],
            default => $race,
        };

        return ['record_length' => $lengths[$file], 'spec_version' => (string) config('kd3.spec_version'), 'fields' => $fields];
    }
}
