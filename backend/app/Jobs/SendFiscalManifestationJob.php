<?php

namespace App\Jobs;

use App\Enums\FiscalManifestationEventType;
use App\Models\Client;
use App\Services\Fiscal\Manifestacao\FiscalManifestationStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envio da manifestação do destinatário — o conector entra no grupo 4.
 */
final class SendFiscalManifestationJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 85;

    public int $tries = 1;

    public function __construct(
        public int $accountId,
        public int $clientId,
        public string $chaveAcesso,
        public FiscalManifestationEventType $eventType,
        public int $eventSeq = 1,
    ) {}

    public function handle(FiscalManifestationStore $store): void
    {
        if (! config('fiscal.manifestacao_enabled', false)) {
            return;
        }

        $client = Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->find($this->clientId);

        if ($client === null) {
            return;
        }

        $store->marcarEnfileirado(
            accountId: $this->accountId,
            clientId: $this->clientId,
            chaveAcesso: $this->chaveAcesso,
            eventType: $this->eventType,
            eventSeq: $this->eventSeq,
        );
    }
}
