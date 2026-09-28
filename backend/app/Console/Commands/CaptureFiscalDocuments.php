<?php

namespace App\Console\Commands;

use App\Enums\FiscalSource;
use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use Illuminate\Console\Command;

final class CaptureFiscalDocuments extends Command
{
    protected $signature = 'fiscal:capture {--source=nfe_distribuicao} {--client=}';

    protected $description = 'Dispara a captura de documentos fiscais por cliente';

    /**
     * A porta de entrada da carteira na fila: um job por cliente capturável e
     * por fonte pedida.
     *
     * Este comando **não** consulta `fiscal.cte_enabled`, e é de propósito: a
     * chave é da porta HTTP, e o comando é o caminho do canário — rodado à mão,
     * em um cliente só, conferindo o `cStat` do serviço de CT-e antes de existir
     * agenda. Quem tem shell no servidor é a mesma autoridade que liga as
     * chaves, então a checagem aqui não acrescentaria nada e atrapalharia o
     * gate de liberação, que é o canário antes da agenda. A agenda de CT-e
     * (`fiscal.cte_scheduled`, em `routes/console.php`) é a decisão seguinte, e
     * ela também não é conferida junto com a outra: as duas chaves são separadas
     * de propósito.
     */
    public function handle(FiscalConnectorRegistry $connectors): int
    {
        $source = FiscalSource::tryFrom((string) $this->option('source'));

        if ($source === null) {
            $this->error('Fonte inválida.');

            return self::FAILURE;
        }

        // A pergunta vai ao registro, que é o único lugar que sabe qual conector
        // serve a fonte. Um job de fonte sem conector rodaria o conector de outro
        // serviço e arquivaria o documento na fonte errada, então o comando
        // continua despachando só o que o registro serve.
        if (! $connectors->has($source)) {
            $this->error("A fonte {$source->label()} não tem conector nesta versão.");

            return self::FAILURE;
        }

        $query = Client::query()->capturable();

        if ($this->option('client') !== null) {
            $query->whereKey((int) $this->option('client'));
        }

        $dispatched = 0;

        $query->chunkById(100, function ($clients) use ($source, &$dispatched): void {
            foreach ($clients as $client) {
                CaptureFiscalDocumentsJob::dispatch($client->getKey(), $source);
                $dispatched++;
            }
        });

        $this->info("Capturas despachadas: {$dispatched}");

        return self::SUCCESS;
    }
}
