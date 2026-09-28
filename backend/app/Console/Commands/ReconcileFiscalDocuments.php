<?php

namespace App\Console\Commands;

use App\Enums\FiscalSource;
use App\Jobs\ReconcileFiscalDocumentsJob;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalReconciliation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class ReconcileFiscalDocuments extends Command
{
    protected $signature = 'fiscal:reconcile {--source=nfe_distribuicao} {--client=}';

    protected $description = 'Dispara a reconciliação das posições gravadas como lacuna de captura';

    public function handle(FiscalReconciliation $reconciliation): int
    {
        $source = FiscalSource::tryFrom((string) $this->option('source'));

        if ($source === null) {
            $this->error('Fonte inválida.');

            return self::FAILURE;
        }

        // Perguntado ao serviço, e não resolvido aqui: quando o conector do
        // CT-e existir, é a resolução de conector que cresce — o comando
        // continua despachando só o que tem conector.
        if (! $reconciliation->hasConnectorFor($source)) {
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
