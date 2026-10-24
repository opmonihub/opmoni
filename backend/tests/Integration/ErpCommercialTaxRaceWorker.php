<?php

namespace Tests\Integration;

use App\Models\Erp\ErpCompanyProductTaxDefault;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpProductTaxProfile;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleLine;
use App\Models\Erp\Stock\ErpStockMovement;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

final class ErpCommercialTaxRaceWorker
{
    public const SOURCES = [
        'composer.lock', 'tests/Integration/bootstrap.php',
        'tests/Integration/ErpCommercialTaxRaceWorker.php', 'tests/Integration/ErpCommercialTaxRaceTestCase.php',
        'tests/Integration/ErpProductTaxConcurrencyTest.php', 'tests/Integration/ErpOperationNatureConcurrencyTest.php',
        'app/Http/Middleware/ResolveCompany.php', 'app/Http/Middleware/SerializedAccessSession.php',
        'app/Support/AccessTransaction.php', 'app/Support/CurrentCompany.php',
        'app/Erp/ErpCompanyAuthorization.php', 'app/Erp/ErpCompanyAdministration.php',
        'app/Erp/ErpCatalogWriteService.php', 'app/Erp/ErpProductTaxProfiles.php',
        'app/Erp/ErpCompanyProductTaxDefaults.php', 'app/Erp/ErpProductTaxResolver.php',
        'app/Erp/ErpOperationNatureRules.php', 'app/Erp/ErpCfopResolver.php',
        'app/Erp/CfopReference/CfopReferenceCatalog.php', 'app/Erp/CfopReference/Data/cfop-v210-rows.json',
        'app/Http/Controllers/Api/V1/Erp/Catalogs/ErpProductTaxProfileController.php',
        'app/Http/Controllers/Api/V1/Erp/Catalogs/ErpOperationNatureController.php',
        'app/Http/Controllers/Api/V1/Erp/Catalogs/ErpOperationNatureRuleController.php',
        'app/Http/Controllers/Api/V1/Erp/ErpCompanySettingsController.php',
        'app/Erp/ErpOperationJournal.php', 'app/Erp/Sales/ErpCommercialOperation.php',
        'app/Erp/Sales/ErpCommercialDraftService.php', 'app/Erp/Sales/ErpCommercialTaxSnapshot.php',
        'app/Erp/Sales/ErpCommercialTaxReview.php', 'app/Erp/Sales/ErpSaleFiscalReadiness.php',
        'app/Erp/Fiscal/ErpFiscalSaleSourcePrerequisites.php', 'app/Erp/Fiscal/ErpFiscalSourceSnapshotFactory.php',
        'app/Erp/Stock/ErpStockParticipant.php', 'app/Erp/Stock/ErpStockLedger.php',
        'app/Erp/Finance/ErpFinanceParticipant.php',
    ];

    /** @return array<string, mixed> */
    public static function assertIsolated(): array
    {
        if (getenv('ERP_COMMERCIAL_TAX_RACES_ISOLATED') !== 'opmoni-erp-template:10.2+10.4'
            || ! app()->environment('testing') || app()->configurationIsCached()
            || realpath(base_path('..')) !== getenv('ERP_COMMERCIAL_TAX_RACES_CHECKOUT')
            || ! str_starts_with((string) realpath(base_path('..')), '/home/obsidian/dev/opmoni/.worktrees/')
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'postgres'
            || config('database.connections.pgsql.database') !== 'opmoni_test'
            || config('cache.default') !== 'redis' || config('session.driver') !== 'redis'
            || config('session.connection') !== 'sessions' || config('session.store') !== 'sessions'
            || config('database.redis.options.prefix') !== 'opmoni-access-test-') {
            throw new RuntimeException('Tax races require the verified task checkout and isolated stores before fixtures.');
        }
        $database = DB::selectOne("select current_database() as name, pg_backend_pid() as pid, current_setting('server_version_num')::integer as version");
        if ($database->name !== 'opmoni_test' || $database->version < 180000 || $database->version >= 190000) {
            throw new RuntimeException('Tax races require PostgreSQL 18 opmoni_test.');
        }
        foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $number) {
            if (config('database.redis.'.$connection.'.host') !== 'redis'
                || (int) config('database.redis.'.$connection.'.database') !== $number) {
                throw new RuntimeException('Unsafe configured tax race Redis store.');
            }
            $client = Redis::connection($connection)->client();
            if ($client->getDBNum() !== $number || $client->getOption(\Redis::OPT_PREFIX) !== 'opmoni-access-test-'
                || ! str_starts_with($client->info('server')['redis_version'], '8.')) {
                throw new RuntimeException('Unsafe connected tax race Redis store.');
            }
        }
        $hashes = [];
        foreach (self::SOURCES as $path) {
            $hash = hash_file('sha256', base_path($path));
            if (! is_string($hash)) {
                throw new RuntimeException('Tax race source identity unavailable.');
            }
            $hashes[$path] = $hash;
        }

