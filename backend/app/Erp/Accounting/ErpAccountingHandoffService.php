<?php

namespace App\Erp\Accounting;

use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\FiscalDocument;
use App\Services\BrazilianTaxId;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ErpAccountingHandoffService
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private BrazilianTaxId $taxId) {}

    public function ensureDeliveryIntent(ErpFiscalDocument $document): ?ErpAccountingDelivery
    {
        $delegation = ErpOfficeDelegation::query()
            ->where('erp_company_id', $document->erp_company_id)
            ->where('status', 'active')
            ->first();
        if ($delegation === null) {
            return null;
        }

        return ErpAccountingDelivery::query()->firstOrCreate(
            [
                'erp_company_id' => $document->erp_company_id,
                'erp_fiscal_document_id' => $document->id,
                'account_id' => $delegation->account_id,
            ],
            [
                'status' => ErpAccountingDelivery::STATUS_PENDING,
                'attempts' => 0,
            ],
        );
    }

    public function deliver(ErpAccountingDelivery $delivery): ErpAccountingDelivery
    {
        return DB::transaction(function () use ($delivery): ErpAccountingDelivery {
            /** @var ErpAccountingDelivery $locked */
            $locked = ErpAccountingDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ErpAccountingDelivery::STATUS_DELIVERED) {
                return $locked;
            }

            $document = ErpFiscalDocument::query()->findOrFail($locked->erp_fiscal_document_id);
            $company = ErpCompany::query()->whereKey($locked->erp_company_id)->lockForUpdate()->firstOrFail();

            if ($company->status !== 'active') {
                return $this->markBlocked($locked, 'company_inactive');
            }

            $delegation = ErpOfficeDelegation::query()
                ->where('erp_company_id', $company->id)
                ->where('account_id', $locked->account_id)
                ->where('status', 'active')
                ->first();
            if ($delegation === null) {
                return $this->markBlocked($locked, 'delegation_revoked');
            }

            if ($locked->attempts >= self::MAX_ATTEMPTS) {
                return $this->markFailed($locked, 'max_attempts_exceeded', retryable: false);
            }

            $client = $this->resolveMappedClient($delegation, $company);
            if ($client === null) {
                return $this->markBlocked($locked, 'client_mapping_missing');
            }

            if ($document->status !== ErpFiscalDocumentStateMachine::AUTHORIZED) {
                return $this->markBlocked($locked, 'fiscal_document_not_authorized');
            }

            if ($document->access_key === null
                || $document->storage_path === null
                || $document->artifact_sha256 === null
                || $document->artifact_byte_length === null) {
                return $this->markFailed($locked, 'artifact_incomplete', retryable: true);
            }

            if (! Storage::disk('local')->exists($document->storage_path)) {
                return $this->markFailed($locked, 'storage_unavailable', retryable: true);
            }

            $officeDocument = $this->publishToOffice($locked, $document, $client, $company);

            $locked->update([
                'client_id' => $client->id,
                'fiscal_document_id' => $officeDocument->id,
                'status' => ErpAccountingDelivery::STATUS_DELIVERED,
                'reason_code' => null,
                'delivered_at' => now(),
            ]);

            return $locked->fresh(['erpFiscalDocument']);
        });
    }

    public function retry(ErpAccountingDelivery $delivery): ErpAccountingDelivery
    {
        return DB::transaction(function () use ($delivery): ErpAccountingDelivery {
            /** @var ErpAccountingDelivery $locked */
            $locked = ErpAccountingDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ErpAccountingDelivery::STATUS_DELIVERED) {
                return $locked;
            }
            if ($locked->status === ErpAccountingDelivery::STATUS_BLOCKED) {
                return $locked;
            }

            $locked->increment('attempts');

            return $this->deliver($locked->fresh());
        });
    }

    private function resolveMappedClient(ErpOfficeDelegation $delegation, ErpCompany $company): ?Client
    {
        $normalizedCnpj = $this->taxId->normalize($company->cnpj);

        foreach (Client::withoutGlobalScope('account')->where('account_id', $delegation->account_id)->cursor() as $client) {
            if ($this->taxId->normalize((string) ($client->tax_id ?? '')) === $normalizedCnpj) {
                return $client;
            }
        }

        return null;
    }

    private function publishToOffice(
        ErpAccountingDelivery $delivery,
        ErpFiscalDocument $document,
        Client $client,
        ErpCompany $company,
    ): FiscalDocument {
        $existing = FiscalDocument::withoutGlobalScope('account')
            ->where('client_id', $client->id)
            ->where('chave_acesso', $document->access_key)
            ->where('event_id', '')
            ->first();
        if ($existing !== null) {
            abort_unless((int) $existing->account_id === (int) $delivery->account_id, 422, 'Documento fiscal já publicado em outro escritório');

            return $existing;
        }

        $model = FiscalModel::fromDocumentModel($document->model) ?? FiscalModel::Nfe;

        return resolve(CurrentTenant::class)->run((int) $delivery->account_id, function () use ($delivery, $client, $document, $company, $model): FiscalDocument {
            return FiscalDocument::withoutGlobalScope('account')->create([
                'account_id' => $delivery->account_id,
                'client_id' => $client->id,
                'source' => FiscalSource::ErpIssued,
                'model' => $model,
                'kind' => FiscalKind::Document,
                'stage' => FiscalStage::Document,
                'chave_acesso' => $document->access_key,
                'event_id' => '',
                'nsu' => (int) $document->id,
                'emitente_cnpj' => $this->taxId->normalize($company->cnpj ?? ''),
                'destinatario_cnpj' => null,
                'valor_total' => $document->commercial_snapshot['total_amount'] ?? null,
                'emissao_at' => $document->authorized_at,
                'storage_path' => $document->storage_path,
                'sha256' => $document->artifact_sha256,
                'xml_bytes' => (int) $document->artifact_byte_length,
                'mascarado' => false,
                'captured_at' => now(),
            ]);
        });
    }

    private function markBlocked(ErpAccountingDelivery $delivery, string $reason): ErpAccountingDelivery
    {
        $delivery->update([
            'status' => ErpAccountingDelivery::STATUS_BLOCKED,
            'reason_code' => $reason,
        ]);

        return $delivery->fresh();
    }

    private function markFailed(ErpAccountingDelivery $delivery, string $reason, bool $retryable): ErpAccountingDelivery
    {
        $delivery->update([
            'status' => $retryable ? ErpAccountingDelivery::STATUS_FAILED : ErpAccountingDelivery::STATUS_BLOCKED,
            'reason_code' => $reason,
        ]);

        return $delivery->fresh();
    }
}
