<?php

namespace App\Services\Fiscal\Processing;

use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalStage;
use App\Jobs\RecoverFiscalXmlJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Tenant\CurrentTenant;

final class FiscalProcessingDueWork
{
    private const CHUNK = 40;

    public function dispatchDue(): int
    {
        if (! config('fiscal.manifestacao_enabled', false)) {
            return 0;
        }

        $now = now();
        $dispatched = 0;

        FiscalDocument::query()
            ->withoutGlobalScope('account')
            ->where('stage', FiscalStage::Summary)
            ->whereIn('processing_state', [
                FiscalProcessingState::AwaitingScience,
                FiscalProcessingState::ScienceQueued,
                FiscalProcessingState::AwaitingXml,
                FiscalProcessingState::RecoveryQueued,
                FiscalProcessingState::Deferred,
            ])
            ->where(function ($query) use ($now): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('processing_claim_expires_at')
                    ->orWhere('processing_claim_expires_at', '<', $now);
            })
            ->orderBy('next_attempt_at')
            ->orderBy('id')
            ->limit(self::CHUNK)
            ->get()
            ->each(function (FiscalDocument $summary) use (&$dispatched): void {
                if ($this->dispatchForSummary($summary)) {
                    $dispatched++;
                }
            });

        return $dispatched;
    }

    private function dispatchForSummary(FiscalDocument $summary): bool
    {
        $client = Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $summary->account_id)
            ->find($summary->client_id);

        if ($client === null) {
            return false;
        }

        return match ($summary->processing_state) {
            FiscalProcessingState::AwaitingScience,
            FiscalProcessingState::ScienceQueued,
            FiscalProcessingState::Deferred => $this->dispatchScience($summary, $client),
            FiscalProcessingState::AwaitingXml,
            FiscalProcessingState::RecoveryQueued => $this->dispatchRecovery($summary),
            default => false,
        };
    }

    private function dispatchScience(FiscalDocument $summary, Client $client): bool
    {
        $manifestation = FiscalManifestation::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $summary->account_id)
            ->where('client_id', $summary->client_id)
            ->where('chave_acesso', $summary->chave_acesso)
            ->orderByDesc('id')
            ->first();

        if ($manifestation !== null && in_array($manifestation->outcome, [
            FiscalManifestationOutcome::Sent,
            FiscalManifestationOutcome::AlreadyManifested,
        ], true)) {
            $summary->forceFill([
                'processing_state' => FiscalProcessingState::AwaitingXml,
                'next_attempt_at' => now(),
            ])->saveQuietly();

            RecoverFiscalXmlJob::dispatch(
                (int) $summary->account_id,
                (int) $summary->client_id,
                $summary->chave_acesso,
            );

            return true;
        }

        return resolve(CurrentTenant::class)->run((int) $summary->account_id, function () use ($summary, $client): bool {
            $account = Account::query()->find($summary->account_id);
            if ($account === null) {
                return false;
            }
            resolve(FiscalProcessingCoordinator::class)->afterSummaryStored($summary, $client, $account);

            return in_array($summary->fresh()->processing_state, [FiscalProcessingState::ScienceQueued, FiscalProcessingState::AwaitingScience, FiscalProcessingState::AwaitingXml], true);
        });
    }

    private function dispatchRecovery(FiscalDocument $summary): bool
    {
        $summary->forceFill([
            'processing_state' => FiscalProcessingState::RecoveryQueued,
            'next_attempt_at' => now()->addHour(),
        ])->saveQuietly();

        RecoverFiscalXmlJob::dispatch((int) $summary->account_id, (int) $summary->client_id, $summary->chave_acesso);

        return true;
    }
}
