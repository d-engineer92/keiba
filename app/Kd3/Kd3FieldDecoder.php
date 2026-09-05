<?php
namespace App\Kd3;

final class Kd3FieldDecoder
{
    public function decode(string $record, int $offset, int $length, string $field, bool $nullable = true, string $trim = 'right'): ?string
    {
        $bytes = substr($record, $offset, $length);
        if (strlen($bytes) !== $length) throw new Kd3ParseException('Field exceeds record.', 'field_validation', null, null, $offset, $field);
        $bytes = $trim === 'both' ? trim($bytes) : ($trim === 'left' ? ltrim($bytes) : rtrim($bytes));
        if ($bytes === '' && $nullable) return null;
        if (! mb_check_encoding($bytes, 'SJIS-win')) throw new Kd3ParseException('Invalid CP932 field.', 'decode', null, null, $offset, $field);
        $value = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        return $value;
    }
}
