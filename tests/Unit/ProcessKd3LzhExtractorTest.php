<?php

namespace Tests\Unit;

use App\Kd3\ProcessKd3LzhExtractor;
use Symfony\Component\Process\Process;
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
        file_put_contents($directory.'/kol_com1.kd3', "synthetic\r\n");
        $archive = $directory.'/input.lzh';
        (new Process(['/usr/bin/lha', 'a', $archive, $directory.'/kol_com1.kd3']))->mustRun();
        $output = $directory.'/out';
        mkdir($output, 0700);
        config(['kd3.lzh_command' => '/usr/bin/lha']);
        $files = (new ProcessKd3LzhExtractor)->extract($archive, $output);
        $this->assertSame(['kol_com1.kd3'], $files);
        $this->assertSame("synthetic\r\n", file_get_contents($output.'/kol_com1.kd3'));
    }
}
