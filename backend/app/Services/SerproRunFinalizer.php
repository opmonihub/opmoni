<?php

namespace App\Services;

use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;

/**
 * O único escritor dos seis contadores e do desfecho da execução.
 *
 * A recontagem é derivada dos itens a cada chamada, e nunca incrementada:
 * um `synchronized++` espalhado pelos jobs precisaria que toda reentrega
 * soubesse se o item já tinha sido contado, e a informação que decide isso
 * já está no estado do item. Ler o `groupBy` responde à pergunta certa —
 * "como estão os itens agora" — em vez de confiar na soma de deltas.
 *
 * `account_id` é parâmetro e não `CurrentTenant`: o finalizador roda dentro
 * de jobs, onde o singleton carrega o valor do job anterior.
 */
final class SerproRunFinalizer
{
    public function recount(int $runId, int $accountId): void
    {
        /** @var array<string, int> $counts */
        $counts = SerproSyncRunItem::query()
            ->where('account_id', $accountId)
            ->where('run_id', $runId)
            ->selectRaw('state, count(*) as n')
            ->groupBy('state')
            ->pluck('n', 'state')
            ->all();

        $count = fn (SerproSyncItemState $state): int => (int) ($counts[$state->value] ?? 0);

        $run = SerproSyncRun::query()->where('account_id', $accountId)->findOrFail($runId);

        $run->forceFill([
            'synchronized' => $count(SerproSyncItemState::Synchronized),
            'skipped' => $count(SerproSyncItemState::Skipped),
            'failed' => $count(SerproSyncItemState::Failed),
            'indeterminate' => $count(SerproSyncItemState::Indeterminate),
            'not_processed' => $count(SerproSyncItemState::NotProcessed),
            'total' => array_sum($counts),
        ]);

        // `nao_processado` é o que mantém a execução viva: enquanto ele existir
        // a execução corre. E `queued` não é recontada para terminal — uma
        // execução sem itens na fila é uma execução que o fan-out ainda nem
        // abriu, não uma execução concluída.
        if ($count(SerproSyncItemState::NotProcessed) > 0 || $run->state !== SerproSyncRunState::Running) {
            $run->save();

            return;
        }

        $responded = $count(SerproSyncItemState::Synchronized) + $count(SerproSyncItemState::Skipped);
        $errado = $count(SerproSyncItemState::Failed) + $count(SerproSyncItemState::Indeterminate);

        if ($responded === 0 && $errado > 0) {
            // Nenhum cliente entregou nada: a execução que não saiu do lugar
            // é falha, e o motivo fica legível na própria linha.
            $run->forceFill([
                'state' => SerproSyncRunState::Failed,
                'reason' => $run->reason ?? 'Nenhum cliente pôde ser processado.',
                'finished_at' => now(),
            ]);
        } elseif ($errado > 0) {
            $run->forceFill([
                'state' => SerproSyncRunState::Partial,
                'finished_at' => now(),
            ]);
        } else {
            $run->forceFill([
                'state' => SerproSyncRunState::Completed,
                'finished_at' => now(),
            ]);
        }

        $run->save();
    }
}
