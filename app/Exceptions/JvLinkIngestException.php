<?php

namespace App\Exceptions;

use RuntimeException;

class JvLinkIngestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $category,
        public readonly bool $retryable,
    ) {
        parent::__construct($message);
    }
}
