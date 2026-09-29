<?php

namespace App\Jobs;

use App\Enums\SerproSyncRunState;
use App\Models\SerproSyncRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * O primeiro passo da execução, depois do commit que a criou.
 *
 * Hoje o job só **reivindica** a execução: a passagem `queued` → `running`
 * é o que separa "na fila" de "em curso" para a guarda de uma-por-conta e
 * para o watchdog, e acontece aqui e não no `SerproRunStarter` porque entre
 * o POST e este job a execução está `queued` de propósito — é o estado que
 * o segundo disparo precisa enxergar para receber `409`.
 *
 * O leque de clientes PJ entra na próxima task do plano; criar os itens
 * agora sem o job filho deixaria uma execução `running` eterna.
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
        $run = SerproSyncRun::query()
            ->where('account_id', $this->accountId)
            ->findOrFail($this->runId);

        if ($run->state !== SerproSyncRunState::Queued) {
            return;
        }

        $run->forceFill([
            'state' => SerproSyncRunState::Running,
            'started_at' => now(),
        ])->save();
    }
}
