<?php

namespace App\Services\Fiscal\Processing;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Jobs\RecoverFiscalXmlJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Manifestacao\FiscalScienceEligibility;
use App\Services\Fiscal\Manifestacao\ManifestacaoDispatcher;
use App\Services\Fiscal\Read\FiscalDocumentReadiness;
use Illuminate\Support\Facades\DB;

final class FiscalProcessingCoordinator
{
    public function __construct(
        private readonly FiscalScienceEligibility $eligibility,
        private readonly ManifestacaoDispatcher $manifestacao,
        private readonly FiscalDocumentReadiness $readiness,
    ) {}

    public function afterSummaryStored(FiscalDocument $summary, Client $client, Account $account): void
    {
        if ($summary->stage !== FiscalStage::Summary || $summary->source !== FiscalSource::NfeDistribuicao) {
            return;
        }

        $complete = $this->readiness->readyScope(FiscalDocument::query())
            ->where('account_id', $summary->account_id)->where('client_id', $summary->client_id)
            ->where('chave_acesso', $summary->chave_acesso)->first();
        if ($complete !== null) {
            $this->afterCompleteDocumentStored($complete);

            return;
        }

        $manifestation = FiscalManifestation::query()->where('account_id', $summary->account_id)
            ->where('client_id', $summary->client_id)->where('chave_acesso', $summary->chave_acesso)
            ->where('event_type', FiscalManifestationEventType::CienciaEmissao)
            ->first();
        if ($manifestation !== null && in_array($manifestation->outcome, [FiscalManifestationOutcome::Sent, FiscalManifestationOutcome::AlreadyManifested], true)) {
            $alreadyPending = in_array($summary->processing_state, [FiscalProcessingState::AwaitingXml, FiscalProcessingState::RecoveryQueued, FiscalProcessingState::Deferred], true);
            if ($alreadyPending || $summary->processing_reason === FiscalProcessingReason::AttemptsExhausted) {
                return;
            }
            $summary->forceFill(['processing_state' => FiscalProcessingState::AwaitingXml, 'processing_reason' => null, 'next_attempt_at' => now()])->saveQuietly();
            if (! $alreadyPending) {
                RecoverFiscalXmlJob::dispatch((int) $summary->account_id, (int) $summary->client_id, $summary->chave_acesso);
            }

            return;
        }

        if ($manifestation !== null && in_array($manifestation->outcome, [FiscalManifestationOutcome::Failed, FiscalManifestationOutcome::NotSendable, FiscalManifestationOutcome::DeadlineMissed], true)) {
            $summary->forceFill([
                'processing_state' => FiscalProcessingState::Attention,
                'processing_reason' => match ($manifestation->outcome) {
                    FiscalManifestationOutcome::NotSendable => FiscalProcessingReason::CertificateMissing,
                    FiscalManifestationOutcome::DeadlineMissed => FiscalProcessingReason::ScienceDeadlineExpired,
                    default => FiscalProcessingReason::ProviderRejected,
                },
                'next_attempt_at' => null,
            ])->saveQuietly();

            return;
        }

        $decision = $this->eligibility->forSummary($summary, $client, $account);

        if ($decision['eligible']) {
            $summary->forceFill([
                'processing_state' => FiscalProcessingState::ScienceQueued,
                'processing_reason' => null,
                'next_attempt_at' => now()->addMinutes(5),
            ])->saveQuietly();

            $this->manifestacao->enfileirarCiencia(
                (int) $account->getKey(),
                $client,
                $summary->chave_acesso,
            );

            return;
        }

        $summary->forceFill([
            'processing_state' => FiscalProcessingState::Attention,
            'processing_reason' => $decision['reason'],
            'next_attempt_at' => null,
        ])->saveQuietly();
    }

    public function afterCompleteDocumentStored(FiscalDocument $document): void
    {
        if ($document->stage !== FiscalStage::Document || ! $this->readiness->isReadyRow($document)) {
            return;
        }

        FiscalDocument::query()
            ->where('account_id', $document->account_id)
            ->where('client_id', $document->client_id)
            ->where('chave_acesso', $document->chave_acesso)
            ->where('stage', FiscalStage::Summary)
            ->update([
                'processing_state' => FiscalProcessingState::Complete,
                'processing_reason' => FiscalProcessingReason::Ready,
                'processing_completed_at' => DB::raw('COALESCE(processing_completed_at, CURRENT_TIMESTAMP)'),
                'next_attempt_at' => null,
                'processing_claim_expires_at' => null,
            ]);
    }

    /**
     * @return bool whether the claim succeeded
     */
    public function claim(FiscalDocument $summary, int $leaseSeconds = 120): bool
    {
        $now = now();
        $expires = $now->copy()->addSeconds($leaseSeconds);

        return DB::transaction(function () use ($summary, $now, $expires): bool {
            $updated = FiscalDocument::query()
                ->whereKey($summary->getKey())
                ->where('stage', FiscalStage::Summary)
                ->where(function ($query): void {
                    $query->whereNull('processing_state')->orWhere('processing_state', '!=', FiscalProcessingState::Complete);
                })
                ->where(function ($query) use ($now): void {
                    $query->whereNull('processing_claim_expires_at')
                        ->orWhere('processing_claim_expires_at', '<', $now);
                })
                ->update([
                    'processing_claim_expires_at' => $expires,
                    'processing_started_at' => $now,
                ]);

            if ($updated === 1) {
                $summary->forceFill(['processing_claim_expires_at' => $expires, 'processing_started_at' => $now]);
            }

            return $updated === 1;
        });
    }

    public function releaseClaim(FiscalDocument $summary): void
    {
        FiscalDocument::query()->whereKey($summary->getKey())
            ->where('processing_claim_expires_at', $summary->processing_claim_expires_at)
            ->update(['processing_claim_expires_at' => null]);
    }
}
