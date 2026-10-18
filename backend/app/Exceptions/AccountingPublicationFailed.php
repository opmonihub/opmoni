<?php

namespace App\Exceptions;

use RuntimeException;

class AccountingPublicationFailed extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'publication_failed')
    {
        parent::__construct($message);
    }
}
