<?php

namespace App\Kd3\Domain;

use RuntimeException;

final class Kd3ImportException extends RuntimeException
{
    public function __construct(string $message, public readonly string $category, public readonly ?string $entity = null, public readonly ?string $key = null)
    {
        parent::__construct($message);
    }
}
