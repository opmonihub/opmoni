<?php

namespace Tests\Integration;

use App\Erp\ErpCompanyInitializer;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpOperationReceipt;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use SessionHandlerInterface;
use Tests\Support\VerifiedIntegrationCheckout;

final class ErpCompanyInitializationConcurrencyWorker
{
    public const SOURCES = [
        'composer.lock',
        'app/Erp/ErpCompanyInitializer.php',
        'app/Erp/ErpCompanyInitializationReconciliation.php',
        'app/Erp/ErpOperationJournal.php',
        'app/Http/Middleware/ResolveCompany.php',
        'tests/Integration/bootstrap.php',
        'tests/Integration/ErpCompanyInitializationConcurrencyTest.php',
        'tests/Integration/ErpCompanyInitializationConcurrencyWorker.php',
        'tests/Support/VerifiedIntegrationCheckout.php',
    ];

    /** @return array<string, mixed> */
    public static function assertIsolated(): array
    {
        if (getenv('ERP_INITIALIZATION_RACES_ISOLATED') !== 'opmoni-erp-template:10.1'
            || ! app()->environment('testing') || app()->configurationIsCached()
            || realpath(base_path('..')) !== getenv('ERP_INITIALIZATION_RACES_CHECKOUT')
            || ! VerifiedIntegrationCheckout::allows(
                (string) realpath(base_path('..')),
                getenv('ERP_INITIALIZATION_RACES_CHECKOUT'),
                getenv('OPMONI_TEST_SOURCE_CHECKOUT'),
                getenv('OPMONI_TEST_SOURCE_IDENTITY'),
                VerifiedIntegrationCheckout::statIdentityFor((string) realpath(base_path('..'))),
            )
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'postgres'
            || config('database.connections.pgsql.database') !== 'opmoni_test'
            || config('database.redis.options.prefix') !== 'opmoni-access-test-'
            || config('cache.default') !== 'redis' || config('session.driver') !== 'redis'
            || config('session.connection') !== 'sessions' || config('session.store') !== 'sessions') {
            throw new RuntimeException('Initialization races require the verified task checkout and isolated stores.');
        }
        $database = DB::selectOne("select current_database() as name, pg_backend_pid() as pid, current_setting('server_version_num')::integer as version");
        if ($database->name !== 'opmoni_test' || $database->version < 180000 || $database->version >= 190000) {
            throw new RuntimeException('Initialization races require PostgreSQL 18 opmoni_test.');
        }
        foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $number) {
            if (config('database.redis.'.$connection.'.host') !== 'redis'
                || (int) config('database.redis.'.$connection.'.database') !== $number) {
                throw new RuntimeException('Unsafe configured initialization race Redis store.');
            }
            $client = Redis::connection($connection)->client();
            if ($client->getDBNum() !== $number || $client->getOption(\Redis::OPT_PREFIX) !== 'opmoni-access-test-'
                || ! str_starts_with($client->info('server')['redis_version'], '8.')) {
                throw new RuntimeException('Unsafe connected initialization race Redis store.');
            }
        }
        $hashes = [];
        foreach (self::SOURCES as $path) {
            $hash = hash_file('sha256', base_path($path));
            if (! is_string($hash)) {
                throw new RuntimeException('Initialization race source identity unavailable.');
            }
            $hashes[$path] = $hash;
        }

