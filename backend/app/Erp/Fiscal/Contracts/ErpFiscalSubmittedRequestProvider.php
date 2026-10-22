<?php

namespace App\Erp\Fiscal\Contracts;

interface ErpFiscalSubmittedRequestProvider
{
    public function submittedRequestXml(): ?string;
}
