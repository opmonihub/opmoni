<?php

namespace App\Jobs;

use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalStage;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Manifestacao\FiscalScienceRolloutGate;
use App\Services\Fiscal\Processing\FiscalProcessingCoordinator;
use App\Services\Fiscal\Processing\FiscalXmlPointRecovery;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RecoverFiscalXmlJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 85;

    public int $tries = 1;

    public function __construct(
        public int $accountId,
        public int $clientId,
        public string $chaveAcesso,
    ) {}

    public function handle(
        FiscalXmlPointRecovery $recovery,
        FiscalProcessingCoordinator $coordinator,
        ?FiscalScienceRolloutGate $rolloutGate = null,
    ): void {
        $rolloutGate ??= resolve(FiscalScienceRolloutGate::class);

        resolve(CurrentTenant::class)->run($this->accountId, function () use ($recovery, $coordinator, $rolloutGate): void {
            if (! config('fiscal.manifestacao_enabled', false)) {
                return;
            }

            if (! $rolloutGate->evaluate($this->accountId, $this->clientId, $this->chaveAcesso)['allowed']) {
                return;
            }

            $summary = FiscalDocument::query()
                ->withoutGlobalScope('account')
                ->where('account_id', $this->accountId)
                ->where('client_id', $this->clientId)
                ->where('chave_acesso', $this->chaveAcesso)
                ->where('stage', FiscalStage::Summary)
                ->first();

            if ($summary === null) {
                return;
            }

            if ($summary->processing_state === FiscalProcessingState::Complete) {
                return;
            }

            if (! $coordinator->claim($summary)) {
                return;
            }

            $client = Client::query()
                ->withoutGlobalScope('account')
                ->where('account_id', $this->accountId)
                ->find($this->clientId);

            if ($client === null) {
                $coordinator->releaseClaim($summary);

                return;
            }

            $summary->forceFill([
                'processing_state' => FiscalProcessingState::RecoveryQueued,
            ])->saveQuietly();

            $outcome = $recovery->recover($client, $this->chaveAcesso);

            $summary->refresh();
            $maxAttempts = (int) config('fiscal.manifestacao_resync_max_attempts', 3);

            if ($outcome === 'recovered') {
                $coordinator->releaseClaim($summary);

                return;
            }

            $attempts = (int) $summary->processing_attempts + 1;
            $nextAttempt = CarbonImmutable::now()->addHour();

            $reason = match ($outcome) {
                'blocked', 'deferred' => FiscalProcessingReason::BudgetCooldown,
                'not_sendable' => FiscalProcessingReason::CertificateMissing,
                'storage_failed' => FiscalProcessingReason::PublicationDelay,
                'not_found', 'invalid_response' => FiscalProcessingReason::ProviderTransient,
                default => FiscalProcessingReason::ProviderTransient,
            };

            if ($attempts >= $maxAttempts && ! in_array($outcome, ['blocked', 'deferred'], true)) {
                $summary->forceFill([
                    'processing_state' => FiscalProcessingState::Attention,
                    'processing_reason' => FiscalProcessingReason::AttemptsExhausted,
                    'processing_attempts' => $attempts,
                    'next_attempt_at' => null,
                    'processing_claim_expires_at' => null,
                ])->saveQuietly();

                return;
            }

            $summary->forceFill([
                'processing_state' => FiscalProcessingState::Deferred,
                'processing_reason' => $reason,
                'processing_attempts' => $attempts,
                'next_attempt_at' => $nextAttempt,
                'processing_claim_expires_at' => null,
            ])->saveQuietly();
        });
    }
}
