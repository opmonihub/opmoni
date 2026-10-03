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

        $this->store->registrarPedido(
            accountId: $accountId,
            clientId: (int) $client->getKey(),
            chaveAcesso: $chaveAcesso,
            eventType: FiscalManifestationEventType::CienciaEmissao,
            eventSeq: 1,
            requestedBy: 'job:SendFiscalManifestationJob',
        );

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
