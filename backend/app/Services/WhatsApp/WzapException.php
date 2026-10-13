<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/**
 * Falha do gateway wzap. A mensagem leva só código e texto do envelope de
 * erro — nunca a apikey, bytes de documento ou corpo de mensagem.
 */
final class WzapException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $httpStatus = 0,
        private readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function errorCode(): ?string
    {
        return $this->errorCode;
    }
}
