<?php

namespace App\Console\Commands;

use App\Enums\FiscalDeliveryOrigin;
use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProcessingState;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Manifestacao\FiscalScienceEligibility;
use App\Services\Fiscal\Manifestacao\ManifestacaoDispatcher;
use App\Services\Fiscal\Read\FiscalDocumentReadiness;
use App\Services\Fiscal\Support\FiscalXmlMetadata;
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
        ManifestacaoDispatcher $manifestacao,
    ): int {
        $accountId = (int) $this->option('account');

        if ($accountId <= 0) {
            $this->error('Informe --account=<id>.');

            return self::FAILURE;
        }

        $clientId = $this->option('client') !== null ? (int) $this->option('client') : null;
        $apply = (bool) $this->option('apply');

        $query = FiscalDocument::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->when($clientId !== null, fn ($q) => $q->where('client_id', $clientId));

        $classified = 0;

        $account = Account::query()->findOrFail($accountId);

        $query->clone()->where('stage', FiscalStage::Summary)->orderBy('id')->chunkById(100, function ($summaries) use ($eligibility, $account, $apply, $manifestacao, &$classified): void {
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

                if ($decision['eligible']) {
                    if (! in_array($summary->processing_state, [
                        FiscalProcessingState::AwaitingScience,
                        FiscalProcessingState::AwaitingXml,
                        FiscalProcessingState::Deferred,
                    ], true)) {
                        $summary->forceFill([
                            'processing_state' => FiscalProcessingState::AwaitingScience,
                            'processing_reason' => null,
                            'next_attempt_at' => now(),
                        ])->saveQuietly();
                    }

                    $manifestacao->enfileirarCiencia(
                        (int) $account->getKey(),
                        $client,
                        $summary->chave_acesso,
                    );
                } elseif ($summary->processing_state !== FiscalProcessingState::Attention
                    || $summary->processing_reason !== $decision['reason']) {
                    $summary->forceFill([
                        'processing_state' => FiscalProcessingState::Attention,
                        'processing_reason' => $decision['reason'] ?? FiscalProcessingReason::DeliveryUnverified,
                        'next_attempt_at' => null,
                    ])->saveQuietly();
                }
            }
        });

        $query->clone()->where('stage', FiscalStage::Document)->orderBy('id')->chunkById(100, function ($documents) use ($metadata, $readiness, $apply, &$classified): void {
            foreach ($documents as $document) {
                $state = FiscalStorageState::Unverified;
                $path = $document->storage_path;

                if ($path !== '' && Storage::disk('fiscal')->exists($path)) {
                    $bytes = Storage::disk('fiscal')->get($path);
                    $parsed = $metadata->extract($bytes, $document->model);
                    $state = hash('sha256', $bytes) === $document->sha256
                        ? FiscalStorageState::Available
                        : FiscalStorageState::Corrupt;
                    if ($apply && $document->authorized_at === null && $parsed->authorizedAt !== null) {
                        $document->forceFill(['authorized_at' => $parsed->authorizedAt])->saveQuietly();
                    }
                } elseif ($path !== '') {
                    $state = FiscalStorageState::Missing;
                }

                $this->line("documento {$document->id}: storage={$state->value} ready=".($readiness->isReadyRow($document) ? 'sim' : 'não'));
                $classified++;

                if ($apply) {
                    $document->forceFill([
                        'storage_state' => $state,
                        'storage_verified_at' => now(),
                        'delivery_origin' => $document->delivery_origin ?? FiscalDeliveryOrigin::Unverified,
                    ])->saveQuietly();

                    if ($document->processing_state === FiscalProcessingState::Complete) {
                        continue;
                    }
                }
            }
        });

        $this->info(($apply ? 'Aplicado' : 'Prévia').": {$classified} linha(s) classificada(s).");

        return self::SUCCESS;
    }
}
