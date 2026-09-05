<?php

namespace Tests\Unit;

use App\Kd3\Kd3FieldDecoder;
use App\Kd3\Kd3FixedWidthReader;
use App\Kd3\Kd3LayoutRegistry;
use App\Kd3\Kd3LzhExtractor;
use App\Kd3\Kd3ParsedPackage;
use App\Kd3\Kd3ParseException;
use App\Kd3\Kd3Parser;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Kd3ParserAcceptanceTest extends TestCase
{
    public function test_returns_typed_records_with_source_context_and_leading_zero_codes(): void
    {
        $package = $this->parse('hb', $this->hb());

        $this->assertInstanceOf(Kd3ParsedPackage::class, $package);
        $this->assertSame(42, $package->sourceFileId);
        $this->assertSame('synthetic.lzh', $package->originalFilename);
        $runner = $package->records['kol_den2.kd3'][0];
        $this->assertSame('01', $runner->fields['venue_code']);
        $this->assertSame('0000001', $runner->fields['horse_code']);
        $this->assertSame(42, $runner->sourceFileId);
        $this->assertCount(3, iterator_to_array($package->records()));
    }

    public function test_rejects_size_remainder_and_missing_expected_file(): void
    {
        $files = $this->hb();
        $files['kol_den1.kd3'] .= 'x';
        $this->assertFailure('physical_layout', fn () => $this->parse('hb', $files), 'kol_den1.kd3');
        unset($files['kol_den2.kd3']);
        $this->assertFailure('lzh', fn () => $this->parse('hb', $files));
    }

    public function test_rejects_invalid_date_and_numeric_with_field_diagnostic(): void
    {
        $files = $this->hb();
        $files['kol_den2.kd3'] = $this->raceRecord('kol_den2.kd3', '20260230', '0000001');
        $this->assertFailure('field_validation', fn () => $this->parse('hb', $files), 'kol_den2.kd3', 1, 'race_date', 12);

        $files = $this->hb();
        $files['kol_den1.kd3'] = $this->raceRecord('kol_den1.kd3', count: 'A1');
        $this->assertFailure('field_validation', fn () => $this->parse('hb', $files), 'kol_den1.kd3', 1, 'runner_count', 337);
    }

    public function test_hb_rejects_count_mismatch_and_duplicate_keys(): void
    {
        $files = $this->hb();
        $files['kol_den1.kd3'] = $this->raceRecord('kol_den1.kd3', count: '02');
        $this->assertFailure('cross_file_validation', fn () => $this->parse('hb', $files), field: 'runner_count');

        $files = $this->hb();
        $files['kol_den1.kd3'] .= $files['kol_den1.kd3'];
        $this->assertFailure('cross_file_validation', fn () => $this->parse('hb', $files), field: 'race_no');

        $files = $this->hb();
        $files['kol_den2.kd3'] .= $files['kol_den2.kd3'];
        $this->assertFailure('cross_file_validation', fn () => $this->parse('hb', $files), field: 'horse_code');

        $files = $this->hb();
        $files['kol_uma.kd3'] .= $files['kol_uma.kd3'];
        $this->assertFailure('cross_file_validation', fn () => $this->parse('hb', $files), field: 'horse_code');
    }

    public function test_ib_validates_counts_and_horse_references_while_sei3_is_optional(): void
    {
        $this->assertSame(3, $this->parse('ib', $this->ib())->recordCount);
        $withSei3 = $this->ib();
        $withSei3['kol_sei3.kd3'] = $this->raceRecord('kol_sei3.kd3');
        $this->assertSame(4, $this->parse('ib', $withSei3)->recordCount);

        $files = $this->ib();
        $files['kol_sei1.kd3'] = $this->raceRecord('kol_sei1.kd3', count: '02');
        $this->assertFailure('cross_file_validation', fn () => $this->parse('ib', $files), field: 'runner_count');

        $files = $this->ib();
        $files['kol_uma.kd3'] = $this->horse('0000002');
        $this->assertFailure('cross_file_validation', fn () => $this->parse('ib', $files), field: 'horse_code');
    }

    public function test_optional_publication_files_may_contain_zero_records(): void
    {
        $this->assertSame(0, $this->parse('jb', ['kol_ods.kd3' => '', 'kol_ods2.kd3' => ''])->recordCount);
        $this->assertSame(0, $this->parse('mb', ['kol_com1.kd3' => ''])->recordCount);
    }

    public function test_comment_record_may_reference_a_prior_race_date(): void
    {
        $comment = $this->raceRecord('kol_com1.kd3', '20260725');

        $this->assertSame(1, $this->parse('mb', ['kol_com1.kd3' => $comment])->recordCount);
    }

    /** @param array<string, string> $files */
    private function parse(string $artifact, array $files): Kd3ParsedPackage
    {
        Storage::fake('kd3-acceptance');
        Storage::disk('kd3-acceptance')->put('source.lzh', 'archive');
        $extractor = new class($files) implements Kd3LzhExtractor
        {
            /** @param array<string, string> $files */
            public function __construct(private readonly array $files) {}

            public function extract(string $archive, string $directory): array
            {
                foreach ($this->files as $name => $contents) {
                    file_put_contents($directory.'/'.$name, $contents);
                }

                return array_keys($this->files);
            }
        };
        $source = (object) [
            'id' => 42, 'source_system' => 'kd3', 'storage_disk' => 'kd3-acceptance',
            'storage_path' => 'source.lzh', 'size_bytes' => 7, 'sha256' => hash('sha256', 'archive'),
            'race_date' => '2026-09-05', 'artifact_type' => $artifact, 'original_filename' => 'synthetic.lzh',
        ];

        return (new Kd3Parser($extractor, new Kd3FixedWidthReader, new Kd3LayoutRegistry, new Kd3FieldDecoder))->parse($source);
    }

    /** @return array<string, string> */
    private function hb(): array
    {
        return ['kol_den1.kd3' => $this->raceRecord('kol_den1.kd3', count: '01'), 'kol_den2.kd3' => $this->raceRecord('kol_den2.kd3', horse: '0000001'), 'kol_uma.kd3' => $this->horse('0000001')];
    }

    /** @return array<string, string> */
    private function ib(): array
    {
        return ['kol_sei1.kd3' => $this->raceRecord('kol_sei1.kd3', count: '01'), 'kol_sei2.kd3' => $this->raceRecord('kol_sei2.kd3', horse: '0000001'), 'kol_uma.kd3' => $this->horse('0000001')];
    }

    private function raceRecord(string $file, string $date = '20260905', ?string $horse = null, ?string $count = null): string
    {
        $layout = (new Kd3LayoutRegistry)->get($file);
        $body = substr_replace(str_repeat(' ', $layout['record_length'] - 2), '012026010105'.$date, 0, 20);
        if ($horse !== null) {
            $offset = $file === 'kol_den2.kd3' ? 25 : 27;
            $body = substr_replace($body, $horse, $offset, 7);
        }
        if ($count !== null) {
            $offset = $file === 'kol_den1.kd3' ? 337 : 366;
            $body = substr_replace($body, $count, $offset, 2);
        }

        return $body."\r\n";
    }

    private function horse(string $code): string
    {
        return substr_replace(str_repeat(' ', 5164), $code, 0, 7)."\r\n";
    }

    private function assertFailure(string $category, callable $callback, ?string $file = null, ?int $record = null, ?string $field = null, ?int $offset = null): void
    {
        try {
            $callback();
        } catch (Kd3ParseException $exception) {
            $this->assertSame($category, $exception->category);
            if ($file !== null) {
                $this->assertSame($file, $exception->fileName);
            }
            if ($record !== null) {
                $this->assertSame($record, $exception->recordNumber);
            }
            if ($field !== null) {
                $this->assertSame($field, $exception->field);
            }
            if ($offset !== null) {
                $this->assertSame($offset, $exception->byteOffset);
            }

            return;
        }
        $this->fail('Expected KD3 parse failure.');
    }
}
