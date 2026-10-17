<?php

namespace App\Erp\Fiscal\Contracts;

use App\Erp\Fiscal\ErpFiscalProviderResult;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;

interface ErpFiscalProvider
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function transmit(ErpFiscalDocument $document, array $payload): ErpFiscalProviderResult;

    public function consult(ErpFiscalDocument $document): ErpFiscalProviderResult;

    public function submitEvent(ErpFiscalDocument $document, ErpFiscalEvent $event): ErpFiscalProviderResult;

    public function submitInvalidation(ErpFiscalInvalidation $invalidation): ErpFiscalProviderResult;
}
