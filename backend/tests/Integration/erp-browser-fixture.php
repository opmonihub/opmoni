<?php

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

if (PHP_SAPI !== 'cli' || getenv('OPMONI_BROWSER_FIXTURE') !== '1') {
    fwrite(STDERR, "ERP browser fixture is disabled.\n");
    exit(2);
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fixtureDirectory = realpath((string) getenv('OPMONI_BROWSER_DIRECTORY'));
$expectedDirectory = realpath(dirname(__DIR__, 3).'/.superpowers/sdd/2026-10-18-erp-canonical-completion');
$manifestPath = $fixtureDirectory ? $fixtureDirectory.'/task-4-browser-auth.json' : '';

if (! $fixtureDirectory || ! $expectedDirectory || $fixtureDirectory !== $expectedDirectory
    || ! $app->environment('testing')
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== 'postgres'
    || config('database.connections.pgsql.database') !== 'opmoni_test'
    || DB::selectOne('select current_database() as name')->name !== 'opmoni_test') {
    fwrite(STDERR, "ERP browser fixture runtime guard rejected this environment.\n");
    exit(3);
}

foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connectionName => $expectedDatabase) {
    $connection = config('database.redis.'.$connectionName);
    if (! is_array($connection)
        || ($connection['host'] ?? null) !== 'redis'
        || (int) ($connection['database'] ?? -1) !== $expectedDatabase) {
        fwrite(STDERR, "ERP browser fixture Redis guard rejected this environment.\n");
        exit(4);
    }

    try {
        Redis::connection($connectionName)->ping();
    } catch (Throwable) {
        fwrite(STDERR, "ERP browser fixture could not reach its isolated Redis database.\n");
        exit(4);
    }
}

if (is_file($manifestPath)) {
    fwrite(STDERR, "ERP browser fixture manifest already exists; refusing to create duplicate data.\n");
    exit(5);
}

if (($argv[1] ?? '') !== 'init') {
    fwrite(STDERR, "Usage: php erp-browser-fixture.php init\n");
    exit(2);
}

$run = Str::lower(Str::random(8));
$passwords = [
    'owner' => Str::random(40),
    'office_admin' => Str::random(40),
    'manager' => Str::random(40),
    'viewer' => Str::random(40),
];
$temporaryManifestPath = $manifestPath.'.'.bin2hex(random_bytes(6)).'.tmp';

