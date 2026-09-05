<?php

namespace App\Kd3;

use RuntimeException;
use Throwable;

class Kd3Exception extends RuntimeException
{
    public function __construct(
        public readonly string $category,
        public readonly ?int $httpStatus = null,
        string $message = 'KD3 download failed.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