        return ['pid' => getmypid(), 'database_pid' => (int) $database->pid,
            'checkout' => realpath(base_path('..')), 'source_hashes' => $hashes];
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
            throw new RuntimeException('Parent and worker tax race sources differ.');
        }
        Http::preventStrayRequests();
        config(['erp.operating_writes_enabled' => true]);
        DB::select("select set_config('application_name', ?, false)", [$input['label']]);
        $companyLocked = false;
        $announced = false;
        DB::connection()->beforeExecuting(function (string $query) use ($input, $identity, &$announced): void {
            if (isset($input['company_gate']) && ! $announced && str_contains($query, '"erp_companies"') && str_contains(strtolower($query), 'for update')) {
                $announced = true;
                self::signal($input['company_gate'].'.ready', $identity);
                self::await($input['company_gate'].'.release');
            }
        });
        DB::listen(function ($query) use (&$companyLocked): void {
            if (str_contains($query->sql, '"erp_companies"') && str_contains(strtolower($query->sql), 'for update')) {
                $companyLocked = true;
            }
        });
        if (isset($input['hold'])) {
            [$class, $event] = match ($input['hold']['kind']) {
                'profile' => [ErpProductTaxProfile::class, 'updated'],
                'default' => [ErpCompanyProductTaxDefault::class, 'updated'],
                'nature' => [ErpOperationNature::class, 'updated'],
                'save' => [ErpSaleLine::class, 'created'],
                'refresh' => [ErpSale::class, 'updated'],
                'post' => [ErpStockMovement::class, 'created'],
                default => throw new RuntimeException('Unsupported tax race post-write hook.'),
            };
            $held = false;
            Event::listen('eloquent.'.$event.': '.$class, function (Model $row) use ($input, $identity, &$companyLocked, &$held): void {
                $hold = $input['hold'];
                if ($held || (int) $row->erp_company_id !== (int) $input['company_id']) {
                    return;
                }
                if (isset($hold['id']) && (int) $row->id !== (int) $hold['id']) {
                    return;
                }
                if ($hold['kind'] === 'post' && ($row->source_type !== 'erp_sale' || (int) $row->source_id !== (int) $hold['source_id'])) {
                    return;
                }
                if ($hold['kind'] === 'refresh' && ! $row->wasChanged('draft_revision')) {
                    return;
                }
                if (! $companyLocked || DB::transactionLevel() === 0) {
                    throw new RuntimeException('Post-write barrier must retain the native Company transaction.');
                }
                $held = true;
                self::signal($hold['barrier'].'.ready', [...$identity, 'kind' => $hold['kind'],
                    'company_id' => (int) $row->erp_company_id, 'resource_id' => (int) $row->id,
                    'transaction_level' => DB::transactionLevel()]);
                self::await($hold['barrier'].'.release');
            });
        }
        $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key',
            'HTTP_X_OPMONI_CLIENT_IP' => $input['ip'], 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http'];
        foreach ($input['headers'] ?? [] as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create('http://localhost:3000'.$input['path'], $input['method'] ?? 'GET', [],
            $input['cookies'] ?? [], [], $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
        $response = $kernel->handle($request);
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        $kernel->terminate($request, $response);
        echo json_encode([...$identity, 'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true), 'cookies' => $cookies,
            'cache_control' => $response->headers->get('Cache-Control'),
            'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
        DB::disconnect();
    }

    private static function signal(string $path, array $payload): void
    {
        if (file_put_contents($path.'.tmp', json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('Unable to publish tax race barrier.');
        }
    }

    private static function await(string $path): void
    {
        $deadline = microtime(true) + 20;
        while (! is_file($path)) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Tax race release barrier timed out.');
            }
            usleep(5000);
            clearstatcache(true, $path);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ErpCommercialTaxRaceWorker::main();
}