try {
    $fixture = DB::transaction(function () use ($manifestPath, $temporaryManifestPath, $passwords, $run): array {
        app(PlanCatalogSeeder::class)->run();
        $erpPlan = app(ErpCommercialCatalog::class)->ensureBasicPlan();

        $office = Account::factory()->create([
            'name' => 'Task 4 Office '.$run,
            'status' => 'active',
        ]);

        $actors = [];
        foreach ([
            'owner' => 'Task 4 ERP Owner',
            'office_admin' => 'Task 4 Office Admin',
            'manager' => 'Task 4 ERP Manager',
            'viewer' => 'Task 4 ERP Viewer',
        ] as $role => $name) {
            $actors[$role] = User::factory()->create([
                'name' => $name.' '.$run,
                'email' => 'erp-task4-'.$role.'-'.$run.'@example.test',
                'password' => $passwords[$role],
                'is_super_admin' => false,
            ]);
        }

        AccountMembership::factory()->create([
            'account_id' => $office->id,
            'user_id' => $actors['office_admin']->id,
            'role' => 'admin',
        ]);

        $cnpjSeed = random_int(10_000_000_000_000, 99_999_999_999_998);
        $company = ErpCompany::factory()->create([
            'name' => 'Task 4 ERP Company '.$run,
            'cnpj' => (string) $cnpjSeed,
            'status' => 'active',
        ]);
        $secondCompany = ErpCompany::factory()->create([
            'name' => 'Task 4 Second Company '.$run,
            'cnpj' => (string) ($cnpjSeed + 1),
            'status' => 'active',
        ]);

        foreach ([$company, $secondCompany] as $erpCompany) {
            ErpSubscription::factory()->active()->create([
                'erp_company_id' => $erpCompany->id,
                'erp_plan_id' => $erpPlan->id,
            ]);
        }

        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $actors['owner']->id,
            'role' => 'owner',
            'action_permissions' => null,
            'access_all_establishments' => true,
        ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $secondCompany->id,
            'user_id' => $actors['owner']->id,
            'role' => 'owner',
            'action_permissions' => null,
            'access_all_establishments' => true,
        ]);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $actors['office_admin']->id,
            'role' => 'operator',
            'action_permissions' => null,
            'access_all_establishments' => true,
        ]);

        $firstEstablishment = ErpEstablishment::factory()->create([
            'erp_company_id' => $company->id,
            'name' => 'Task 4 Establishment A '.$run,
            'cnpj' => null,
            'status' => 'active',
        ]);
        $secondEstablishment = ErpEstablishment::factory()->create([
            'erp_company_id' => $company->id,
            'name' => 'Task 4 Establishment B '.$run,
            'cnpj' => null,
            'status' => 'active',
        ]);
        $foreignEstablishment = ErpEstablishment::factory()->create([
            'erp_company_id' => $secondCompany->id,
            'name' => 'Task 4 Other Company Establishment '.$run,
            'cnpj' => null,
            'status' => 'active',
        ]);

        $client = app(CurrentTenant::class)->run($office->id, fn () => Client::factory()->company()->for($office)->create([
            'name' => 'Task 4 Office Client '.$run,
        ]));

        $accountSubscription = $office->subscription()->with('plan')->first();
        if (! $accountSubscription || $accountSubscription->status !== 'active' || $accountSubscription->plan?->slug !== 'basico') {
            throw new RuntimeException('The synthetic office must have an active Basic subscription.');
        }

        foreach ([$company, $secondCompany] as $erpCompany) {
            $subscription = $erpCompany->subscription()->with('plan')->first();
            if (! $subscription || $subscription->status !== 'active' || $subscription->plan?->slug !== $erpPlan->slug) {
                throw new RuntimeException('Each synthetic ERP company must have an active Basic subscription.');
            }
        }

        if (AccountMembership::query()->where('user_id', $actors['owner']->id)->exists()
            || ErpCompanyMembership::query()->where('user_id', $actors['manager']->id)->exists()
            || ErpCompanyMembership::query()->where('user_id', $actors['viewer']->id)->exists()) {
            throw new RuntimeException('Synthetic owner, manager, and viewer memberships do not match the acceptance roles.');
        }

        $manifest = [
            'run' => $run,
            'created_at' => now()->toIso8601String(),
            'office' => ['id' => $office->id, 'name' => $office->name],
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'establishments' => [
                    ['id' => $firstEstablishment->id, 'name' => $firstEstablishment->name],
                    ['id' => $secondEstablishment->id, 'name' => $secondEstablishment->name],
                ],
            ],
            'second_company' => [
                'id' => $secondCompany->id,
                'name' => $secondCompany->name,
                'establishment' => ['id' => $foreignEstablishment->id, 'name' => $foreignEstablishment->name],
            ],
            'client' => ['id' => $client->id, 'name' => $client->name],
            'actors' => [],
        ];

        foreach ($actors as $role => $actor) {
            $manifest['actors'][$role] = [
                'id' => $actor->id,
                'name' => $actor->name,
                'email' => $actor->email,
                'password' => $passwords[$role],
            ];
        }

        $contents = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        $previousUmask = umask(0077);
        try {
            $manifestWritten = file_put_contents($temporaryManifestPath, $contents, LOCK_EX) !== false;
        } finally {
            umask($previousUmask);
        }

        if (! $manifestWritten
            || ! chmod($temporaryManifestPath, 0600)
            || ! rename($temporaryManifestPath, $manifestPath)
            || ! chmod($manifestPath, 0600)) {
            throw new RuntimeException('Could not write the private ERP browser fixture manifest.');
        }

        return $manifest;
    });
} catch (Throwable $error) {
    @unlink($temporaryManifestPath);
    @unlink($manifestPath);
    throw $error;
}

fwrite(STDOUT, 'ERP browser fixture created in opmoni_test for run '.$fixture['run'].".\n");
