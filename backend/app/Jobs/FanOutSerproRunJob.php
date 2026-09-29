<?php

namespace App\Jobs;

use App\Enums\ClientPersonType;
use App\Enums\SerproSyncRunState;
use App\Models\Client;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Services\SerproRunFinalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * O primeiro passo da execução, depois do commit que a criou.
 *
 * O job faz as duas coisas que o POST não pode: a passagem `queued` →
 * `running` — que é o que separa "na fila" de "em curso" para a guarda de
 * uma-por-conta e para o watchdog — e a abertura do leque, um item por
 * cliente PJ da conta. É o estado `queued` que o segundo disparo precisa
 * enxergar para receber `409`, e por isso a reivindicação mora aqui e não
 * no `SerproRunStarter`.
 *
 * PF não vira item de propósito: a spec manda contar só os considerados, e
 * um PF contado como `ignorado` mentiria nos dois sentidos — diria que foi
 * avaliado, e inflaria o `total` de uma execução que nunca o tocaria.
 *
 * A reentrega é idempotente nos dois níveis: a execução que já não está
 * `queued` sai cedo, e o `(run_id, client_id)` já gravado não redespacha —
 * o índice único é a barreira física e a checagem dentro da transação é a
 * que decide de quem é o trabalho.
 */
final class FanOutSerproRunJob implements ShouldQueue
{
    use Queueable;

    /**
     * Três tentativas: falhar a reivindicação não é motivo para desistir da
     * execução, e a linha `queued` que sobra para trás é o que o watchdog
     * recolhe se todas esgotarem.
     */
    public int $tries = 3;

    public function __construct(
        public int $runId,
        public int $accountId,
    ) {}

    public function handle(): void
    {
        // `account_id` explícito: no worker o escopo global do tenant não
        // filtra nada, e o singleton pode estar carregando a conta do job
        // anterior.
        DB::transaction(function (): void {
            $run = SerproSyncRun::query()
                ->where('account_id', $this->accountId)
                ->whereKey($this->runId)
                ->lockForUpdate()
                ->first();

            // Execução apagada, ou reivindicada pela entrega que chegou
            // antes: nos dois casos esta entrega não tem o que fazer.
            if ($run === null || $run->state !== SerproSyncRunState::Queued) {
                return;
            }

            $run->forceFill([
                'state' => SerproSyncRunState::Running,
                'started_at' => now(),
            ])->save();

            $clientes = Client::query()
                ->where('account_id', $this->accountId)
                ->where('person_type', ClientPersonType::Company)
                ->orderBy('id')
                ->pluck('id');

            foreach ($clientes as $clientId) {
                $existe = SerproSyncRunItem::query()
                    ->where('run_id', $this->runId)
                    ->where('client_id', $clientId)
                    ->exists();

                if ($existe) {
                    continue;
                }

                // `forceFill` e não `firstOrCreate`: `run_id` e `client_id`
                // ficam fora do `#[Fillable]` porque são a chave da
                // idempotência, e a atribuição em massa os descartaria.
                (new SerproSyncRunItem)->forceFill([
                    'account_id' => $this->accountId,
                    'run_id' => $this->runId,
                    'client_id' => $clientId,
                ])->save();

                // Depois do commit: um job na fila antes da transação fechar
                // procuraria um item que ainda não existe.
                SyncSerproClientJob::dispatch($this->runId, $this->accountId, $clientId)->afterCommit();
            }
        });

        resolve(SerproRunFinalizer::class)->recount($this->runId, $this->accountId);
    }
}
