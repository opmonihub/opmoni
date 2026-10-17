<?php

namespace App\Services\Fiscal\Manifestacao;

use App\Enums\FiscalManifestationEventType;
use App\Jobs\SendFiscalManifestationJob;
use App\Models\Client;

/**
 * Despacho pós-resumo da ciência da emissão (210210).
 */
final class ManifestacaoDispatcher
{
    public function __construct(
        private readonly FiscalManifestationStore $store,
        private readonly FiscalScienceRolloutGate $rolloutGate,
    ) {}

    public function manifestacaoHabilitada(): bool
    {
        return config('fiscal.manifestacao_enabled', false);
    }

    public function enfileirarCiencia(int $accountId, Client $client, string $chaveAcesso): bool
    {
        if (! $this->manifestacaoHabilitada()) {
            return false;
        }

        if (! $this->rolloutGate->evaluate($accountId, (int) $client->getKey(), $chaveAcesso)['allowed']) {
            return false;
        }

        $pedido = $this->store->registrarPedido(
            accountId: $accountId,
            clientId: (int) $client->getKey(),
            chaveAcesso: $chaveAcesso,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );

        if ($pedido->outcome->isVerdict()) {
            $pedido->touch();

            return true;
        }

        SendFiscalManifestationJob::dispatch(
            accountId: $accountId,
            clientId: (int) $client->getKey(),
            chaveAcesso: $chaveAcesso,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
        );

        return true;
    }
}
