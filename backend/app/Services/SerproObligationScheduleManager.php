<?php

namespace App\Services;

use App\Models\SerproObligationSchedule;
use Illuminate\Support\Facades\DB;

/**
 * A agenda por documento do escritório: qual obrigação sincroniza sozinha e
 * em que dia do mês. É settings da conta com forma de tabela — a unique
 * `(account_id, obligation)` é a agenda em si, e o `replace` é a forma de
 * editar: o formulário de Settings manda a lista inteira, e o que ficou de
 * fora dela deixa de existir.
 *
 * `account_id` é parâmetro e não `CurrentTenant`: o comando agendado lê a
 * agenda de cada conta no console, onde o singleton do tenant não filtra
 * nada.
 */
final class SerproObligationScheduleManager
{
    /**
     * A agenda da conta, na forma que o formulário lê e grava.
     *
     * @return list<array{obligation: string, day: int}>
     */
    public function for(int $accountId): array
    {
        return SerproObligationSchedule::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->orderBy('obligation')
            ->get()
            ->map(fn (SerproObligationSchedule $agenda): array => [
                'obligation' => $agenda->obligation,
                'day' => $agenda->day,
            ])
            ->all();
    }

    /**
     * A lista inteira, no lugar da que existe. A unique absorve o reenvio do
     * mesmo par. A criação sai por `forceFill` e não por `updateOrCreate`:
     * `account_id` fica fora do `#[Fillable]` de propósito, e a atribuição
     * em massa o descartaria.
     *
     * @param  list<array{obligation: string, day: int}>  $agendamentos
     */
    public function replace(int $accountId, array $agendamentos): void
    {
        DB::transaction(function () use ($accountId, $agendamentos): void {
            SerproObligationSchedule::query()
                ->withoutGlobalScope('account')
                ->where('account_id', $accountId)
                ->whereNotIn('obligation', array_column($agendamentos, 'obligation'))
                ->delete();

            foreach ($agendamentos as $agendamento) {
                $agenda = SerproObligationSchedule::query()
                    ->withoutGlobalScope('account')
                    ->where('account_id', $accountId)
                    ->where('obligation', $agendamento['obligation'])
                    ->first();

                if ($agenda === null) {
                    $agenda = (new SerproObligationSchedule)->forceFill([
                        'account_id' => $accountId,
                        'obligation' => $agendamento['obligation'],
                    ]);
                }

                $agenda->day = (int) $agendamento['day'];
                $agenda->save();
            }
        });
    }
}
