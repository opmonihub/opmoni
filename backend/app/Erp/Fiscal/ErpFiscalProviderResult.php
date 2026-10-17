<?php

namespace App\Erp\Fiscal;

final class ErpFiscalProviderResult
{
    /**
     * @param  array<string, mixed>|null  $providerResponse
     */
    public function __construct(
        public readonly string $outcome,
        public readonly ?string $protocol = null,
        public readonly ?string $receipt = null,
        public readonly ?string $xml = null,
        public readonly ?string $message = null,
        public readonly ?array $providerResponse = null,
    ) {}

    public static function authorized(string $protocol, string $xml, ?string $receipt = null): self
    {
        return new self('authorized', $protocol, $receipt, $xml);
    }

    public static function rejected(string $message, ?array $providerResponse = null): self
    {
        return new self('rejected', message: $message, providerResponse: $providerResponse);
    }

    public static function indeterminate(?string $receipt = null, ?string $message = null): self
    {
        return new self('indeterminate', receipt: $receipt, message: $message);
    }

    public static function eventAccepted(string $protocol, ?array $providerResponse = null): self
    {
        return new self('event_accepted', $protocol, providerResponse: $providerResponse);
    }

    public static function eventRejected(string $message, ?array $providerResponse = null): self
    {
        return new self('event_rejected', message: $message, providerResponse: $providerResponse);
    }
}
