<?php

namespace App\Jobs;

use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CaptureFiscalDocumentsJob implements ShouldQueue
{
    use Queueable;

    /**
     * 85 fica abaixo dos dois limites: do retry_after=90 do redis e do
     * --timeout=120 do worker (docker/queue-entrypoint.sh). Um job que
     * encosta no retry_after é exatamente o que o redis reentrega enquanto o
     * original ainda corre — e a idempotência por chave de acesso é o que
     * torna uma execução duplicada inofensiva quando ela acontece.
     */
    public int $timeout = 85;

    /**
     * Uma tentativa só: a reentrega do redis chega depois de 90 segundos,
     * quando o original pode estar vivo ainda — repetir seria a consulta
     * paralela que a NT classifica como uso indevido. Quem repete é a agenda
     * de hora em hora.
     */
    public int $tries = 1;

    public function __construct(
        public int $clientId,
        public FiscalSource $source,
        public int $accountId,
    ) {}

    public function handle(FiscalCaptureService $capture): void
    {
        // `account_id` explícito e escopo global desligado: o worker herda o
        // `CurrentTenant` do job anterior, e uma busca que dependesse dele
        // acharia o cliente da conta errada — ou nenhum. O `account_id` vem
        // junto no `where` porque a unicidade do id não prova a conta, e a
        // prova de conta é o que impede a captura de sair pelo tenant de
        // outro.
        $client = Client::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $this->accountId)
            ->find($this->clientId);

        if ($client === null) {
            return;
        }

        $capture->capture($client, $this->source);
    }
}