        return ['pid' => getmypid(), 'database_pid' => (int) $database->pid, 'checkout' => realpath(base_path('..')), 'source_hashes' => $hashes];
    }

    public static function main(): void
    {
        require __DIR__.'/bootstrap.php';
        $app = require __DIR__.'/../../bootstrap/app.php';
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        $identity = self::assertIsolated();
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        if (($input['source_hashes'] ?? null) !== $identity['source_hashes']) {
            throw new RuntimeException('Parent and worker initialization race sources differ.');
        }
        config(['erp.operating_writes_enabled' => true]);
        Http::preventStrayRequests();
        DB::select("select set_config('application_name', ?, false)", [$input['label']]);
        if (isset($input['company_barrier'])) {
            $announced = false;
            DB::connection()->beforeExecuting(function (string $query) use ($input, $identity, &$announced): void {
                if (! $announced && str_contains($query, 'erp_companies') && str_contains(strtolower($query), 'for update')) {
                    $announced = true;
                    self::signal($input['company_barrier'].'.ready', $identity);
                    self::await($input['company_barrier'].'.release');
                }
            });
        }
        if (($input['commit_before_503'] ?? false) && ($input['fault'] ?? null) !== 'receipt') {
            throw new RuntimeException('Commit-before-503 control requires the receipt boundary.');
        }
        $commitBeforeFailure = false;
        if (isset($input['fault'])) {
            $model = $input['fault'] === 'customer' ? ErpContact::class : ErpOperationReceipt::class;
            $model::created(function (Model $created) use ($input, $identity, &$commitBeforeFailure): void {
                $effectIds = [];
                foreach (['erp_operation_receipts', 'erp_company_setup_states', 'erp_units', 'erp_contacts', 'erp_invoice_schemes', 'erp_invoice_layouts', 'erp_catalog_attributions'] as $table) {
                    $effectIds[$table] = DB::table($table)->where('erp_company_id', $created->erp_company_id)->orderBy('id')->pluck('id')->all();
                }
                self::signal($input['fault_barrier'].'.ready', [...$identity, 'fault_company_id' => $created->erp_company_id, 'fault_effect_ids' => $effectIds]);
                self::await($input['fault_barrier'].'.release');
                if ($input['commit_before_503'] ?? false) {
                    $commitBeforeFailure = true;

                    return;
                }
                abort(503, 'Falha obrigatória de inicialização injetada pelo teste.');
            });
        }
        if (($input['mode'] ?? 'http') === 'initialize') {
            $state = app(ErpCompanyInitializer::class)->initialize(ErpCompany::query()->findOrFail($input['company_id']));
            $result = ['status' => 200, 'body' => ['data' => $state->toArray()], 'cookies' => []];
        } else {
            if ($input['session_write_failure'] ?? false) {
                $session = $app->make('session')->driver();
                $session->setHandler(new ErpInitializationFailingSessionHandler($session->getHandler()));
            }
            $server = [
                'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key',
                'HTTP_X_OPMONI_CLIENT_IP' => $input['ip'], 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http',
            ];
            foreach ($input['headers'] ?? [] as $name => $value) {
                $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
            }
            $request = Request::create('http://localhost:3000'.$input['path'], $input['method'] ?? 'GET', [], $input['cookies'] ?? [], [], $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
            $response = $kernel->handle($request);
            $cookies = [];
            foreach ($response->headers->getCookies() as $cookie) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
            $result = ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'cookies' => $cookies, 'cache_control' => $response->headers->get('Cache-Control')];
            $kernel->terminate($request, $response);
            if ($commitBeforeFailure) {
                if ($result['status'] !== 200 || DB::transactionLevel() !== 0) {
                    throw new RuntimeException('Negative control requires the real operation to commit successfully before reporting 503.');
                }
                $result['status'] = 503;
            }
        }
        echo json_encode([...$identity, ...$result, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
        DB::disconnect();
    }

    /** @param array<string, mixed> $identity */
    private static function signal(string $path, array $identity): void
    {
        if (file_put_contents($path.'.tmp', json_encode($identity, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('Unable to publish initialization race barrier.');
        }
    }

    private static function await(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Initialization race release barrier timed out.');
            }
            usleep(5000);
            clearstatcache(true, $path);
        }
    }
}

final class ErpInitializationFailingSessionHandler implements SessionHandlerInterface
{
    public function __construct(private SessionHandlerInterface $inner) {}

    public function open(string $path, string $name): bool
    {
        return $this->inner->open($path, $name);
    }

    public function close(): bool
    {
        return $this->inner->close();
    }

    public function read(string $id): string|false
    {
        return $this->inner->read($id);
    }

    public function write(string $id, string $data): bool
    {
        throw new RuntimeException('Injected session persistence failure after the operation transaction.');
    }

    public function destroy(string $id): bool
    {
        return $this->inner->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->inner->gc($max_lifetime);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ErpCompanyInitializationConcurrencyWorker::main();
}
