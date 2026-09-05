<?php

namespace App\Kd3;

final class Kd3FieldDecoder
{
    public function decode(string $record, int $offset, int $length, string $field, bool $nullable = true, string $trim = 'right'): ?string
    {
        $bytes = substr($record, $offset, $length);
        if (strlen($bytes) !== $length) {
            throw new Kd3ParseException('Field exceeds record.', 'field_validation', null, null, $offset, $field);
        }
        $bytes = $trim === 'both' ? trim($bytes) : ($trim === 'left' ? ltrim($bytes) : rtrim($bytes));
        if ($bytes === '' && $nullable) {
            return null;
        }
        if (! mb_check_encoding($bytes, 'SJIS-win')) {
            throw new Kd3ParseException('Invalid CP932 field.', 'decode', null, null, $offset, $field);
        }
        $value = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');

        return $value;
    }

    /** @param array{offset:int,length:int,type:string,nullable:bool,trim:string} $definition */
    public function typed(string $record, string $field, array $definition, string $file, int $recordNumber): string|int|null
    {
        $value = $this->decode($record, $definition['offset'], $definition['length'], $field, $definition['nullable'], $definition['trim']);
        if ($value === null || $definition['type'] === 'code') {
            return $value;
        }
        if ($definition['type'] === 'numeric' && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value;
        }
        if ($definition['type'] === 'date' && preg_match('/^[0-9]{8}$/', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Ymd', $value);
            if ($date !== false && $date->format('Ymd') === $value) {
                return $value;
            }
        }
        throw new Kd3ParseException('Invalid field value.', 'field_validation', $file, $recordNumber, $definition['offset'], $field);
    }
}
