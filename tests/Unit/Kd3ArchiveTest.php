<?php

namespace Tests\Unit;

use App\Kd3\Kd3Archive;
use App\Kd3\Kd3Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SyntheticKd3;

class Kd3ArchiveTest extends TestCase
{
    public function test_extracts_exactly_one_expected_flat_lzh_entry(): void
    {
        $result = (new Kd3Archive)->extract(SyntheticKd3::zip('kd3_hb260905.lzh', 'synthetic-lzh'), '/^kd3_hb[0-9]+\.lzh$/i');

        $this->assertNotNull($result);
        $this->assertSame('kd3_hb260905.lzh', $result['name']);
        $this->assertSame('synthetic-lzh', $result['contents']);
    }

    public function test_extracts_from_a_zip_file_without_requiring_zip_bytes(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'kd3-archive-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, SyntheticKd3::zip('kd3_hb260905.lzh', 'file-backed-lzh'));

        try {
            $result = (new Kd3Archive)->extractFile($path, '/^kd3_hb[0-9]+\.lzh$/i');
        } finally {
            @unlink($path);
        }

        $this->assertNotNull($result);
        $this->assertSame('kd3_hb260905.lzh', $result['name']);
        $this->assertSame('file-backed-lzh', $result['contents']);
    }

    #[DataProvider('invalidArchives')]
    public function test_rejects_invalid_or_ambiguous_archives(string $bytes): void
    {
        $this->expectException(Kd3Exception::class);
        (new Kd3Archive)->extract($bytes, '/^kd3_hb[0-9]+\.lzh$/i');
    }

    public static function invalidArchives(): array
    {
        return [
            'not a zip' => ['<html>login</html>'],
            'corrupt zip' => ["PK\x03\x04corrupt"],
            'empty entry' => [SyntheticKd3::zip('kd3_hb260905.lzh', '')],
        ];
    }

    public function test_missing_artifact_in_a_valid_bundle_is_not_available(): void
    {
        $result = (new Kd3Archive)->extract(SyntheticKd3::zip('kd3_ib260905.lzh', 'synthetic'), '/^kd3_hb[0-9]+\.lzh$/i');

        $this->assertNull($result);
    }
}
