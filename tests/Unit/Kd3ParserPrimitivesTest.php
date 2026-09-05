<?php

namespace Tests\Unit;

use App\Kd3\Kd3FieldDecoder;
use App\Kd3\Kd3FixedWidthReader;
use App\Kd3\Kd3ParseException;
use Tests\TestCase;

class Kd3ParserPrimitivesTest extends TestCase
{
    public function test_decoder_slices_cp932_bytes_before_decoding(): void
    {
        $record = '01'.mb_convert_encoding('テスト', 'SJIS-win', 'UTF-8').'  ';
        $this->assertSame('テスト', (new Kd3FieldDecoder)->decode($record, 2, 6, 'name'));
        $this->assertNull((new Kd3FieldDecoder)->decode('  ', 0, 2, 'optional'));
    }

    public function test_reader_rejects_bad_crlf_with_record_diagnostic(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kd3-reader-');
        file_put_contents($path, "abcd\n");
        try {
            iterator_to_array((new Kd3FixedWidthReader)->records($path, 6, 'kol_den1.kd3'));
        } catch (Kd3ParseException $exception) {
            $this->assertSame('physical_layout', $exception->category);
            $this->assertSame(1, $exception->recordNumber);

            return;
        } finally {
            unlink($path);
        }
        $this->fail('Expected physical layout exception.');
    }
}
