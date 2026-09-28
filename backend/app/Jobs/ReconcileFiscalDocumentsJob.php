<?php

namespace App\Jobs;

use App\Enums\FiscalSource;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalReconciliation;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
 *
 * Ele adota a conta do cliente enquanto roda e devolve a conta corrente que
 * encontrou ao fim, e essa devolução é parte do contrato: o `queue:work` é longo
 * e o `CurrentTenant` é um singleton que ninguém zera entre jobs, então um job
 * que deixa a conta do cliente atrás desliga silenciosamente o próximo job que
 * não adota conta própria.
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
        // A conta sai da consulta e a conta do cliente entra no lugar dela. A
        // leitura do escopo de conta depende da conta corrente, e a conta
        // corrente é um singleton que o worker de fila nunca zera entre jobs: o
        // `queue:work` é longo e o valor do job anterior sobrevive, então um
        // `find` com o escopo ligado devolveria `null` para o cliente de uma
        // conta que não é a que ficou apontada — e a reconciliação da noite
        // sumiria sem deixar rastro. O comando já entrega o cliente de todas as
        // contas; este job não pode desmentir isso uma camada abaixo.
        //
        // O escopo de exclusão lógica fica: cliente apagado da carteira não tem
        // buraco a recuperar, e o que ele traga de fora é nada, não erro.
        $client = Client::query()
            ->withoutGlobalScope('account')
            ->find($this->clientId);

        if ($client === null) {
            $this->reportMissingClient();

            return;
        }

        // A conta do cliente passa a ser a conta corrente **por esta execução**,
        // e volta ao que era ao fim dela.
        //
        // Adotar é o que torna coerente o resto do caminho — o cursor lido e as
        // lacunas lidas — com o cliente que este job está reconciliando, em vez
        // de cada consulta repetir um `where('account_id')` embaixo de uma conta
        // alheia. O caminho abaixo roda sem o middleware de tenant, que é o worker
        // de fila, e a conta tem de vir do cliente justamente por isso.
        //
        // Devolver em `finally` é o que impede a adoção de virar resíduo: o
        // `queue:work` é longo e o singleton não é zerado entre jobs, então
        // deixar a conta do cliente aqui faria o próximo job que não adota conta
        // própria — a captura é um deles — não achar o próprio cliente e encerrar
        // em silêncio. O job seguinte começa do estado que esperava, e não do
        // que este deixou.
        $tenant = resolve(CurrentTenant::class);
        $previous = $tenant->accountId;

        try {
            $tenant->accountId = (int) $client->account_id;

            $reconciliation->run($client, $this->source);
        } finally {
            $tenant->accountId = $previous;
        }
    }

    /**
     * O `null` da busca acima tem duas causas e elas não se confundem.
     *
     * A linha apagada é o caso previsto: o cliente saiu da carteira entre o
     * despacho e a execução, não há documento a recuperar e nada a dizer — a
     * lacuna dele cai junto, em cascata, e a noite segue. A linha que não está
     * lá de jeito nenhum é outra coisa: ninguém na carteira é dono daquele id, e
     * uma noite em que a reconciliação não fez nada precisa ter uma linha que
     * diga por quê.
     *
     * A terceira causa — a linha viva que a busca não devolveu — seria defeito
     * e não estado, e por isso avisa junto: ela só existiria se alguma outra
     * coisa voltasse a filtrar a consulta, que é exatamente o que esta consulta
     * existe para não deixar acontecer. A pergunta é feita só neste caminho, e
     * só quando a busca já falhou.
     */
    private function reportMissingClient(): void
    {
        $row = Client::withTrashed()
            ->withoutGlobalScope('account')
            ->whereKey($this->clientId)
            ->first();

        if ($row !== null && $row->trashed()) {
            return;
        }

        Log::warning('fiscal.reconciliacao.cliente_ausente', [
            'client_id' => $this->clientId,
            'source' => $this->source->value,
        ]);
    }
}
