<?php

namespace App\Services;

use App\Enums\SerproManualSearchMode;
use App\Enums\SerproManualSearchState;
use App\Jobs\RunSerproManualSearchJob;
use App\Models\Account;
use App\Models\SerproManualSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O disparo de buscas manuais sob demanda: um registro por cliente, um job
 * por registro, e a cota contada **antes** de qualquer linha nascer.
 *
 * **A guarda da cota precisa da linha da conta trancada.** Dois POST
 * simultâneos no limite, sem lock, leriam "9 usadas" os dois e o mês
 * terminaria com 11 — o `lockForUpdate` na `Account` é o ponto de
 * serialização, o mesmo desenho do `SerproRunStarter`.
 *
 * **O estouro é por cliente.** A cota é do par `cliente × obrigação`, e um
 * lote em que um cliente estourou não sai inteiro: o `422` lista cada
 * cliente com o que dele já foi usado, para a tela apontar quem bloqueia a
 * busca em massa.
 *
 * **A linha do painel nasce junto.** Sem o registro em `serpro_monitorings`,
 * a busca em curso não teria onde aparecer como "Processando" — associar e
 * pedir busca são passos que o modal faz de uma vez, e o endpoint reflete o
 * par.
 */
final class SerproManualSearchStarter
{
    public function __construct(
        private readonly SerproManualSearchQuota $quota,
        private readonly SerproMonitoringWriter $writer,
    ) {}

    /**
     * Cria um pedido por cliente e devolve as linhas criadas.
     *
     * @param  list<int>  $clientIds
     * @return list<SerproManualSearch>
     *
     * @throws ValidationException 422 com um erro por cliente que estourou a cota.
     */
    public function start(
        int $accountId,
        int $userId,
        string $obligation,
        array $clientIds,
        SerproManualSearchMode $mode = SerproManualSearchMode::Full,
        ?string $recalculateDate = null,
    ): array {
        return DB::transaction(function () use ($accountId, $userId, $obligation, $clientIds, $mode, $recalculateDate): array {
            // O lock da conta serializa os POST concorrentes no limite da cota.
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();

            $estouro = [];
            foreach ($clientIds as $clientId) {
                $usadas = $this->quota->used($accountId, (int) $clientId, $obligation);

                if ($usadas >= $this->quota->limit()) {
                    $estouro[] = ['client_id' => (int) $clientId, 'used' => $usadas];
                }
            }

            if ($estouro !== []) {
                // A chave leva o id do cliente para a tela saber quem bloqueia
                // o lote, e a mensagem carrega o par — a cota não é da conta.
                throw ValidationException::withMessages(array_map(
                    fn (array $cliente): string => sprintf(
                        'Cota mensal de buscas esgotada para este cliente nesta obrigação (%d de %d no mês).',
                        $cliente['used'],
                        $this->quota->limit(),
                    ),
                    array_column($estouro, null, 'client_id'),
                ));
            }

            $criadas = [];
            foreach ($clientIds as $clientId) {
                $clientId = (int) $clientId;

                // `forceFill` porque a conta, o cliente e a autoria ficam fora
                // do `#[Fillable]` de propósito — o vínculo nunca vem do corpo
                // do POST.
                $busca = (new SerproManualSearch)->forceFill([
                    'account_id' => $accountId,
                    'client_id' => $clientId,
                    'obligation' => $obligation,
                    'state' => SerproManualSearchState::Queued,
                    'mode' => $mode,
                    'recalculate_date' => $recalculateDate,
                    'requested_by' => $userId,
                ]);
                $busca->save();
                $criadas[] = $busca;

                // A linha do painel nasce sem dados: é ela que entra na
                // listagem como "Processando" enquanto a busca corre.
                $this->writer->store($accountId, $clientId, $obligation, [], null);

                // Depois do commit: um job na fila antes da transação fechar
                // procuraria uma busca que ainda não existe.
                RunSerproManualSearchJob::dispatch($busca->getKey(), $accountId)->afterCommit();
            }

            return $criadas;
        });
    }
}
