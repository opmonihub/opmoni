<?php

declare(strict_types=1);

use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Erp\Fiscal\ErpFiscalNumberAllocator;
use App\Erp\Fiscal\ErpFiscalProviderResult;
use App\Models\Erp\Fiscal\ErpFiscalAttempt;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
assertDedicatedStores();
$input = json_decode(stream_get_contents(STDIN) ?: '{}', true, flags: JSON_THROW_ON_ERROR);
config(['erp.operating_writes_enabled' => true, 'erp.release_enabled' => true, 'erp.release_mode' => 'production']);
DB::statement("SET lock_timeout TO '8s'");
DB::statement("SET statement_timeout TO '12s'");
$barrier = (string) ($input['barrier'] ?? '');
waitForFile($barrier);
$identity = ['backend_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid];
$result = match ($input['operation']) {
    'allocate' => ['number' => app(ErpFiscalNumberAllocator::class)->allocate((int) $input['company_id'], (string) $input['model'], (string) $input['environment'], (int) $input['series'], (int) $input['initial_next_number'])],
    'claim' => (function () use ($input): array {
        $attempt = app(ErpFiscalAttemptService::class)->claim((int) $input['attempt_id']);

        return ['claimed' => $attempt !== null, 'token' => $attempt?->lease_token, 'transaction_level' => DB::transactionLevel()];
    })(),
    'work' => (function () use ($input, $app): array {
        config(['queue.default' => 'database', 'queue.connections.database.queue' => $input['queue']]);
        $app->instance(ErpFiscalProvider::class, new class($input) implements ErpFiscalProvider
        {
            public function __construct(private array $input) {}

            public function transmit(ErpFiscalDocument $document, array $payload): ErpFiscalProviderResult
            {
                if (DB::transactionLevel() !== 0) {
                    throw new RuntimeException('Provider boundary retained an application transaction.');
                }
                $dom = new DOMDocument;
                if (! $dom->loadXML($payload['signed_xml'], LIBXML_NONET)) {
                    throw new RuntimeException('Provider did not receive complete signed XML.');
                }
                $xpath = new DOMXPath($dom);
                $legalName = $xpath->query('//*[local-name()="emit"]/*[local-name()="xNome"]')->item(0)?->textContent;
                file_put_contents($this->input['provider_ready'], json_encode([
                    'transaction_level' => DB::transactionLevel(), 'certificate_revision_id' => $payload['certificate_revision_id'],
                    'configuration_revision_id' => $payload['configuration_revision_id'], 'issuer_name' => $legalName,
                    'signed_sha256' => hash('sha256', $payload['signed_xml']),
                ], JSON_THROW_ON_ERROR));
                waitForFile($this->input['provider_release']);

                return ErpFiscalProviderResult::rejected('synthetic isolated provider rejection');
            }

            public function consult(ErpFiscalDocument $document): ErpFiscalProviderResult
            {
                throw new RuntimeException('Unexpected consultation in the first-attempt fixture.');
            }

            public function submitEvent(ErpFiscalDocument $document, ErpFiscalEvent $event): ErpFiscalProviderResult
            {
                throw new RuntimeException('Unexpected event in document fixture.');
            }

            public function submitInvalidation(ErpFiscalInvalidation $invalidation): ErpFiscalProviderResult
            {
                throw new RuntimeException('Unexpected range in document fixture.');
            }
        });
        $exit = Artisan::call('queue:work', ['connection' => 'database', '--queue' => $input['queue'], '--once' => true, '--sleep' => 0, '--timeout' => 25, '--tries' => 1]);
        $attempt = ErpFiscalAttempt::findOrFail((int) $input['attempt_id']);

        return ['exit' => $exit, 'status' => $attempt->status, 'dispatch_attempts' => $attempt->dispatch_attempts, 'transaction_level' => DB::transactionLevel()];
    })(),
    default => throw new RuntimeException('Unsupported isolated fiscal test operation.'),
};
fwrite(STDOUT, json_encode([...$identity, ...$result], JSON_THROW_ON_ERROR));
DB::disconnect();

function waitForFile(string $path): void
{
    $deadline = microtime(true) + 12;
    while ($path !== '' && ! is_file($path) && microtime(true) < $deadline) {
        usleep(10_000);
    }
    if ($path === '' || ! is_file($path)) {
        throw new RuntimeException('Fiscal test barrier deadline expired.');
    }
}

function assertDedicatedStores(): void
{
    if (getenv('ERP_FISCAL_DURABLE_ISOLATED') !== 'task17-20261021' || ! app()->environment('testing')
        || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'opmoni_test'
        || DB::selectOne('select current_database() as name')->name !== 'opmoni_test'
        || config('database.redis.options.prefix') !== 'opmoni-access-test-') {
        throw new RuntimeException('Task17 worker requires its retained dedicated PostgreSQL and Redis namespace.');
    }
    foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $expected) {
        if ((int) config('database.redis.'.$connection.'.database', -1) !== $expected || (int) Redis::connection($connection)->client()->getDBNum() !== $expected) {
            throw new RuntimeException('Task17 worker requires Redis databases 13, 14, and 15.');
        }
    }
}
