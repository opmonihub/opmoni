<?php

namespace App\Console\Commands;

use App\Enums\FiscalSource;
use App\Jobs\CaptureFiscalDocumentsJob;
use App\Models\Client;
use App\Services\Fiscal\Capture\FiscalCaptureService;
use Illuminate\Console\Command;

final class CaptureFiscalDocuments extends Command
{
    protected $signature = 'fiscal:capture {--source=nfe_distribuicao} {--client=}';

    protected $description = 'Dispara a captura de documentos fiscais por cliente';

    public function handle(FiscalCaptureService $capture): int
    {
        $source = FiscalSource::tryFrom((string) $this->option('source'));

        if ($source === null) {
            $this->error('Fonte inválida.');

            return self::FAILURE;
        }

        // Perguntado ao serviço, e não resolvido aqui: quando o conector do
        // CT-e existir, é a resolução de conector dele que cresce — o comando
        // continua despachando só o que tem conector.
        if (! $capture->hasConnectorFor($source)) {
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
