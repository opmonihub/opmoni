<?php

namespace App\Console\Commands;

use App\Enums\SerproSyncRunState;
use App\Models\SerproSyncRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * O cão de guarda das execuções: quem disse `running` e parou de produzir
 * prova de vida.
 *
 * Uma execução presa em `running` para sempre é o caso que nenhum desfecho
 * natural cobre — o worker morreu entre um item e outro e nada mais vai
 * escrever nela. O sinal não é o tempo de execução e sim o silêncio: o
 * `updated_at` da execução e o de qualquer item pararam há mais de duas
 * vezes o `timeout` do job.
 *
 * A decisão fica em `failed` porque a execução não entregou o prometido; os
 * itens ficam como estão — `nao_processado` é o que de fato aconteceu com
 * eles — e os contadores não são reescritos, porque recontar mudaria o
 * relatório do que nunca aconteceu.
 *
 * A conta viaja na query e não no singleton: o scheduler roda sem tenant.
 */
class FailAbandonedSerproRuns extends Command
{
    protected $signature = 'serpro:fail-abandoned';

    protected $description = 'Falha execuções de sincronização paradas sem progresso.';

    public function handle(): int
    {
        // Duas vezes o `timeout` de `SyncSerproClientJob` (75s): uma passada
        // mais cedo confundiria o intervalo entre entregas com abandono.
        $limite = now()->subSeconds(150);

        $abandonadas = SerproSyncRun::query()
            ->withoutGlobalScope('account')
            ->where('state', SerproSyncRunState::Running)
            ->where('updated_at', '<', $limite)
            ->whereDoesntHave('items', fn ($query) => $query->where('updated_at', '>=', $limite))
            ->pluck('id', 'account_id')
            ->all();

        foreach ($abandonadas as $accountId => $runId) {
            DB::transaction(function () use ($accountId, $runId): void {
                SerproSyncRun::query()
                    ->withoutGlobalScope('account')
                    ->where('account_id', $accountId)
                    ->whereKey($runId)
                    ->where('state', SerproSyncRunState::Running)
                    ->update([
                        'state' => SerproSyncRunState::Failed->value,
                        'reason' => 'Execução abandonada sem progresso.',
                        'finished_at' => now(),
                    ]);
            });
        }

        return self::SUCCESS;
    }
}
