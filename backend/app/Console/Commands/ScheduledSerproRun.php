<?php

namespace App\Console\Commands;

use App\Enums\SerproSyncRunTrigger;
use App\Models\Account;
use App\Models\SerproObligationSchedule;
use App\Services\SerproAccountEnablement;
use App\Services\SerproRunStarter;
use Illuminate\Console\Command;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A varredura agendada: no dia do mês marcado para cada documento, cada
 * conta habilitada recebe **uma execução com o escopo dos documentos do
 * dia** — `trigger=scheduled`, sem operador e sem consumir cota manual
 * nenhuma (a cota é da busca manual, e não da sincronização).
 *
 * A agenda é a tabela `serpro_obligation_schedules`: um dia por documento,
 * e documento sem linha é documento sem busca automática. Os documentos do
 * mesmo dia saem numa execução única com o escopo em lista — disparar um
 * por documento encontraria a guarda de uma-execução-por-conta, e o 409 da
 * segunda seria a agenda brigando consigo mesma.
 *
 * A conta sem agenda nenhuma cai no fallback `scheduled_run_day`: quem
 * nunca configurou Settings não pode ver a varredura parar em silêncio.
 *
 * O comando roda diário e decide sozinho o que toca hoje — a agenda em
 * `routes/console.php` não sabe ler tabela nem config por si. Recusas
 * (`422`) são esperadas e relatadas por conta, não abortam as outras: uma
 * credencial quebrada em um escritório não pode apagar o monitoramento dos
 * demais.
 */
class ScheduledSerproRun extends Command
{
    protected $signature = 'serpro:scheduled-run';

    protected $description = 'Dispara a execução agendada de sincronização por documento para cada conta habilitada';

    public function handle(SerproRunStarter $starter): int
    {
        $fallback = max(1, (int) config('integra-contador.scheduled_run_day', 15));
        $dia = today()->day;
        $disparadas = 0;

        foreach (Account::query()->pluck('id') as $accountId) {
            $accountId = (int) $accountId;
            $habilitada = resolve(SerproAccountEnablement::class)->enabled($accountId);

            if (! $habilitada) {
                continue;
            }

            // Os documentos deste dia, juntos numa execução só: o escopo da
            // run é a lista — e a lista pode ter mais de um documento, porque
            // dias repetidos entre obrigações são agenda legítima.
            $agendados = SerproObligationSchedule::query()
                ->where('account_id', $accountId)
                ->get()
                ->groupBy('day');

            $escopoHoje = $agendados->get($dia)?->pluck('obligation')->values()->all() ?? [];

            if ($escopoHoje === [] && $agendados->isNotEmpty()) {
                // A conta que configurou agenda só sincroniza nos dias dela:
                // o dia sem linha é silêncio de propósito.
                continue;
            }

            if ($escopoHoje === [] && $dia !== $fallback) {
                continue;
            }

            try {
                $execucao = $starter->start(
                    $accountId,
                    null,
                    trigger: SerproSyncRunTrigger::Scheduled,
                    obligations: $escopoHoje === [] ? null : $escopoHoje,
                );
                $disparadas++;
                $this->info("Conta {$accountId}: execução #{$execucao->getKey()} disparada para "
                    .($escopoHoje === [] ? 'todas as obrigações' : implode(', ', $escopoHoje)).'.');
            } catch (ValidationException $e) {
                // Conta desligada não chega aqui (o `continue` de cima já a
                // pulou); aqui falam credencial e identidade. São
                // pré-condições da conta, e o remédio é de lá.
                $this->warn("Conta {$accountId}: pulada — ".implode(' ', collect($e->errors())->flatten()->all()));
            } catch (HttpResponseException $e) {
                // Execução já em andamento (409): a de cima venceu a corrida,
                // e a agenda de hoje já está coberta por ela.
                $this->warn("Conta {$accountId}: pulada — já existe execução em andamento.");
            } catch (Throwable $e) {
                $this->error("Conta {$accountId}: falhou — {$e->getMessage()}");
            }
        }

        if ($disparadas === 0) {
            $this->line('Nenhuma execução agendada disparada.');
        }

        return self::SUCCESS;
    }
}
