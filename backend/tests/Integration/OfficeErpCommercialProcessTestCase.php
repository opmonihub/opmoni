<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

abstract class OfficeErpCommercialProcessTestCase extends TestCase
{
    private const PRESERVED_TABLES = [
        'accounts', 'account_memberships', 'users', 'subscriptions', 'plans', 'departments', 'clients',
        'office_erp_sales_plans', 'office_erp_sales_plan_revisions', 'office_erp_contracts',
        'office_erp_contract_revisions', 'office_erp_licence_allocations',
    ];

    private const SOURCES = [
        'composer.lock',
        'app/Erp/Commercial/OfficeErpAdmission.php',
        'app/Erp/Commercial/OfficeErpExecutionContext.php',
        'app/Erp/Commercial/OfficeErpLicenceAllocator.php',
        'app/Erp/Commercial/OfficeErpRecords.php',
        'app/Models/Erp/Commercial/OfficeErpContract.php',
        'app/Models/Erp/Commercial/OfficeErpLicenceAllocation.php',
        'tests/Integration/OfficeErpCommercialConcurrencyTest.php',
        'tests/Integration/OfficeErpCommercialProcessTestCase.php',
        'tests/Integration/OfficeErpCommercialConcurrencyWorker.php',
    ];

    /** @var array<int, array{process: Process, base: string, identity: array<string, mixed>}> */
    private array $workers = [];

    /** @var array<string, array{ids: list<int>, hash: string}> */
    private array $retainedRows = [];

    /** @var array{pid: int, database_pid: int, checkout: string, source_hashes: array<string, string>} */
    private array $identity;

    private string $directory;

    private int $workerSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = $this->assertIsolatedStores();
        self::assertSame(0, DB::transactionLevel(), 'Commercial race fixtures must be committed and visible to subprocesses.');
        Http::preventStrayRequests();
        foreach (self::PRESERVED_TABLES as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->retainedRows[$table] = [
                'ids' => array_map('intval', $rows->pluck('id')->all()),
                'hash' => hash('sha256', $rows->toJson()),
            ];
        }

