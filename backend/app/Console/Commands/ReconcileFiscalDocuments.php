<?php

namespace App\Console\Commands;

use App\Enums\FiscalSource;
use App\Jobs\ReconcileFiscalDocumentsJob;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class ReconcileFiscalDocuments extends Command
{
    protected $signature = 'fiscal:reconcile {--source=nfe_distribuicao} {--client=}';

    protected $description = 'Dispara a reconciliação das posições gravadas como lacuna de captura';

    /**
     * A volta atrás: despacha uma reconciliação por cliente que tem lacuna na
     * fonte pedida.
     *
     * A fonte padrão é a de NF-e, que é a que a agenda noturna usa — ela não
     * passa `--source`, e por isso nunca alcança a de CT-e por si. Passada
     * `--source=cte_distribuicao`, este comando despacha jobs de CT-e mesmo com a
     * captura desligada, e a chave não é consultada aqui: quem decide é
     * `FiscalReconciliation::run()`, que pula as lacunas de CT-e com
     * `fiscal.cte_enabled` desligada, sem gastar tentativa e sem derrubar a noite
     * de NF-e. A chave é da mesma porta que a captura manual, e é a mesma
     * decisão sobre o mesmo serviço — quem liga a captura liga a volta atrás, e
     * o canário é a primeira das duas.
     */
    public function handle(FiscalConnectorRegistry $connectors): int
    {
        $source = FiscalSource::tryFrom((string) $this->option('source'));

        if ($source === null) {
            $this->error('Fonte inválida.');

            return self::FAILURE;
        }

        // A pergunta vai ao registro, que é o único lugar que sabe qual conector
        // serve a fonte. Um job de fonte sem conector consultaria a posição de um
        // serviço pelo outro, então o comando continua despachando só o que o
        // registro serve.
        if (! $connectors->has($source)) {
            $this->error("A fonte {$source->label()} não tem conector nesta versão.");

            return self::FAILURE;
        }

        // Quem tem lacuna **na fonte pedida**. O escopo de conta sai dos dois
        // lados de propósito: a agenda é um comando de console e a conta
        // corrente é um singleton que o worker de fila nunca zera, então
        // deixar o ambiente decidir quem entra aqui transformaria uma execução
        // parcial em silêncio — um cliente de outra conta simplesmente não
        // seria despachado, e a lacuna dele esperaria o dia seguinte.
        $query = Client::query()
            ->withoutGlobalScope('account')
            ->whereHas('fiscalGaps', fn (Builder $gaps): Builder => $gaps
                ->withoutGlobalScope('account')
                ->where('source', $source->value));

        if ($this->option('client') !== null) {
            $query->whereKey((int) $this->option('client'));
        }

        $dispatched = 0;

        $query->chunkById(100, function ($clients) use ($source, &$dispatched): void {
            foreach ($clients as $client) {
                ReconcileFiscalDocumentsJob::dispatch((int) $client->getKey(), $source);
                $dispatched++;
            }
        });

        $this->info("Reconciliações despachadas: {$dispatched}");

        return self::SUCCESS;
    }
}
