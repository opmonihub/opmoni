<?php

namespace App\Erp\Fiscal;

/**
 * Supported fiscal models, events and API routes for acceptance inventory.
 *
 * @return list<array{model: string, action: string, route: string, fixture: string}>
 */
final class ErpFiscalActionCatalog
{
    /** @return list<array{model: string, action: string, route: string, fixture: string}> */
    public static function rows(): array
    {
        $document = '/api/v1/erp/fiscal/documents/{document}';

        return [
            ['model' => '*', 'action' => 'configuration_show', 'route' => 'GET /api/v1/erp/fiscal/configuration/{establishment}', 'fixture' => 'ErpFiscalIssuerConfigurationTest'],
            ['model' => '*', 'action' => 'configuration_update', 'route' => 'PATCH /api/v1/erp/fiscal/configuration/{establishment}', 'fixture' => 'ErpFiscalIssuerConfigurationTest'],
            ['model' => '*', 'action' => 'certificate_index', 'route' => 'GET /api/v1/erp/fiscal/certificates', 'fixture' => 'ErpFiscalIssuerConfigurationTest'],
            ['model' => '*', 'action' => 'certificate_show', 'route' => 'GET /api/v1/erp/fiscal/certificates/{certificate}', 'fixture' => 'ErpFiscalIssuerConfigurationTest'],
            ['model' => '*', 'action' => 'certificate_store', 'route' => 'POST /api/v1/erp/fiscal/certificates', 'fixture' => 'ErpFiscalIssuerConfigurationTest'],
            ['model' => '*', 'action' => 'certificate_rotate', 'route' => 'POST /api/v1/erp/fiscal/certificates/{certificate}/rotate', 'fixture' => 'ErpFiscalIssuerConfigurationTest'],
            ['model' => '55', 'action' => 'create', 'route' => 'POST /api/v1/erp/fiscal/documents', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '55', 'action' => 'queue', 'route' => 'POST '.$document.'/queue', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '55', 'action' => 'transmit', 'route' => 'POST '.$document.'/transmit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '55', 'action' => 'consult', 'route' => 'POST '.$document.'/consult', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '55', 'action' => 'download_xml', 'route' => 'GET '.$document.'/xml', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '55', 'action' => 'download_pdf', 'route' => 'GET '.$document.'/pdf', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '55', 'action' => 'cancel', 'route' => 'POST /api/v1/erp/fiscal/events + submit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '55', 'action' => 'correct', 'route' => 'POST /api/v1/erp/fiscal/events + submit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '55', 'action' => 'invalidate', 'route' => 'POST /api/v1/erp/fiscal/invalidations + submit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '65', 'action' => 'transmit', 'route' => 'POST '.$document.'/transmit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '65', 'action' => 'contingency', 'route' => 'POST '.$document.'/contingency', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '65', 'action' => 'reconcile_contingency', 'route' => 'POST '.$document.'/reconcile-contingency', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '57', 'action' => 'transmit', 'route' => 'POST '.$document.'/transmit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '57', 'action' => 'complement', 'route' => 'POST /api/v1/erp/fiscal/events + submit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '57', 'action' => 'substitution', 'route' => 'POST /api/v1/erp/fiscal/events + submit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '58', 'action' => 'transmit', 'route' => 'POST '.$document.'/transmit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '58', 'action' => 'close', 'route' => 'POST /api/v1/erp/fiscal/events + submit', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '*', 'action' => 'certificate_upload', 'route' => 'POST /api/v1/erp/fiscal/certificates', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '*', 'action' => 'certificate_rotate', 'route' => 'POST /api/v1/erp/fiscal/certificates/{certificate}/rotate', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
            ['model' => '*', 'action' => 'return_note', 'route' => 'POST /api/v1/erp/fiscal/documents (model 55 devolucao)', 'fixture' => 'ErpFiscalIssuanceCompleteTest'],
        ];
    }
}
