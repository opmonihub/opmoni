<?php

namespace App\Services\Fiscal\Processing;

use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
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

        $decision = $this->eligibility->forSummary($summary, $client, $account);

        if ($decision['eligible']) {
            $summary->forceFill([
                'processing_state' => FiscalProcessingState::AwaitingScience,
                'processing_reason' => null,
                'next_attempt_at' => now(),
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
                'processing_completed_at' => now(),
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
                ->where(function ($query) use ($now): void {
                    $query->whereNull('processing_claim_expires_at')
                        ->orWhere('processing_claim_expires_at', '<', $now);
                })
                ->update([
                    'processing_claim_expires_at' => $expires,
                    'processing_started_at' => $now,
                ]);

            return $updated === 1;
        });
    }

    public function releaseClaim(FiscalDocument $summary): void
    {
        $summary->forceFill(['processing_claim_expires_at' => null])->saveQuietly();
    }
}
