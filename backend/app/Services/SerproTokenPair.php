<?php

namespace App\Services;

final readonly class SerproTokenPair
{
    public function __construct(
        private string $accessToken,
        private string $jwtToken,
        private int $expiresIn,
    ) {}

    public function accessToken(): string
    {
        return $this->accessToken;
    }

    public function jwtToken(): string
    {
        return $this->jwtToken;
    }

    public function ttl(): int
    {
        $margin = (int) config('integra-contador.token_margin', 300);

        return max(60, $this->expiresIn - $margin);
    }
}
