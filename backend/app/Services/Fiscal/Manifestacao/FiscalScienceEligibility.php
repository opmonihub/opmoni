<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalDeliveryOrigin;
use App\Enums\FiscalKind;
use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Enums\FiscalProcessingReason;
use App\Enums\FiscalProviderNoteStatus;
use App\Enums\FiscalSource;
use App\Enums\FiscalStage;
use App\Enums\FiscalStorageState;
use App\Models\Account;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalManifestation;
use App\Services\Fiscal\Support\FiscalEnvironment;
use Carbon\CarbonInterface;

final class FiscalScienceEligibility
{
    public const SCIENCE_DEADLINE_DAYS = 10;

    public const CONCLUSIVE_EVENT_TYPES = ['210200', '210220', '210240'];

    public function __construct(
        private readonly FiscalScienceRolloutGate $rolloutGate,
    ) {}

    /**
     * @return array{eligible: bool, reason: ?FiscalProcessingReason}
     */
    public function forSummary(FiscalDocument $summary, Client $client, Account $account, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        if ($summary->stage !== FiscalStage::Summary || $summary->source !== FiscalSource::NfeDistribuicao) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::ProviderRejected];
        }

        $gate = $this->rolloutGate->evaluate((int) $account->getKey(), (int) $client->getKey(), $summary->chave_acesso);

        if (! $gate['allowed']) {
            return ['eligible' => false, 'reason' => match ($gate['reason']) {
                'pilot_scope' => FiscalProcessingReason::PilotScope,
                default => FiscalProcessingReason::GateDisabled,
            }];
        }

        if ($summary->delivery_origin !== FiscalDeliveryOrigin::AuthenticatedDistribution) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::DeliveryUnverified];
        }

        if ($summary->delivery_environment !== FiscalEnvironment::selecionar()) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::DeliveryUnverified];
        }

        $queried = preg_replace('/\D/', '', (string) $summary->queried_tax_id) ?? '';

        if ($queried === '' || $queried !== preg_replace('/\D/', '', (string) $client->tax_id)) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::DeliveryUnverified];
        }

        if ($account->status !== 'active') {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::AccountSuspended];
        }

        if ($client->trashed()) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::ClientInactive];
        }

        $certificate = $client->currentCertificate;

        if ($certificate === null) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::CertificateMissing];
        }

        if ($certificate->valid_until->isPast()) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::CertificateExpired];
        }

        if ($summary->emitente_cnpj !== null && preg_replace('/\D/', '', (string) $summary->emitente_cnpj) === preg_replace('/\D/', '', (string) $client->tax_id)) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::SelfIssued];
        }

        if ($summary->provider_note_status === FiscalProviderNoteStatus::Cancelled) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::NoteCancelled];
        }

        if ($summary->provider_note_status === FiscalProviderNoteStatus::Denied) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::NoteDenied];
        }

        if ($this->hasConclusiveEvent($summary)) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::ConclusiveEvent];
        }

        if ($this->hasAcceptedScience($summary)) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::Ready];
        }

        $authorizedAt = $summary->authorized_at;

        if ($authorizedAt === null) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::AuthorizationMissing];
        }

        if ($authorizedAt->isFuture()) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::AuthorizationFuture];
        }

        if ($authorizedAt->diffInDays($now) > self::SCIENCE_DEADLINE_DAYS) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::ScienceDeadlineExpired];
        }

        if ($this->hasVerifiedCompleteDocument($summary)) {
            return ['eligible' => false, 'reason' => FiscalProcessingReason::Ready];
        }

        return ['eligible' => true, 'reason' => null];
    }

    private function hasAcceptedScience(FiscalDocument $summary): bool
    {
        return FiscalManifestation::query()
            ->where('account_id', $summary->account_id)
            ->where('client_id', $summary->client_id)
            ->where('chave_acesso', $summary->chave_acesso)
            ->where('event_type', FiscalManifestationEventType::CienciaEmissao)
            ->whereIn('outcome', [
                FiscalManifestationOutcome::Sent,
                FiscalManifestationOutcome::AlreadyManifested,
            ])
            ->exists();
    }

    private function hasConclusiveEvent(FiscalDocument $summary): bool
    {
        return FiscalDocument::query()
            ->where('account_id', $summary->account_id)
            ->where('client_id', $summary->client_id)
            ->where('chave_acesso', $summary->chave_acesso)
            ->where('stage', FiscalStage::Event)
            ->where(function ($query): void {
                foreach (self::CONCLUSIVE_EVENT_TYPES as $type) {
                    $query->orWhere('event_id', 'like', $type.'-%');
                }
            })
            ->exists();
    }

    private function hasVerifiedCompleteDocument(FiscalDocument $summary): bool
    {
        return FiscalDocument::query()
            ->where('account_id', $summary->account_id)
            ->where('client_id', $summary->client_id)
            ->where('chave_acesso', $summary->chave_acesso)
            ->where('stage', FiscalStage::Document)
            ->where('kind', FiscalKind::Document)
            ->where('storage_state', FiscalStorageState::Available)
            ->exists();
    }
}
