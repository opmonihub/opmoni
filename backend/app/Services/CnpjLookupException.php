<?php

namespace App\Services;

use RuntimeException;

final class CnpjLookupException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }
}
