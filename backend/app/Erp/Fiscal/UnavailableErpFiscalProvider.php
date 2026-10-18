<?php

namespace App\Erp\Fiscal;

use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;

final class UnavailableErpFiscalProvider implements ErpFiscalProvider
{
    public function transmit(ErpFiscalDocument $document, array $payload): ErpFiscalProviderResult
    {
        abort(503, 'Emissão fiscal indisponível: provedor e reconciliação durável ainda não implementados');
    }

    public function consult(ErpFiscalDocument $document): ErpFiscalProviderResult
    {
        abort(503, 'Consulta fiscal indisponível: provedor não implementado');
    }

    public function submitEvent(ErpFiscalDocument $document, ErpFiscalEvent $event): ErpFiscalProviderResult
    {
        abort(503, 'Evento fiscal indisponível: provedor não implementado');
    }

    public function submitInvalidation(ErpFiscalInvalidation $invalidation): ErpFiscalProviderResult
    {
        abort(503, 'Inutilização fiscal indisponível: provedor não implementado');
    }
}
