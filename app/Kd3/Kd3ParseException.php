<?php

namespace App\Kd3;

use RuntimeException;

final class Kd3ParseException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly ?string $fileName = null,
        public readonly ?int $recordNumber = null,
        public readonly ?int $byteOffset = null,
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }
}
