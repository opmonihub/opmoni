<?php

namespace App\Jobs;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\Cte57DocumentBuilder;
use App\Erp\Fiscal\ErpFiscalArtifactStore;
use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Erp\Fiscal\ErpFiscalCertificateRevisionService;
use App\Erp\Fiscal\ErpFiscalCorrelationValidator;
use App\Erp\Fiscal\ErpFiscalEventRegistry;
use App\Erp\Fiscal\ErpFiscalProductionGate;
use App\Erp\Fiscal\ErpFiscalSchemaValidator;
use App\Erp\Fiscal\ErpFiscalXmlSigner;
use App\Erp\Fiscal\Mdfe58DocumentBuilder;
use App\Erp\Fiscal\Nfce65DocumentBuilder;
use App\Erp\Fiscal\Nfe55DocumentBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessErpFiscalAttempt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 150;

    public function __construct(public readonly int $attemptId) {}

    public function handle(ErpFiscalAttemptService $attempts, ErpFiscalAttemptAuthorization $authorization, ErpFiscalCertificateRevisionService $certificates, ErpFiscalXmlSigner $signer, ErpFiscalSchemaValidator $schemas, ErpFiscalCorrelationValidator $correlation, ErpFiscalArtifactStore $artifacts, ErpFiscalProductionGate $productionGate, ErpFiscalProvider $provider): void
    {
        $attempt = $attempts->claim($this->attemptId);
        if (! $attempt) {
            return;
        }
        $token = (string) $attempt->lease_token;
        if (! in_array($attempt->action, ['document:queue', 'document:contingency', 'document:transmit', 'document:consult', 'document:reconcile', 'event:submit', 'invalidation:submit'], true)) {
            $attempts->complete($attempt->id, $token, 'attention_required', ['code' => 'unsupported_attempt_action']);

            return;
        }
        if (in_array($attempt->action, ['event:submit', 'invalidation:submit'], true) && (int) $attempt->dispatch_attempts > 1) {
            $attempts->complete($attempt->id, $token, 'indeterminate', ['code' => 'operation_requires_external_evidence']);

            return;
        }
        $snapshot = $authorization->sourceSnapshot($attempt);
        $source = $snapshot['request']['source'] ?? [];
        $document = $attempt->document()->first();
        $issuer = $attempt->establishment()->first();
        $isDocument = str_starts_with($attempt->action, 'document:');
        $subject = $isDocument || $attempt->action === 'event:submit' ? $document : $attempt->invalidation()->first();
        if (! $issuer || ! $subject || ($source['issuer']['cnpj'] ?? null) !== $issuer->cnpj || ! $productionGate->allowsLiveDispatch($subject, (int) $attempt->actor_user_id)) {
            $attempts->complete($attempt->id, $token, 'blocked_authority', ['code' => 'issuer_or_release_scope_changed']);

            return;
        }
        try {
            $configuration = $attempt->configurationRevision()->first();
            $certificate = $attempt->certificateRevision()->first();
            if (! $configuration || ! $certificate || $configuration->environment !== $attempt->environment || ! $attempt->schema_release
                || (int) $configuration->configurationOwner->erp_company_id !== (int) $attempt->erp_company_id
                || (int) $configuration->configurationOwner->erp_establishment_id !== (int) $issuer->id) {
                throw new \InvalidArgumentException('Revisões fiscais incoerentes.');
            }
            $material = $certificates->material($certificate, $issuer);
            $pkcs12 = [];
            if (! openssl_pkcs12_read($material['certificate'], $pkcs12, $material['password']) || ! is_string($pkcs12['cert'] ?? null) || ! is_string($pkcs12['pkey'] ?? null)) {
                throw new \InvalidArgumentException('Certificado fixado não utilizável.');
            }
            if ($isDocument) {
                $builder = app(match ($attempt->model) {
                    '55' => Nfe55DocumentBuilder::class, '65' => Nfce65DocumentBuilder::class, '57' => Cte57DocumentBuilder::class, '58' => Mdfe58DocumentBuilder::class
                });
                if ($attempt->model === '65' && ($source['nfce_qr']['mode'] ?? null) === 'offline') {
                    $source = app(ErpFiscalEventRegistry::class)->signOfflineQr($source, $pkcs12['pkey']);
                }
                $builder->validateSource($source);
                $signed = $signer->sign($builder->build($source), $pkcs12['pkey'], $pkcs12['cert']);
                $schemas->assertValid($signed, $attempt->model, 'request');
                if (in_array($attempt->model, ['57', '58'], true)) {
                    $schemas->assertEmbeddedRoadModalValid($signed, $attempt->model);
                }
                $expected = ['model' => $document->model, 'access_key' => $document->access_key, 'issuer_cnpj' => $issuer->cnpj, 'environment' => $document->environment, 'series' => $document->series, 'number' => $document->number];
                $correlation->assertDocumentMatches($signed, $expected);
            } else {
                $registry = app(ErpFiscalEventRegistry::class);
                $unsigned = $attempt->action === 'event:submit' ? $registry->buildEvent($source) : $registry->buildInvalidation($source);
                $signed = $signer->sign($unsigned, $pkcs12['pkey'], $pkcs12['cert']);
                $schemas->assertValid($signed, $attempt->model, $attempt->action === 'event:submit' ? 'event' : 'invalidation', $source['event_type'] ?? null);
            }
        } catch (Throwable) {
            $attempts->complete($attempt->id, $token, 'attention_required', ['code' => 'local_fiscal_prerequisite_failed']);

            return;
        }
        if ($isDocument && in_array($attempt->action, ['document:queue', 'document:contingency'], true)) {
            $artifact = null;
            if ($attempt->action === 'document:contingency') {
                try {
                    $artifact = $artifacts->persistLocalContingencyXml($document, $signed);
                } catch (Throwable) {
                    $attempts->complete($attempt->id, $token, 'attention_required', ['code' => 'local_contingency_storage_failed']);

                    return;
                }
            }
            $attempts->finalize($attempt->id, $token, 'accepted', ['code' => 'local_preparation_complete', 'artifact_ready' => false, 'provider_outcome' => 'not_dispatched', ...($artifact === null ? [] : ['artifact_sha256' => $artifact['artifact_sha256'], 'artifact_byte_length' => $artifact['artifact_byte_length']])], null, null, static function () use ($document, $artifact): bool {
                $document->refresh();
                if (! in_array($document->status, ['draft', 'queued', 'contingency_local'], true) || $document->authorized_at !== null || isset($document->metadata['closed_at'])) {
                    return false;
                }
                if ($artifact !== null) {
                    $document->fill([...$artifact, 'status' => 'contingency_local', 'metadata' => [...($document->metadata ?? []), 'contingency' => ['active' => true, 'claims_provider_authorization' => false]]])->save();
                }

                return true;
            });

            return;
        }
        if ($isDocument && $attempt->model === '57' && ($source['document_kind'] ?? null) === 'substitution') {
            $attempts->complete($attempt->id, $token, 'attention_required', ['code' => 'cte_substitution_disagreement_evidence_unavailable', 'provider_outcome' => 'not_dispatched', 'artifact_ready' => false]);

            return;
        }
        if (! DB::transaction(fn (): bool => $authorization->allowsDispatch($attempt))) {
            $attempts->complete($attempt->id, $token, 'blocked_authority', ['code' => 'authority_revoked_before_provider']);

            return;
        }
        $reconcile = $attempt->action === 'document:consult' || (int) $attempt->dispatch_attempts > 1
            || in_array($document?->status, ['indeterminate', 'submitting', 'authorized'], true)
            || $document?->attempts()->whereKeyNot($attempt->id)->whereIn('action', ['document:transmit', 'document:reconcile'])->whereIn('status', ['submitting', 'indeterminate', 'artifact_pending', 'accepted'])->exists();
        try {
            if ($isDocument) {
                $result = $reconcile ? $provider->consult($document) : $provider->transmit($document, ['signed_xml' => $signed, 'configuration_revision_id' => $attempt->erp_fiscal_configuration_revision_id, 'certificate_revision_id' => $attempt->erp_fiscal_certificate_revision_id, 'schema_release' => $attempt->schema_release]);
            } elseif ($attempt->action === 'event:submit') {
                $event = $attempt->event()->firstOrFail();
                $event->payload_snapshot = ['signed_xml' => $signed];
                $result = $provider->submitEvent($document, $event);
            } else {
                $invalidation = $attempt->invalidation()->firstOrFail();
                $invalidation->payload_snapshot = ['signed_xml' => $signed];
                $result = $provider->submitInvalidation($invalidation);
            }
        } catch (Throwable) {
            $attempts->finalize($attempt->id, $token, 'indeterminate', ['code' => 'provider_outcome_unknown'], null, null, static function () use ($document, $isDocument): bool {
                $document?->refresh();
                if ($isDocument && (! in_array($document->status, ['draft', 'queued', 'submitting', 'indeterminate', 'rejected', 'contingency_local'], true) || $document->authorized_at !== null)) {
                    return false;
                }
                if ($isDocument) {
                    $document->status = 'indeterminate';
                    $document->save();
                }

                return true;
            });

            return;
        }
        if (! $isDocument) {
            $accepted = $result->outcome === 'event_accepted';
            $rejected = in_array($result->outcome, ['event_rejected', 'rejected'], true);
            if ($accepted) {
                try {
                    if (! is_string($result->xml)) {
                        throw new \InvalidArgumentException('Evidência correlacionada ausente.');
                    }
                    $schemas->assertValid($result->xml, $attempt->model, $attempt->action === 'event:submit' ? 'processed_event' : 'processed_invalidation', $source['event_type'] ?? null);
                    app(ErpFiscalEventRegistry::class)->assertOperationResultMatches($result->xml, $signed, $source, (string) $result->protocol);
                    if (! $signer->verify($result->xml, $pkcs12['cert'])) {
                        throw new \InvalidArgumentException('Assinatura da operação não corresponde à revisão fixada.');
                    }
                } catch (Throwable) {
                    $attempts->complete($attempt->id, $token, 'attention_required', ['code' => 'provider_event_evidence_missing_or_mismatched', 'provider_outcome' => 'event_accepted'], $result->receipt, $result->protocol);

                    return;
                }
            }
            $status = $accepted ? 'accepted' : ($rejected ? 'rejected' : 'indeterminate');
            $attempts->finalize($attempt->id, $token, $status, ['provider_outcome' => $result->outcome], $result->receipt, $result->protocol, static function () use ($attempt, $accepted, $status, $document, $source, $result): void {
                $document?->refresh();
                $operation = $attempt->action === 'event:submit' ? $attempt->event()->firstOrFail() : $attempt->invalidation()->firstOrFail();
                $operation->fill(['status' => $status, 'protocol' => $result->protocol, 'submitted_at' => now(), 'completed_at' => now()])->save();
                if ($accepted && $attempt->action === 'event:submit' && in_array($source['event_type'], ['cancel', 'substitution_cancel'], true) && $document->status === 'authorized') {
                    $document->status = 'canceled';
                    $document->save();
                }
                if ($accepted && ($source['event_type'] ?? null) === 'close') {
                    $document->metadata = [...($document->metadata ?? []), 'closed_at' => now()->toIso8601String()];
                    $document->save();
                }
            });

            return;
        }
        if ($result->outcome !== 'authorized') {
            $status = $result->outcome === 'rejected' ? 'rejected' : 'indeterminate';
            $attempts->finalize($attempt->id, $token, $status, ['provider_outcome' => $result->outcome], $result->receipt, $result->protocol, static function () use ($document, $status): bool {
                $document->refresh();
                if (! in_array($document->status, ['draft', 'queued', 'submitting', 'indeterminate', 'rejected', 'contingency_local'], true)) {
                    return false;
                }
                if ($document->authorized_at === null) {
                    $document->status = $status;
                    $document->save();

                    return true;
                }

                return false;
            });

            return;
        }
        try {
            $processed = $result->xml ?? '';
            $schemas->assertValid($processed, $attempt->model, 'processed');
            if (in_array($attempt->model, ['57', '58'], true)) {
                $schemas->assertEmbeddedRoadModalValid($processed, $attempt->model);
            }
            $correlation->assertProcessedMatches($processed, [...$expected, 'protocol' => $result->protocol]);
            if (! $signer->verify($processed, $pkcs12['cert'])) {
                throw new \InvalidArgumentException('Assinatura do XML processado incoerente.');
            }
            $document->refresh();
            $expectedArtifact = $document->only(['storage_path', 'artifact_sha256', 'artifact_byte_length']);
            $publishedArtifactIntact = $artifacts->verifyIntegrity($document);
            $artifact = $artifacts->persistAuthorizedXml($document, $processed);
        } catch (Throwable) {
            $attempts->finalize($attempt->id, $token, 'artifact_pending', ['code' => 'authorized_artifact_not_ready', 'provider_outcome' => 'authorized', 'artifact_ready' => false], $result->receipt, $result->protocol, static function () use ($document, $result): bool {
                $document->refresh();
                if (! in_array($document->status, ['draft', 'queued', 'submitting', 'indeterminate', 'rejected', 'contingency_local'], true) || $document->authorized_at !== null) {
                    return false;
                }
                $document->fill(['status' => 'authorized', 'authorized_at' => $document->authorized_at ?? now(), 'metadata' => [...($document->metadata ?? []), 'provider_outcome' => 'authorized', 'provider_protocol' => $result->protocol]])->save();

                return true;
            });

            return;
        }
        $attempts->finalize($attempt->id, $token, 'accepted', ['document_status' => 'authorized', 'artifact_ready' => true, 'artifact_sha256' => $artifact['artifact_sha256'], 'artifact_byte_length' => $artifact['artifact_byte_length']], $result->receipt, $result->protocol, static function () use ($document, $artifact, $result, $expectedArtifact, $publishedArtifactIntact): bool {
            $document->refresh();
            if (! in_array($document->status, ['draft', 'queued', 'submitting', 'indeterminate', 'rejected', 'contingency_local', 'authorized'], true) || isset($document->metadata['closed_at']) || $document->only(array_keys($expectedArtifact)) !== $expectedArtifact || $document->status === 'authorized' && $publishedArtifactIntact) {
                return false;
            }
            $wasReady = $document->status === 'authorized' && $document->storage_path !== null;
            $document->fill([...$artifact, 'status' => 'authorized', 'authorized_at' => $document->authorized_at ?? now(), 'metadata' => [...($document->metadata ?? []), 'provider_protocol' => $result->protocol]])->save();
            if ($wasReady) {
                return true;
            }
            $handoff = app(ErpAccountingHandoffService::class);
            $delivery = $handoff->ensureDeliveryIntent($document);
            if ($delivery !== null) {
                $handoff->queueAfterCommit($delivery);
            }

            return true;
        });
    }
}
