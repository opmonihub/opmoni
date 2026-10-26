<?php

namespace App\Erp\Accounting;

use App\Enums\ClientStatus;
use App\Enums\FiscalDeliveryOrigin;
use App\Enums\FiscalKind;
use App\Enums\FiscalModel;
use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Erp\ErpCommercialGate;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Exceptions\AccountingPublicationFailed;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\Accounting\ErpAccountingDeliveryDispatch;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\FiscalDocument;
use App\Services\BrazilianTaxId;
use App\Services\Fiscal\Capture\FiscalXmlPath;
use App\Services\Fiscal\Processing\FiscalProcessingCoordinator;
use App\Services\Fiscal\Read\FiscalDocumentReadiness;
use App\Support\AccessTransaction;
use App\Support\ErpCompanyContext;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;

class ErpAccountingHandoffService
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private BrazilianTaxId $taxId) {}

    public function ensureDeliveryIntent(ErpFiscalDocument $document, ?int $requestedByUserId = null): ?ErpAccountingDelivery
    {
        return $this->ensureProjectionIntent($document, $requestedByUserId);
    }

    public function ensureEventDeliveryIntent(ErpFiscalDocument $document, ErpFiscalEvent $event, ?int $requestedByUserId = null): ?ErpAccountingDelivery
    {
        abort_unless((int) $event->erp_fiscal_document_id === (int) $document->id && $event->status === 'accepted' && $event->accepted_event_identity !== null, 409);

        return $this->ensureProjectionIntent($document, $requestedByUserId, $event);
    }

    private function ensureProjectionIntent(ErpFiscalDocument $document, ?int $requestedByUserId, ?ErpFiscalEvent $event = null): ?ErpAccountingDelivery
    {
        return DB::transaction(function () use ($document, $requestedByUserId, $event): ?ErpAccountingDelivery {
            $document = ErpFiscalDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail();
            $delegation = ErpOfficeDelegation::query()->where('erp_company_id', $document->erp_company_id)->where('status', 'active')->first();
            if ($delegation === null) {
                return null;
            }
            $mapping = ErpAccountingMapping::query()->where('delegation_id', $delegation->id)
                ->where('erp_establishment_id', $document->erp_establishment_id)->where('status', 'active')->first();
            $identity = [
                'erp_company_id' => $document->erp_company_id,
                'erp_fiscal_document_id' => $document->id,
                'account_id' => $delegation->account_id,
                'delegation_id' => $delegation->id,
                'projection_identity' => $event === null ? 'document' : 'event:'.$event->id,
            ];
            $existing = ErpAccountingDelivery::query()->where($identity)->where('mapping_id', $mapping?->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            if ($mapping !== null) {
                $placeholder = ErpAccountingDelivery::query()->where($identity)->whereNull('mapping_id')
                    ->where(function ($query): void {
                        $query->whereIn('status', ['pending', 'failed'])
                            ->orWhere(fn ($query) => $query->where('status', 'blocked')->whereIn('reason_code', ['client_mapping_missing', 'mapping_pending_approval', 'fiscal_document_not_authorized', 'fiscal_authorization_unverified']));
                    })->first();
                if ($placeholder !== null) {
                    return $placeholder;
                }
            }

            return ErpAccountingDelivery::query()->firstOrCreate($identity + ['mapping_id' => $mapping?->id], [
                'erp_fiscal_event_id' => $event?->id,
                'mapping_revision' => $mapping?->revision,
                'requested_by_user_id' => $requestedByUserId,
                'status' => ErpAccountingDelivery::STATUS_PENDING,
                'attempts' => 0,
            ]);
        });
    }

    public function queueAfterCommit(ErpAccountingDelivery $delivery, bool $allowNextGeneration = false): ErpAccountingDelivery
    {
        if ($delivery->status !== ErpAccountingDelivery::STATUS_PENDING) {
            return $delivery;
        }
        $connection = (string) config('queue.default');
        if (! in_array(config('queue.connections.'.$connection.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            $delivery->update(['reason_code' => 'accounting_worker_not_configured']);
        }

        $dispatch = DB::transaction(function () use ($delivery, $allowNextGeneration): ?ErpAccountingDeliveryDispatch {
            $locked = ErpAccountingDelivery::query()->whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $latest = ErpAccountingDeliveryDispatch::query()
                ->where('erp_accounting_delivery_id', $locked->id)
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->first();
            if ($latest !== null && ! $allowNextGeneration) {
                return null;
            }

            return ErpAccountingDeliveryDispatch::query()->create([
                'erp_accounting_delivery_id' => $locked->id,
                'generation' => ($latest?->generation ?? 0) + 1,
                'status' => 'pending',
                'available_at' => now(),
                'attempt_sequence' => 1,
            ]);
        });
        if ($dispatch !== null) {
            resolve(ErpAccountingDispatchOutbox::class)->scheduleWake($dispatch);
        }

        return $delivery;
    }

    public function deliver(
        ErpAccountingDelivery $delivery,
        ?int $dispatchId = null,
        ?int $attemptSequence = null,
        ?int $dispatchGeneration = null,
        ?string $dispatchLeaseToken = null,
    ): ErpAccountingDelivery {
        return $this->withFences($delivery, function (ErpAccountingDelivery $locked, ErpCompany $company, ?Account $account) use ($dispatchId, $attemptSequence, $dispatchGeneration, $dispatchLeaseToken): ErpAccountingDelivery {
            $dispatch = $dispatchId === null ? null : ErpAccountingDeliveryDispatch::query()
                ->whereKey($dispatchId)
                ->where('erp_accounting_delivery_id', $locked->id)
                ->lockForUpdate()
                ->first();
            if ($dispatchId !== null && ($dispatch === null || $dispatch->generation !== $dispatchGeneration
                || $dispatch->status === 'completed'
                || $dispatch->attempt_sequence !== $attemptSequence
                || $dispatch->lease_token === null
                || ! hash_equals($dispatch->lease_token, (string) $dispatchLeaseToken)
                || ($dispatch->status === 'pending' && $dispatch->available_at?->isFuture()))) {
                return $locked;
            }
            if ($locked->status === ErpAccountingDelivery::STATUS_DELIVERED) {
                if ($dispatch === null) {
                    $dispatch = ErpAccountingDeliveryDispatch::query()
                        ->where('erp_accounting_delivery_id', $locked->id)
                        ->whereIn('status', ['pending', 'leased'])
                        ->orderByDesc('generation')
                        ->lockForUpdate()
                        ->first();
                }

                return $this->recordDispatchResult($dispatch, $locked);
            }
            $ready = $this->revalidate($locked, $company, $account);
            if ($ready === null) {
                return $this->recordDispatchResult($dispatch, $locked->fresh());
            }
            [$document, $client, $bytes, $eventProof] = $ready;
            $locked->increment('attempts');
            try {
                $officeDocument = resolve(CurrentTenant::class)->run((int) $locked->account_id,
                    function () use ($locked, $document, $client, $company, $bytes, $eventProof): FiscalDocument {
                        $original = $this->publishToOffice($locked, $document, $client, $company, $bytes);

                        return $eventProof === null ? $original : $this->publishEventToOffice($locked, $document, $client, $eventProof);
                    });
            } catch (AccountingPublicationFailed $error) {
                if ($error->reason === 'artifact_conflict') {
                    return $this->recordDispatchResult($dispatch, $this->markBlocked($locked, 'artifact_conflict'));
                }

                return $this->recordDispatchResult($dispatch, $this->markFailed($locked, 'publication_failed', retryable: true));
            } catch (FilesystemException) {
                return $this->recordDispatchResult($dispatch, $this->markFailed($locked, 'publication_failed', retryable: true));
            }
            $locked->update([
                'client_id' => $client->id,
                'fiscal_document_id' => $officeDocument->id,
                'status' => ErpAccountingDelivery::STATUS_DELIVERED,
                'reason_code' => null,
                'delivered_at' => now(),
            ]);

            return $this->recordDispatchResult($dispatch, $locked->fresh(['erpFiscalDocument']));
        });
    }

    public function retry(ErpAccountingDelivery $delivery): ErpAccountingDelivery
    {
        return $this->withFences($delivery, function (ErpAccountingDelivery $locked, ErpCompany $company, ?Account $account): ErpAccountingDelivery {
            if ($locked->status === ErpAccountingDelivery::STATUS_DELIVERED) {
                return $locked;
            }
            if ($this->revalidate($locked, $company, $account) === null) {
                return $locked->fresh();
            }
            $latest = ErpAccountingDeliveryDispatch::query()
                ->where('erp_accounting_delivery_id', $locked->id)
                ->orderByDesc('generation')
                ->lockForUpdate()
                ->first();
            if ($latest !== null && (! in_array($locked->status, [ErpAccountingDelivery::STATUS_FAILED, ErpAccountingDelivery::STATUS_BLOCKED], true) || $latest->status !== 'completed')) {
                return $locked;
            }
            $locked->update(['status' => ErpAccountingDelivery::STATUS_PENDING, 'reason_code' => null]);

            return $this->queueAfterCommit($locked, $latest !== null);
        });
    }

    private function recordDispatchResult(?ErpAccountingDeliveryDispatch $dispatch, ErpAccountingDelivery $delivery): ErpAccountingDelivery
    {
        if ($dispatch !== null) {
            resolve(ErpAccountingDispatchOutbox::class)->recordDeliveryResult($dispatch, $delivery->status, $delivery->attempts);
        }

        return $delivery;
    }

    private function withFences(ErpAccountingDelivery $delivery, callable $callback): ErpAccountingDelivery
    {
        $identity = ErpAccountingDelivery::query()->findOrFail($delivery->id);

        return DB::transaction(function () use ($identity, $callback): ErpAccountingDelivery {
            AccessTransaction::boundLockWaits();
            $company = ErpCompany::query()->whereKey($identity->erp_company_id)->lockForUpdate()->firstOrFail();
            $account = resolve(AccountingMappingFence::class)->acquire(Account::query()->whereKey($identity->account_id));
            ErpFiscalDocument::query()->whereKey($identity->erp_fiscal_document_id)->where('erp_company_id', $company->id)->lockForUpdate()->firstOrFail();
            $locked = ErpAccountingDelivery::query()->whereKey($identity->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $locked->erp_company_id === (int) $company->id && (int) $locked->account_id === (int) $identity->account_id && (int) $locked->erp_fiscal_document_id === (int) $identity->erp_fiscal_document_id, 409);

            return resolve(ErpCompanyContext::class)->run((int) $company->id, fn () => $callback($locked, $company, $account));
        });
    }

    /** @return array{ErpFiscalDocument, Client, string, ?array}|null */
    private function revalidate(ErpAccountingDelivery $delivery, ErpCompany $company, ?Account $account): ?array
    {
        if ($delivery->status === ErpAccountingDelivery::STATUS_BLOCKED && ! in_array($delivery->reason_code, ['client_mapping_missing', 'mapping_pending_approval', 'fiscal_document_not_authorized', 'fiscal_authorization_unverified', 'erp_trial_expired'], true)) {
            return null;
        }
        $reason = match (true) {
            $company->status !== 'active' => 'company_inactive',
            $account === null || $account->status !== 'active' => 'office_inactive',
            $delivery->delegation_id === null => 'intent_delegation_unverified',
            default => null,
        };
        if ($reason !== null) {
            $this->markBlocked($delivery, $reason);

            return null;
        }
        $commercialDenial = resolve(ErpCommercialGate::class)->operatingWriteDenialCode($company);
        if ($commercialDenial !== null) {
            $this->markBlocked($delivery, $commercialDenial);

            return null;
        }
        $delegation = ErpOfficeDelegation::query()->whereKey($delivery->delegation_id)->lockForUpdate()->first();
        if ($delegation === null || $delegation->status !== 'active'
            || (int) $delegation->erp_company_id !== (int) $company->id || (int) $delegation->account_id !== (int) $delivery->account_id) {
            $this->markBlocked($delivery, 'delegation_revoked');

            return null;
        }
        $document = ErpFiscalDocument::query()->whereKey($delivery->erp_fiscal_document_id)->where('erp_company_id', $company->id)->firstOrFail();
        $mappingQuery = ErpAccountingMapping::query()->where('delegation_id', $delegation->id)
            ->where('erp_establishment_id', $document->erp_establishment_id)->where('erp_company_id', $company->id)->where('account_id', $account->id);
        $mapping = ($delivery->mapping_id === null ? $mappingQuery->where('status', 'active') : $mappingQuery->whereKey($delivery->mapping_id))->lockForUpdate()->first();
        if ($mapping === null || $mapping->status !== 'active' || ($delivery->mapping_id !== null && (int) $delivery->mapping_revision !== (int) $mapping->revision)) {
            $this->markBlocked($delivery, $delivery->mapping_id === null ? 'client_mapping_missing' : 'mapping_revoked');

            return null;
        }
        if ($delivery->mapping_id === null) {
            $delivery->update(['mapping_id' => $mapping->id, 'mapping_revision' => $mapping->revision]);
        }
        $client = Client::withoutGlobalScope('account')->whereKey($mapping->client_id)->where('account_id', $account->id)->lockForUpdate()->first();
        if ($client === null || $client->status !== ClientStatus::Active) {
            $this->markBlocked($delivery, 'client_inactive');

            return null;
        }
        $establishment = ErpEstablishment::query()->whereKey($document->erp_establishment_id)->where('erp_company_id', $company->id)->first();
        if ($establishment === null || $establishment->status !== 'active') {
            $this->markBlocked($delivery, 'establishment_inactive');

            return null;
        }
        $document = ErpFiscalDocument::query()->whereKey($document->id)->where('erp_company_id', $company->id)->lockForUpdate()->firstOrFail();
        if ((int) $document->erp_establishment_id !== (int) $mapping->erp_establishment_id) {
            $this->markBlocked($delivery, 'mapping_revoked');

            return null;
        }
        $isEvent = $delivery->erp_fiscal_event_id !== null;
        if ((! $isEvent && $document->status !== ErpFiscalDocumentStateMachine::AUTHORIZED)
            || ($isEvent && ! in_array($document->status, ['authorized', 'canceled'], true))) {
            $this->markBlocked($delivery, 'fiscal_document_not_authorized');

            return null;
        }
        if ($delivery->attempts >= self::MAX_ATTEMPTS) {
            $this->markFailed($delivery, 'max_attempts_exceeded', retryable: false);

            return null;
        }
        if ($document->access_key === null || $document->storage_path === null || $document->artifact_sha256 === null || $document->artifact_byte_length === null) {
            $this->markFailed($delivery, 'artifact_incomplete', retryable: true);

            return null;
        }
        try {
            $bytes = Storage::disk('local')->get($document->storage_path);
        } catch (FilesystemException) {
            $bytes = null;
        }
        if ($bytes === null) {
            $this->markFailed($delivery, 'storage_unavailable', retryable: true);

            return null;
        }
        if (strlen($bytes) !== (int) $document->artifact_byte_length || ! hash_equals($document->artifact_sha256, hash('sha256', $bytes))) {
            $this->markFailed($delivery, 'artifact_integrity_failed', retryable: true);

            return null;
        }

        if (! resolve(ErpFiscalArtifactStore::class)->verifiedAuthorizedXmlReady($document)) {
            $this->markBlocked($delivery, 'fiscal_authorization_unverified');

            return null;
        }

        $eventProof = null;
        if ($isEvent) {
            $event = ErpFiscalEvent::query()->whereKey($delivery->erp_fiscal_event_id)->where('erp_fiscal_document_id', $document->id)->first();
            $eventProof = $event === null ? null : resolve(ErpFiscalArtifactStore::class)->verifiedAcceptedEvent($event, $document);
            if ($eventProof === null || $delivery->projection_identity !== 'event:'.$event->id) {
                $this->markBlocked($delivery, 'fiscal_event_unverified');

                return null;
            }
        } elseif ($delivery->projection_identity !== 'document') {
            $this->markBlocked($delivery, 'fiscal_event_unverified');

            return null;
        }

        return [$document, $client, $bytes, $eventProof];
    }

    private function publishToOffice(
        ErpAccountingDelivery $delivery,
        ErpFiscalDocument $document,
        Client $client,
        ErpCompany $company,
        string $bytes,
    ): FiscalDocument {
        $existing = FiscalDocument::withoutGlobalScope('account')
            ->where('client_id', $client->id)
            ->where('chave_acesso', $document->access_key)
            ->where('stage', FiscalStage::Document)
            ->where('event_id', '')
            ->first();
        if ($existing !== null) {
            abort_unless((int) $existing->account_id === (int) $delivery->account_id, 422, 'Documento fiscal já publicado em outro escritório');

            if (! hash_equals($existing->sha256, $document->artifact_sha256) || (int) $existing->xml_bytes !== (int) $document->artifact_byte_length
                || $existing->model !== (FiscalModel::fromDocumentModel($document->model))
                || ($existing->emitente_cnpj !== null && $existing->emitente_cnpj !== substr((string) $document->access_key, 6, 14))
                || ($existing->delivery_environment !== null && $existing->delivery_environment !== ($document->environment === 'production' ? 'producao' : 'homologacao'))) {
                $existing->forceFill(['processing_state' => FiscalProcessingState::Attention, 'processing_reason' => FiscalProcessingReason::ArtifactConflict])->save();
                throw new AccountingPublicationFailed('Existing fiscal artifact identity differs.', 'artifact_conflict');
            }
            $readiness = resolve(FiscalDocumentReadiness::class);
            $readiness->reconcileOne($existing);
            if ($readiness->isReadyRow($existing)) {
                resolve(CurrentTenant::class)->run((int) $delivery->account_id, fn () => resolve(FiscalProcessingCoordinator::class)->afterCompleteDocumentStored($existing));

                return $existing;
            }
        }

        $model = FiscalModel::fromDocumentModel($document->model);

        return resolve(CurrentTenant::class)->run((int) $delivery->account_id, function () use ($delivery, $client, $document, $model, $bytes, $existing): FiscalDocument {
            $path = FiscalXmlPath::for((int) $delivery->account_id, (int) $client->id, $document->access_key, '', FiscalStage::Document);
            if (! Storage::disk('fiscal')->put($path, $bytes)) {
                throw new AccountingPublicationFailed('Fiscal XML publication unavailable.');
            }
            $published = $existing ?? new FiscalDocument;
            $published->account_id = $delivery->account_id;
            if ($existing === null) {
                $published->fill([
                    'account_id' => $delivery->account_id,
                    'client_id' => $client->id,
                    'source' => FiscalSource::ErpIssued,
                    'model' => $model,
                    'kind' => FiscalKind::Document,
                    'stage' => FiscalStage::Document,
                    'chave_acesso' => $document->access_key,
                    'event_id' => '',
                    'nsu' => (int) $document->id,
                    'emitente_cnpj' => substr((string) $document->access_key, 6, 14),
                    'destinatario_cnpj' => null,
                    'valor_total' => $document->commercial_snapshot['total_amount'] ?? null,
                    'emissao_at' => $document->authorized_at,
                    'authorized_at' => $document->authorized_at,
                    'delivery_origin' => FiscalDeliveryOrigin::ErpIssued,
                    'delivery_environment' => $document->environment === 'production' ? 'producao' : 'homologacao',
                    'storage_path' => $path,
                    'sha256' => $document->artifact_sha256,
                    'xml_bytes' => (int) $document->artifact_byte_length,
                    'mascarado' => false,
                    'captured_at' => now(),
                    'storage_state' => FiscalStorageState::Unverified,
                ]);
            } else {
                $published->storage_path = $path;
            }
            $published->save();
            $readiness = resolve(FiscalDocumentReadiness::class);
            $readiness->reconcileOne($published);
            if (! $readiness->isReadyRow($published)) {
                throw new AccountingPublicationFailed('Fiscal XML integrity could not be verified.');
            }
            resolve(FiscalProcessingCoordinator::class)->afterCompleteDocumentStored($published);

            return $published;
        });
    }

    private function publishEventToOffice(ErpAccountingDelivery $delivery, ErpFiscalDocument $document, Client $client, array $proof): FiscalDocument
    {
        $xml = $proof['xml'];
        $hash = hash('sha256', $xml);
        $existing = FiscalDocument::withoutGlobalScope('account')->where('account_id', $delivery->account_id)
            ->where('client_id', $client->id)->where('chave_acesso', $document->access_key)
            ->where('stage', FiscalStage::Event)->where('event_id', $proof['event_identity'])->lockForUpdate()->first();
        $model = FiscalModel::fromDocumentModel($document->model);
        $environment = $document->environment === 'production' ? 'producao' : 'homologacao';
        if ($existing !== null && (! hash_equals((string) $existing->sha256, $hash) || (int) $existing->xml_bytes !== strlen($xml)
            || $existing->model !== $model || ($existing->emitente_cnpj !== null && $existing->emitente_cnpj !== $proof['issuer_cnpj'])
            || ($existing->delivery_environment !== null && $existing->delivery_environment !== $environment))) {
            $existing->forceFill(['processing_state' => FiscalProcessingState::Attention, 'processing_reason' => FiscalProcessingReason::ArtifactConflict])->save();
            throw new AccountingPublicationFailed('Existing fiscal event identity differs.', 'artifact_conflict');
        }
        $readiness = resolve(FiscalDocumentReadiness::class);
        if ($existing !== null) {
            $existing->forceFill(['storage_state' => $readiness->stateOf($existing), 'storage_verified_at' => now()])->saveQuietly();
            if ($existing->storage_state === FiscalStorageState::Available) {
                return $existing;
            }
        }
        $path = $existing?->storage_path ?? FiscalXmlPath::for((int) $delivery->account_id, (int) $client->id, $document->access_key, $proof['event_identity'], FiscalStage::Event);
        if (! Storage::disk('fiscal')->put($path, $xml)) {
            throw new AccountingPublicationFailed('Fiscal event publication unavailable.');
        }
        $published = $existing ?? new FiscalDocument;
        $published->account_id = $delivery->account_id;
        if ($existing === null) {
            $published->fill(['account_id' => $delivery->account_id, 'client_id' => $client->id, 'source' => FiscalSource::ErpIssued,
                'model' => $model, 'kind' => FiscalKind::Event, 'stage' => FiscalStage::Event, 'chave_acesso' => $document->access_key,
                'event_id' => $proof['event_identity'], 'nsu' => (int) $delivery->erp_fiscal_event_id, 'emitente_cnpj' => $proof['issuer_cnpj'],
                'evento_ocorrido_em_at' => $proof['occurred_at'], 'delivery_origin' => FiscalDeliveryOrigin::ErpIssued,
                'delivery_environment' => $environment, 'storage_path' => $path, 'sha256' => $hash, 'xml_bytes' => strlen($xml),
                'mascarado' => false, 'captured_at' => now(), 'storage_state' => FiscalStorageState::Unverified]);
        }
        $published->save();
        $published->forceFill(['storage_state' => $readiness->stateOf($published), 'storage_verified_at' => now()])->saveQuietly();
        if ($published->storage_state !== FiscalStorageState::Available) {
            throw new AccountingPublicationFailed('Fiscal event integrity could not be verified.');
        }

        return $published;
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
