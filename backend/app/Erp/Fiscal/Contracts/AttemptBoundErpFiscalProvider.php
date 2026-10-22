<?php

namespace App\Erp\Fiscal\Contracts;

use App\Models\Erp\Fiscal\ErpFiscalAttempt;

interface AttemptBoundErpFiscalProvider
{
    public function forAttempt(ErpFiscalAttempt $attempt, string $signedXml): ErpFiscalProvider;
}
