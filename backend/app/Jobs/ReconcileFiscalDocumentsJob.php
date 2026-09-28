<?php

namespace App\Jobs;

use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalReconciliation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A volta atrás de um cliente: as posições que a captura gravou como lacuna,
 * buscadas uma a uma e sob o teto de consultas da hora.
 *
 * Um job por cliente e por fonte, e nunca um por carteira: cada posição é uma
 * consulta pontual ao CNPJ, e a carteira inteira não cabe na janela do worker —
 * o que caberia seria uma execução morta no meio, com a trava vencida e a
 * contagem de tentativas pela metade. É o mesmo desenho do
 * `CaptureFiscalDocumentsJob`, e a mesma trava por cliente e fonte: uma chave só
 * para captura e para reconciliação, senão as duas consultam o mesmo CNPJ ao
 * mesmo tempo, que é o uso indevido que a NT classifica.
 */
final class ReconcileFiscalDocumentsJob implements ShouldQueue
{
    use Queueable;

    /**
     * 85 fica abaixo dos três limites: do `fiscal.lock_ttl` (180), do
     * retry_after=90 do redis e do --timeout=120 do worker
     * (docker/queue-entrypoint.sh). A trava é o primeiro deles porque a
     * execução que a perde é a que ainda consulta: um job morto pelo timeout
     * com a trava vencida deixa a reconciliação seguinte entrar enquanto esta
     * ainda pergunta ao fisco, e duas consultas do mesmo CNPJ ao mesmo tempo é
     * exatamente o que a trava existe para impedir.
     */
    public int $timeout = 85;

    /**
     * Uma tentativa só: a reconciliação que falhou precisa aparecer, e não ser
     * repetida às cegas. Quem repete é a agenda, uma vez ao dia, e a contagem
     * de tentativas de cada lacuna é o que impede a repetição de virar
     * máquina de consulta.
     */
    public int $tries = 1;

    public function __construct(
        public int $clientId,
        public FiscalSource $source,
    ) {}

    public function handle(FiscalReconciliation $reconciliation): void
    {
        // `find` sem `withoutGlobalScopes`: o escopo de conta só existe com
        // tenant, e o de exclusão lógica é o que impede reconciliar cliente
        // apagado. Um cliente que saiu da carteira entre o despacho e a
        // execução é nada, não erro.
        $client = Client::query()->find($this->clientId);

        if ($client === null) {
            return;
        }

        $reconciliation->run($client, $this->source);
    }
}
