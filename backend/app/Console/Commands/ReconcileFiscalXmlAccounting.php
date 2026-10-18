<?php

namespace App\Console\Commands;

use App\Enums\FiscalDeliveryOrigin;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Manifestacao\FiscalScienceEligibility;
use App\Services\Fiscal\Processing\FiscalProcessingCoordinator;
use App\Services\Fiscal\Read\FiscalDocumentReadiness;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
use App\Tenant\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class ReconcileFiscalXmlAccounting extends Command
{
    protected $signature = 'fiscal:reconcile-xml-accounting
                            {--account= : ID do escritório (obrigatório)}
                            {--client= : ID opcional do cliente}
                            {--apply : Persiste classificação e enfileira trabalho elegível}';

    protected $description = 'Classifica resumos/documentos históricos para o fluxo contábil de XML completo (dry-run por padrão)';

    public function handle(
        FiscalXmlMetadata $metadata,
        FiscalScienceEligibility $eligibility,
        FiscalDocumentReadiness $readiness,
    ): int {
        $accountId = (int) $this->option('account');

        if ($accountId <= 0) {
            $this->error('Informe --account=<id>.');

            return self::FAILURE;
        }

        return resolve(CurrentTenant::class)->run($accountId, fn (): int => $this->reconcileAccount($accountId, $metadata, $eligibility, $readiness));
    }

    private function reconcileAccount(int $accountId, FiscalXmlMetadata $metadata, FiscalScienceEligibility $eligibility, FiscalDocumentReadiness $readiness): int
    {
        $clientId = $this->option('client') !== null ? (int) $this->option('client') : null;
        $apply = (bool) $this->option('apply');

        $query = FiscalDocument::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId));

        $classified = 0;

        $account = Account::query()->findOrFail($accountId);

        $query->clone()->where('stage', FiscalStage::Document)->orderBy('id')->chunkById(100, function ($documents) use ($metadata, $readiness, $apply, &$classified): void {
            foreach ($documents as $document) {
                $state = $readiness->stateOf($document);
                if ($state === FiscalStorageState::Available && $document->authorized_at === null) {
                    try {
                        $parsed = $metadata->extract(Storage::disk('fiscal')->get($document->storage_path), $document->model);
                        if ($apply && $parsed->authorizedAt !== null) {
                            $document->forceFill(['authorized_at' => $parsed->authorizedAt])->saveQuietly();
                        }
                    } catch (\RuntimeException) {
                        $state = FiscalStorageState::Corrupt;
                    }
                }

                $this->line("documento {$document->id}: storage={$state->value} ready=".($state === FiscalStorageState::Available ? 'sim' : 'não'));
                $classified++;

                if ($apply) {
                    $document->forceFill([
                        'storage_state' => $state,
                        'storage_verified_at' => now(),
                        'delivery_origin' => $document->delivery_origin ?? FiscalDeliveryOrigin::Unverified,
                    ])->saveQuietly();
                    if ($state === FiscalStorageState::Available) {
                        resolve(FiscalProcessingCoordinator::class)->afterCompleteDocumentStored($document);
                    }
                }
            }
        });

        $query->clone()->where('stage', FiscalStage::Summary)->orderBy('id')->chunkById(100, function ($summaries) use ($eligibility, $account, $apply, &$classified): void {
            foreach ($summaries as $summary) {
                $summary->loadMissing('client');
                $client = $summary->client;

                if ($client === null) {
                    $this->line("resumo {$summary->id}: cliente ausente");

                    continue;
                }

                $decision = $eligibility->forSummary($summary, $client, $account);
                $label = $decision['eligible'] ? 'elegível_ciência' : ($decision['reason']?->value ?? 'inelegível');

                $this->line("resumo {$summary->id} chave={$summary->chave_acesso}: {$label}");
                $classified++;

                if (! $apply) {
                    continue;
                }

                if ($summary->processing_state === FiscalProcessingState::Complete) {
                    continue;
                }

                resolve(FiscalProcessingCoordinator::class)->afterSummaryStored($summary, $client, $account);
            }
        });

        $this->info(($apply ? 'Aplicado' : 'Prévia').": {$classified} linha(s) classificada(s).");

        return self::SUCCESS;
    }
}
