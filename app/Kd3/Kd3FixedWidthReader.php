<?php
namespace App\Kd3;

final class Kd3FixedWidthReader
{
    /** @return iterable<int, string> */
    public function records(string $path, int $recordLength, string $fileName = ''): iterable
    {
        $size = filesize($path);
        if ($size === false || $size % $recordLength !== 0) {
            throw new Kd3ParseException('File size is not divisible by record length.', 'physical_layout', $fileName);
        }
        $handle = fopen($path, 'rb');
        if ($handle === false) throw new Kd3ParseException('Unable to read extracted file.', 'lzh', $fileName);
        try {
            $number = 0;
            while (! feof($handle)) {
                $record = fread($handle, $recordLength);
                if ($record === '' || $record === false) break;
                $number++;
                if (strlen($record) !== $recordLength || substr($record, -2) !== "\r\n") {
                    throw new Kd3ParseException('Record length or CRLF is invalid.', 'physical_layout', $fileName, $number, ($number - 1) * $recordLength);
                }
                yield $number => substr($record, 0, -2);
            }
        } finally { fclose($handle); }
    }
}
