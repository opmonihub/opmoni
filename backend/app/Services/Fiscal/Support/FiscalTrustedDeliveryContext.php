<?php

namespace App\Services\Fiscal\Support;

use App\Enums\FiscalSource;
use Carbon\CarbonImmutable;

/**
 * Evidence stamped only by an authenticated provider capture for an explicit
 * Account, client, source and queried tax id.
 */
final readonly class FiscalTrustedDeliveryContext
{
    public function __construct(
        public int $accountId,
        public int $clientId,
        public FiscalSource $source,
        public string $environment,
        public string $queriedTaxId,
        public CarbonImmutable $receivedAt,
        public string $correlationId,
    ) {}
}
