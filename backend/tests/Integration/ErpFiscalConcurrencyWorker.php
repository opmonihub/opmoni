<?php

declare(strict_types=1);

use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Erp\Fiscal\ErpFiscalProviderResult;
use App\Erp\Fiscal\FakeErpFiscalProvider;
use App\Jobs\ProcessErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'opmoni_test') {
    throw new RuntimeException('Fiscal concurrency worker requires the testing environment.');
}
$app->bind(ErpFiscalProvider::class, FakeErpFiscalProvider::class);

$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
$barrier = (string) ($input['barrier'] ?? '');

while ($barrier !== '' && ! is_file($barrier)) {
    usleep(1000);
}

$result = match ($input['operation'] ?? '') {
    'fiscal-transmit' => fiscalTransmit($input),
    'fiscal-event-submit' => fiscalEventSubmit($input),
    default => ['ok' => false, 'error' => 'unknown operation'],
};

fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));

/** @param array<string, mixed> $input */
function fiscalTransmit(array $input): array
{
    try {
        config(['erp.operating_writes_enabled' => true, 'erp.release_enabled' => true, 'erp.release_mode' => 'production']);
        app()->instance(ErpFiscalProvider::class, new class($input['provider_calls']) implements ErpFiscalProvider
        {
            public function __construct(private string $calls) {}

            public function transmit(ErpFiscalDocument $document, array $payload): ErpFiscalProviderResult
            {
                if (DB::transactionLevel() !== 0) {
                    throw new RuntimeException('Provider call must run outside the acceptance transaction.');
                }
                file_put_contents($this->calls, getmypid()."\n", FILE_APPEND | LOCK_EX);
                $signed = $payload['signed_xml'];
                $dom = new DOMDocument;
                $dom->loadXML($signed, LIBXML_NONET);
                $digest = (new DOMXPath($dom))->query('//*[local-name()="DigestValue"]')->item(0)->textContent;
                $xml = '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'.preg_replace('/^<\?xml[^>]*>\s*/', '', $signed)
                    .'<protNFe versao="4.00"><infProt><tpAmb>2</tpAmb><verAplic>TESTE</verAplic><chNFe>'.$document->access_key.'</chNFe><dhRecbto>2026-10-22T10:00:00-03:00</dhRecbto><nProt>135260000000001</nProt><digVal>'.$digest.'</digVal><cStat>100</cStat><xMotivo>Autorizado o uso</xMotivo></infProt></protNFe></nfeProc>';

                return ErpFiscalProviderResult::authorized('135260000000001', $xml);
            }

            public function consult(ErpFiscalDocument $document): ErpFiscalProviderResult
            {
                throw new RuntimeException('Unexpected consult in first-attempt claim fixture.');
            }

            public function submitEvent(ErpFiscalDocument $document, ErpFiscalEvent $event): ErpFiscalProviderResult
            {
                throw new RuntimeException('Unexpected event in document claim fixture.');
            }

            public function submitInvalidation(ErpFiscalInvalidation $invalidation): ErpFiscalProviderResult
            {
                throw new RuntimeException('Unexpected invalidation in document claim fixture.');
            }
        });
        app()->call([new ProcessErpFiscalAttempt((int) $input['attempt_id']), 'handle']);

        return ['ok' => true, 'backend_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid];
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}

/** @param array<string, mixed> $input */
function fiscalEventSubmit(array $input): array
{
    try {
        return DB::transaction(function () use ($input): array {
            $event = ErpFiscalEvent::query()->whereKey($input['event_id'])->lockForUpdate()->firstOrFail();
            $document = ErpFiscalDocument::query()->whereKey($event->erp_fiscal_document_id)->firstOrFail();
            app(ErpFiscalIssuanceService::class)->submitEvent($event, $document);

            return ['ok' => true];
        });
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => $exception->getMessage()];
    }
}
