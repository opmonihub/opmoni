<?php

namespace App\Services;

use App\Enums\SerproSyncRunState;
use App\Enums\SerproSyncRunTrigger;
use App\Jobs\FanOutSerproRunJob;
use App\Models\Account;
use App\Models\SerproConnection;
use App\Models\SerproSyncRun;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O disparo de uma execução de sincronização.
 *
 * **A guarda "uma por conta" precisa da linha da conta trancada.** Dois
 * POST simultâneos sem lock lêem "nenhuma execução ativa" os dois, criam
 * duas e despacham dois fan-outs — o índice único não existe porque a
 * regra é sobre estado (`queued|running`), e não sobre a tabela inteira. O
 * `lockForUpdate` na linha da `Account` é o ponto de serialização: quem
 * chega segundo espera o primeiro commitar e enxerga a execução dele.
 *
 * **As recusas são de validação, não de autorização nem de erro.** Papel
 * errado é `403` e vem da policy, antes daqui. Flag desligada, credencial
 * ausente e credencial inválida são `422`: a request é bem formada, a
 * pré-condição é que falta, e nenhuma execução nasce para o operador
 * confundir com "em andamento". O `409` é o único conflito: a execução que
 * já corre volta no corpo para a tela poder apontar para ela. A cota de
 * buscas manuais não mora aqui: ela é por cliente×documento e vale no
 * endpoint de busca manual, que lista o estouro par a par.
 *
 * **O fan-out despacha depois do commit.** `afterCommit` garante que o job
 * só sai da fila com a execução gravada; despachar dentro de uma transação
 * que abortasse depois deixaria um job órfão processando uma execução que
 * não existe.
 */
final class SerproRunStarter
{
    /**
     * @throws HttpResponseException 409 quando já existe execução ativa.
     * @throws ValidationException 422 quando flag, credencial ou identidade
     *                             faltam.
     */
    public function start(int $accountId, ?int $userId, ?int $previousRunId = null, SerproSyncRunTrigger $trigger = SerproSyncRunTrigger::Manual, ?array $obligations = null): SerproSyncRun
    {
        return DB::transaction(function () use ($accountId, $userId, $previousRunId, $trigger, $obligations): SerproSyncRun {
            // O lock é na linha da conta, e não na consulta das execuções:
            // a execução que vai nascer ainda não existe para ser trancada.
            Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();

            $this->assertEnabled($accountId);
            $this->assertConnection();

            $active = SerproSyncRun::query()
                ->where('account_id', $accountId)
                ->whereIn('state', [
                    SerproSyncRunState::Queued,
                    SerproSyncRunState::Running,
                ])
                ->first();

            if ($active !== null) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Já existe uma execução em andamento nesta conta.',
                    'run_id' => $active->getKey(),
                ], 409));
            }

            // `account_id` explícito e não o singleton do tenant: este serviço
            // também é chamável do queue/console, onde o singleton é `null` e
            // o escopo global não filtraria nada. `forceFill` porque a chave da
            // conta, o elo para a execução anterior e o escopo de obrigações
            // ficam fora do `#[Fillable]` de propósito — nenhuma request pode
            // declará-los.
            $run = (new SerproSyncRun)->forceFill([
                'account_id' => $accountId,
                'requested_by' => $userId,
                'previous_run_id' => $previousRunId,
                'state' => SerproSyncRunState::Queued,
                'trigger' => $trigger->value,
                // O escopo da execução: a lista de obrigações que ela cobre,
                // ou `null` para "todas as sincronizáveis" — é o que o comando
                // agendado usa para disparar uma run por documento.
                'obligations' => $obligations,
            ]);
            $run->save();

            FanOutSerproRunJob::dispatch($run->getKey(), $accountId)->afterCommit();

            return $run;
        });
    }

    private function assertEnabled(int $accountId): void
    {
        if (! resolve(SerproAccountEnablement::class)->enabled($accountId)) {
            throw ValidationException::withMessages([
                'enabled' => 'A integração está desabilitada para esta conta.',
            ]);
        }
    }

    private function assertConnection(): void
    {
        $connection = SerproConnection::current();

        if ($connection === null || ! $connection->isConfigured()) {
            throw ValidationException::withMessages([
                'connection' => 'A credencial da plataforma não está configurada.',
            ]);
        }

        // Uma credencial que o próprio sistema sabe ser inválida — certificado
        // vencido, documento divergente — é `422` como a ausente, e não `500`:
        // o remédio é reconfigurar, e não repetir.
        try {
            $connection->assertIdentity();
        } catch (SerproException $exception) {
            throw ValidationException::withMessages([
                'connection' => $exception->getMessage(),
            ]);
        }
    }
}
