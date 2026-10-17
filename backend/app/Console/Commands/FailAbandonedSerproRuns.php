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
            ->whereDoesntHave('items', fn ($query) => $query->withoutGlobalScope('account')->where('updated_at', '>=', $limite))
            ->get(['id', 'account_id']);

        foreach ($abandonadas as $candidate) {
            DB::transaction(function () use ($candidate, $limite): void {
                // The selection is only a hint. Claims and recounts may have progressed while we waited.
                $run = SerproSyncRun::query()
                    ->withoutGlobalScope('account')
                    ->where('account_id', $candidate->account_id)
                    ->whereKey($candidate->getKey())
                    ->lockForUpdate()
                    ->first();

                if ($run === null || $run->state !== SerproSyncRunState::Running
                    || $run->updated_at->gte($limite)
                    || $run->items()->withoutGlobalScope('account')
                        ->where('account_id', $run->account_id)
                        ->where('updated_at', '>=', $limite)->exists()) {
                    return;
                }

                $run->forceFill([
                    'state' => SerproSyncRunState::Failed,
                    'reason' => 'Execução abandonada sem progresso.',
                    'finished_at' => now(),
                ])->save();
            });
        }

        return self::SUCCESS;
    }
}
