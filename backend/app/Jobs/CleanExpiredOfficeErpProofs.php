<?php

namespace App\Jobs;

use App\Erp\Commercial\OfficeErpReceiptProofs;
use Carbon\CarbonImmutable;

final class CleanExpiredOfficeErpProofs
{
    public function handle(OfficeErpReceiptProofs $proofs): void
    {
        $proofs->cleanExpired(CarbonImmutable::now());
    }
}
