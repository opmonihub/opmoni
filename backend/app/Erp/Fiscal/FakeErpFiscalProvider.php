<?php

namespace App\Erp\Fiscal;

use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;

final class FakeErpFiscalProvider implements ErpFiscalProvider
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function transmit(ErpFiscalDocument $document, array $payload): ErpFiscalProviderResult
    {
        $key = $document->idempotency_key;
        if (str_contains($key, 'force-reject')) {
            return ErpFiscalProviderResult::rejected('Rejeição simulada do provedor.');
        }
        if (str_contains($key, 'force-indeterminate')) {
            return ErpFiscalProviderResult::indeterminate(receipt: 'RCPT-'.$document->access_key, message: 'Timeout simulado');
        }

        $contingency = is_array($payload['contingency'] ?? null) ? $payload['contingency'] : [];
        if (($contingency['contingency_mode'] ?? false) === true) {
            return ErpFiscalProviderResult::indeterminate(message: 'Contingência local não consulta SEFAZ neste fixture.');
        }

        return ErpFiscalProviderResult::authorized(
            'PROT-'.substr($document->access_key ?? '000', -8),
            $this->sampleXml($document, $payload),
            'RCPT-'.$document->access_key,
        );
    }

    public function consult(ErpFiscalDocument $document): ErpFiscalProviderResult
    {
        $metadata = is_array($document->metadata) ? $document->metadata : [];
        $receipt = $metadata['provider_receipt'] ?? null;
        if ($document->status === ErpFiscalDocumentStateMachine::INDETERMINATE && is_string($receipt) && $receipt !== '') {
            return ErpFiscalProviderResult::authorized(
                'PROT-CONSULT-'.substr($document->access_key ?? '000', -8),
                $this->sampleXml($document, []),
                $receipt,
            );
        }

        if ($document->status === ErpFiscalDocumentStateMachine::AUTHORIZED) {
            return ErpFiscalProviderResult::authorized(
                'PROT-'.substr($document->access_key ?? '000', -8),
                $this->sampleXml($document, []),
            );
        }

        return ErpFiscalProviderResult::rejected('Documento não autorizado na consulta simulada.');
    }

    public function submitEvent(ErpFiscalDocument $document, ErpFiscalEvent $event): ErpFiscalProviderResult
    {
        if (str_contains($event->idempotency_key, 'force-reject')) {
            return ErpFiscalProviderResult::eventRejected('Evento rejeitado pelo provedor simulado.');
        }

        return ErpFiscalProviderResult::eventAccepted('EVT-'.substr($document->access_key ?? '000', -6));
    }

    public function submitInvalidation(ErpFiscalInvalidation $invalidation): ErpFiscalProviderResult
    {
        if (str_contains($invalidation->idempotency_key, 'force-reject')) {
            return ErpFiscalProviderResult::eventRejected('Inutilização rejeitada pelo provedor simulado.');
        }

        return ErpFiscalProviderResult::eventAccepted('INUT-'.substr((string) $invalidation->series, 0, 3).$invalidation->number_from);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sampleXml(ErpFiscalDocument $document, array $payload): string
    {
        $chave = $document->access_key ?? '00000000000000000000000000000000000000000000';
        $mod = $document->model;

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">
          <NFe>
            <infNFe Id="NFe{$chave}" versao="4.00">
              <ide><mod>{$mod}</mod></ide>
            </infNFe>
          </NFe>
          <protNFe versao="4.00"><infProt><chNFe>{$chave}</chNFe></infProt></protNFe>
        </nfeProc>
        XML;
    }
}