        $this->directory = sys_get_temp_dir().'/opmoni-office-erp-races-'.bin2hex(random_bytes(10));
        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Unable to create the private commercial race signal directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(1);
            }
        }

        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }

        foreach ($this->retainedRows as $table => $baseline) {
            $rows = $baseline['ids'] === []
                ? collect()
                : DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get();
            self::assertSame($baseline['hash'], hash('sha256', $rows->toJson()), 'Pre-existing rows changed: '.$table);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function commercialFixture(int $licenceLimit): array
    {
        self::assertSame(0, DB::transactionLevel());
        $tag = bin2hex(random_bytes(8));
        $account = Account::factory()->create(['name' => 'Office ERP race '.$tag, 'status' => 'active']);
        $plan = Plan::factory()->create([
            'erp_commercial' => ['enabled' => true, 'licence_limit' => $licenceLimit, 'module_keys' => ['purchases']],
        ]);
        $subscription = Subscription::query()->where('account_id', $account->id)->firstOrFail();
        $subscription->forceFill(['plan_id' => $plan->id, 'status' => 'active'])->save();
        $actor = User::factory()->create(['email' => 'office-erp-race-'.$tag.'@example.test']);
        $membership = AccountMembership::factory()->create([
            'account_id' => $account->id,
            'user_id' => $actor->id,
            'role' => 'admin',
        ]);
        [$context, $contracts] = $this->selectOffice($account, $membership, function () use ($account, $actor, $tag): array {
            $context = OfficeErpExecutionContext::officeAdmin($account, $actor, 'commercial-race:'.$tag);
            $contracts = [
                $this->acceptedContract($context, $tag.'-first'),
                $this->acceptedContract($context, $tag.'-second'),
            ];

            return [$context, $contracts];
        });

        return [
            'account_id' => (int) $account->id,
            'actor_id' => (int) $actor->id,
            'membership_id' => (int) $membership->id,
            'context' => $context,
            'account' => $account,
            'actor' => $actor,
            'membership' => $membership,
            'contracts' => $contracts,
            'contract_ids' => array_map(static fn (OfficeErpContract $contract): int => (int) $contract->id, $contracts),
            'second_contract_snapshot' => $contracts[1]->getAttributes(),
        ];
    }

    /** @param array<string, mixed> $fixture */
    protected function officeAdmin(array $fixture, callable $callback): mixed
    {
        $account = Account::query()->findOrFail($fixture['account_id']);
        $membership = AccountMembership::query()
            ->whereKey($fixture['membership_id'])
            ->where('account_id', $fixture['account_id'])
            ->where('user_id', $fixture['actor_id'])
            ->where('role', 'admin')
            ->firstOrFail();

        return $this->selectOffice($account, $membership, $callback);
    }

    /** @param array<string, mixed> $payload */
    protected function worker(string $action, array $payload): Process
    {
        $base = $this->directory.'/worker-'.(++$this->workerSequence);
        $process = new Process(
            [PHP_BINARY, __DIR__.'/OfficeErpCommercialConcurrencyWorker.php'],
            base_path(),
            ['APP_ENV' => 'testing', 'FISCAL_ENVIRONMENT' => 'producao', 'FRONTEND_ORIGIN' => 'http://localhost:3000'],
        );
        $process->setInput(json_encode([...$payload,
            'action' => $action,
            'base' => $base,
            'source_hashes' => $this->identity['source_hashes'],
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $this->workers[spl_object_id($process)] = ['process' => $process, 'base' => $base, 'identity' => []];
        $process->start();
        $this->waitFor($process, 'ready');
        $identity = $this->signalData($process, 'ready');
        self::assertSame($process->getPid(), $identity['pid']);
        self::assertNotSame($this->identity['pid'], $identity['pid']);
        self::assertNotSame($this->identity['database_pid'], $identity['database_pid']);
        foreach ($this->workers as $other) {
            if ($other['process'] !== $process && $other['identity'] !== []) {
                self::assertNotSame($other['identity']['pid'], $identity['pid']);
                self::assertNotSame($other['identity']['database_pid'], $identity['database_pid']);
            }
        }
        $this->workers[spl_object_id($process)]['identity'] = $identity;

        return $process;
    }

    protected function waitFor(Process $process, string $signal): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($this->signal($process, $signal))) {
            if (! $process->isRunning()) {
                self::fail('Worker exited before '.$signal.'; exit='.$process->getExitCode().'; stdout bytes='.strlen($process->getOutput()).'; stderr bytes='.strlen($process->getErrorOutput()));
            }
            if (microtime(true) >= $deadline) {
                self::fail('Commercial worker barrier timed out: '.$signal);
            }
            usleep(10000);
        }
    }

    protected function release(Process $process, string $signal = 'release'): void
    {
        if (! touch($this->signal($process, $signal))) {
            throw new RuntimeException('Unable to release the private commercial worker barrier.');
        }
    }

    /** @return array<string, mixed> */
    protected function signalData(Process $process, string $signal): array
    {
        return json_decode(file_get_contents($this->signal($process, $signal)), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function assertWaitingOnAccountLock(Process $process): void
    {
        $identity = $this->workers[spl_object_id($process)]['identity'];
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne(
                'select wait_event_type, query from pg_stat_activity where pid = ?',
                [$identity['database_pid']],
            );
            if ($activity?->wait_event_type === 'Lock') {
                self::assertStringContainsString('"accounts"', strtolower($activity->query));
                self::assertStringContainsString('for update', strtolower($activity->query));

                return;
            }
            self::assertTrue($process->isRunning(), 'Contender exited before the Account row lock wait.');
            self::assertLessThan($deadline, microtime(true), 'No real PostgreSQL Account row lock wait observed.');
            usleep(10000);
        } while (true);
    }

    /** @return array<string, mixed> */
    protected function workerResult(Process $process): array
    {
        $process->wait();
        self::assertTrue($process->isSuccessful(), 'Worker exit='.$process->getExitCode().'; stderr bytes='.strlen($process->getErrorOutput()));
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $result['transaction_level']);

        return $result;
    }

    /** @return array{pid: int, database_pid: int, checkout: string, source_hashes: array<string, string>} */
    public static function assertIsolatedStores(): array
    {
        $checkout = realpath(base_path('..'));
        if (! app()->environment('testing')
            || app()->configurationIsCached()
            || $checkout !== '/workspace'
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'postgres'
            || config('database.connections.pgsql.database') !== 'opmoni_test'
            || config('cache.default') !== 'redis'
            || config('session.driver') !== 'redis'
            || config('session.connection') !== 'sessions'
            || config('session.store') !== 'sessions'
            || config('database.redis.options.prefix') !== 'opmoni-access-test-') {
            throw new RuntimeException('Commercial races require the guarded private checkout and isolated stores before fixture writes.');
        }

        foreach (self::PRESERVED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Commercial schema is absent; apply the additive private test migrations before this race.');
            }
        }

        $database = DB::selectOne("select current_database() as name, pg_backend_pid() as pid, current_setting('server_version_num')::integer as version");
        if ($database->name !== 'opmoni_test' || $database->version < 180000 || $database->version >= 190000) {
            throw new RuntimeException('Commercial races require the private PostgreSQL 18 opmoni_test database.');
        }
        foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $number) {
            if (config('database.redis.'.$connection.'.host') !== 'redis'
                || (int) config('database.redis.'.$connection.'.database') !== $number) {
                throw new RuntimeException('Unsafe configured commercial race Redis store.');
            }
            $client = Redis::connection($connection)->client();
            if ((int) $client->getDBNum() !== $number
                || $client->getOption(\Redis::OPT_PREFIX) !== 'opmoni-access-test-') {
                throw new RuntimeException('Unsafe connected commercial race Redis store.');
            }
            $redisVersion = $client->info('server')['redis_version'] ?? null;
            if (! is_string($redisVersion) || ! str_starts_with($redisVersion, '8.')) {
                throw new RuntimeException('Commercial races require private Redis 8.');
            }
        }

        $hashes = [];
        foreach (self::SOURCES as $path) {
            $hash = hash_file('sha256', base_path($path));
            if (! is_string($hash)) {
                throw new RuntimeException('Commercial race source identity unavailable.');
            }
            $hashes[$path] = $hash;
        }

        return [
            'pid' => getmypid(),
            'database_pid' => (int) $database->pid,
            'checkout' => $checkout,
            'source_hashes' => $hashes,
        ];
    }

    private function acceptedContract(OfficeErpExecutionContext $context, string $label): OfficeErpContract
    {
        return $context->run(function () use ($context, $label): OfficeErpContract {
            $salesPlan = OfficeErpSalesPlan::factory()->create([
                'account_id' => $context->accountId,
                'name' => 'Race plan '.$label,
            ]);
            $salesRevision = OfficeErpSalesPlanRevision::factory()->create([
                'account_id' => $context->accountId,
                'sales_plan_id' => $salesPlan->id,
                'module_keys' => ['purchases'],
            ]);
            $salesPlan->forceFill(['current_revision_id' => $salesRevision->id])->save();
            $client = Client::factory()->company()->create(['account_id' => $context->accountId]);
            $contract = OfficeErpContract::factory()->create([
                'account_id' => $context->accountId,
                'client_id' => $client->id,
                'sales_plan_id' => $salesPlan->id,
                'agreement_status' => 'accepted',
                'billing_timezone' => 'America/Sao_Paulo',
            ]);
            $revision = OfficeErpContractRevision::factory()->create([
                'account_id' => $context->accountId,
                'contract_id' => $contract->id,
                'price' => '100.00',
                'currency' => 'BRL',
                'interval_months' => 1,
                'due_in_days' => 10,
                'module_keys' => ['purchases'],
            ]);
            $contract->forceFill(['current_revision_id' => $revision->id])->save();

            return $contract->refresh();
        });
    }

    private function selectOffice(Account $account, AccountMembership $membership, ?callable $callback = null): mixed
    {
        $request = request();
        $previous = $request->attributes->get(CurrentAccount::class);
        $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));

        try {
            return $callback === null ? null : $callback();
        } finally {
            if ($previous === null) {
                $request->attributes->remove(CurrentAccount::class);
            } else {
                $request->attributes->set(CurrentAccount::class, $previous);
            }
        }
    }

    private function signal(Process $process, string $name): string
    {
        return $this->workers[spl_object_id($process)]['base'].'.'.$name;
    }
}
