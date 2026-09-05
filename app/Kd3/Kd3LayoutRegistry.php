<?php

namespace App\Kd3;

final class Kd3LayoutRegistry
{
    /** @return array{record_length:int,spec_version:string,fields:array<string,mixed>} */
    public function get(string $file): array
    {
        $lengths = ['kol_den1.kd3' => 848, 'kol_den2.kd3' => 1000, 'kol_uma.kd3' => 5166, 'kol_sei1.kd3' => 3200, 'kol_sei2.kd3' => 600, 'kol_sei3.kd3' => 1050, 'kol_ods.kd3' => 1504, 'kol_ods2.kd3' => 9043, 'kol_kod.kd3' => 1504, 'kol_kod2.kd3' => 9043, 'kol_kod3.kd3' => 49123, 'kol_com1.kd3' => 3010];
        if (! isset($lengths[$file])) {
            throw new Kd3ParseException('Unknown KD3 internal file.', 'physical_layout', $file);
        }

        return ['record_length' => $lengths[$file], 'spec_version' => (string) config('kd3.spec_version'), 'fields' => []];
    }
}
