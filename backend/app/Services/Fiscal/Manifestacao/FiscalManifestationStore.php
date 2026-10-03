<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalManifestationEventType;
use App\Enums\FiscalManifestationOutcome;
use App\Models\FiscalManifestation;
use Carbon\CarbonInterface;

/**
 * Grava e atualiza o estado da manifestação com deduplicação por chave lógica.
 */
final class FiscalManifestationStore
{
    public function registrarPedido(
        int $accountId,
        int $clientId,
        string $chaveAcesso,
        FiscalManifestationEventType $eventType,
        int $eventSeq,
        string $requestedBy,
        ?CarbonInterface $requestedAt = null,
    ): FiscalManifestation {
        $requestedAt ??= now();

        $registro = FiscalManifestation::query()
            ->withoutGlobalScope('account')
            ->firstOrNew([
                'account_id' => $accountId,
                'client_id' => $clientId,
                'chave_acesso' => $chaveAcesso,
                'event_type' => $eventType,
                'event_seq' => $eventSeq,
            ]);

        // `account_id` não é mass-assignável: jobs gravam a conta de forma explícita.
        $registro->account_id = $accountId;
        $registro->fill([
            'requested_by' => $requestedBy,
            'outcome' => FiscalManifestationOutcome::Pending,
            'requested_at' => $requestedAt,
            'sent_at' => null,
            'resulted_at' => null,
        ]);
        $registro->save();

        return $registro;
    }

    public function marcarEnfileirado(
        int $accountId,
        int $clientId,
        string $chaveAcesso,
        FiscalManifestationEventType $eventType,
        int $eventSeq,
    ): void {
        FiscalManifestation::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('client_id', $clientId)
            ->where('chave_acesso', $chaveAcesso)
            ->where('event_type', $eventType)
            ->where('event_seq', $eventSeq)
            ->update(['outcome' => FiscalManifestationOutcome::Queued]);
    }
}
