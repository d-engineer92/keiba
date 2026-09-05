<?php

namespace Tests\Unit;

use App\Kd3\ProcessKd3LzhExtractor;
use Tests\TestCase;

class ProcessKd3LzhExtractorTest extends TestCase
{
    public function test_extracts_a_synthetic_lzh_after_preflight(): void
    {
        if (! is_executable('/usr/bin/lha')) {
            $this->markTestSkipped('lhasa is exercised by the Docker CI image.');
        }
        $directory = sys_get_temp_dir().'/kd3-lzh-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $archive = $directory.'/input.lzh';
        $encoded = file_get_contents(base_path('tests/Fixtures/synthetic-three-files.lzh.base64'));
        $this->assertNotFalse($encoded);
        file_put_contents($archive, base64_decode(trim($encoded), true));
        $output = $directory.'/out';
        mkdir($output, 0700);
        config(['kd3.lzh_command' => '/usr/bin/lha']);
        $files = (new ProcessKd3LzhExtractor)->extract($archive, $output);
        sort($files);
        $this->assertSame(['kol_den1.kd3', 'kol_den2.kd3', 'kol_uma.kd3'], $files);
        $this->assertSame("abc\r\n", file_get_contents($output.'/kol_den1.kd3'));
    }
}
