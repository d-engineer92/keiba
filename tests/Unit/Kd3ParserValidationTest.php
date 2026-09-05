<?php

namespace Tests\Unit;

use App\Kd3\Kd3FieldDecoder;
use App\Kd3\Kd3FixedWidthReader;
use App\Kd3\Kd3LayoutRegistry;
use App\Kd3\Kd3LzhExtractor;
use App\Kd3\Kd3ParseException;
use App\Kd3\Kd3Parser;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Kd3ParserValidationTest extends TestCase
{
    public function test_hb_rejects_unresolved_runner_horse(): void
    {
        Storage::fake('kd3-parser');
        $archive = 'synthetic';
        Storage::disk('kd3-parser')->put('source.lzh', $archive);
        $extractor = new class implements Kd3LzhExtractor
        {
            public function extract(string $archive, string $directory): array
            {
                $date = '20260905';
                file_put_contents($directory.'/kol_den1.kd3', $this->record(848, $date, 2, null));
                file_put_contents($directory.'/kol_den2.kd3', $this->record(1000, $date, null, '0000001'));
                file_put_contents($directory.'/kol_uma.kd3', $this->horse(5166, '0000002'));

                return ['kol_den1.kd3', 'kol_den2.kd3', 'kol_uma.kd3'];
            }

            private function record(int $length, string $date, ?int $count, ?string $horse): string
            {
                $body = str_repeat(' ', $length - 2);
                $body = substr_replace($body, '012026010105'.$date, 0, 20);
                if ($count !== null) {
                    $body = substr_replace($body, str_pad((string) $count, 2, '0', STR_PAD_LEFT), 337, 2);
                }
                if ($horse !== null) {
                    $body = substr_replace($body, $horse, 25, 7);
                }

                return $body."\r\n";
            }

            private function horse(int $length, string $horse): string
            {
                return substr_replace(str_repeat(' ', $length - 2), $horse, 0, 7)."\r\n";
            }
        };
        $parser = new Kd3Parser($extractor, new Kd3FixedWidthReader, new Kd3LayoutRegistry, new Kd3FieldDecoder);
        $source = (object) ['id' => 1, 'source_system' => 'kd3', 'storage_disk' => 'kd3-parser', 'storage_path' => 'source.lzh', 'size_bytes' => strlen($archive), 'sha256' => hash('sha256', $archive), 'race_date' => '2026-09-05', 'artifact_type' => 'hb', 'original_filename' => 'synthetic.lzh'];
        try {
            $parser->parse($source);
        } catch (Kd3ParseException $exception) {
            $this->assertSame('cross_file_validation', $exception->category);
            $this->assertSame('horse_code', $exception->field);

            return;
        }
        $this->fail('Expected package validation failure.');
    }
}
