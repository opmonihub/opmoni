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
