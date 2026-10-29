<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class OfficeErpIdentityConcurrencyTest extends TestCase
{
    private array $processes = [];

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        OfficeErpCommercialProcessTestCase::assertIsolatedStores();
        self::assertSame(0, DB::transactionLevel());
        $this->directory = sys_get_temp_dir().'/opmoni-identity-races-'.bin2hex(random_bytes(10));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_owner_addition_waits_for_identity_without_holding_company(): void
    {
        $owner = User::factory()->create();
        $target = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => (string) random_int(10000000000000, 99999999999999)]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id]);
        ErpSubscription::factory()->active()->create(['erp_company_id' => $company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id]);
        $session = $this->login($owner);
        $reset = $this->worker(['database_operation' => 'reset-auth-revision', 'user_id' => $target->id,
            'company_id' => $company->id, 'ready' => $this->signal('reset-ready'), 'release' => $this->signal('reset-release')]);
        $holder = $this->awaitSignal('reset-ready', $reset);
        $addition = $this->worker($session + ['path' => '/api/v1/erp/members', 'method' => 'POST',
            'started' => $this->signal('add-started'),
            'body' => ['user_id' => $target->id, 'role' => 'viewer', 'establishment_scope' => 'all'],
        ]);
        $contender = $this->awaitSignal('add-started', $addition);
        $this->assertWaitingBeforeCompany((int) $contender['database_pid'], (int) $holder['database_pid'], $company->id);
        touch($this->signal('reset-release'));
        self::assertSame(204, $this->workerResponse($reset)['status']);
        self::assertSame(201, $this->workerResponse($addition)['status']);
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $target->id)->count());
    }

    public function test_a_verified_old_password_persists_only_its_obsolete_session_revision(): void
    {
        $target = User::factory()->create();
        $anonymous = $this->anonymous();
        $login = $this->worker($anonymous + ['path' => '/api/v1/login', 'method' => 'POST',
            'body' => ['email' => $target->email, 'password' => 'password'],
            'pause_after_password_proof' => ['ready' => $this->signal('proof-ready'), 'release' => $this->signal('proof-release')]]);
        self::assertSame(0, $this->awaitSignal('proof-ready', $login)['revision']);
        $reset = $this->worker(['database_operation' => 'reset-auth-revision', 'user_id' => $target->id]);
        self::assertSame(204, $this->workerResponse($reset)['status']);
        touch($this->signal('proof-release'));
        $response = $this->workerResponse($login);
        self::assertSame(200, $response['status']);
        self::assertSame(401, $this->request(['path' => '/api/v1/me', 'cookies' => $response['cookies']])['status']);
        self::assertSame(1, $target->fresh()->access_auth_revision);
    }

    public function test_reset_winning_identity_lock_rejects_an_old_password_login(): void
    {
        $target = User::factory()->create();
        $anonymous = $this->anonymous();
        $reset = $this->worker(['database_operation' => 'reset-auth-revision', 'user_id' => $target->id,
            'ready' => $this->signal('reset-ready'), 'release' => $this->signal('reset-release')]);
        $holder = $this->awaitSignal('reset-ready', $reset);
        $login = $this->worker($anonymous + ['path' => '/api/v1/login', 'method' => 'POST',
            'body' => ['email' => $target->email, 'password' => 'password'], 'started' => $this->signal('login-started')]);
        $contender = $this->awaitSignal('login-started', $login);
        $this->assertWaitingBeforeCompany((int) $contender['database_pid'], (int) $holder['database_pid']);
        touch($this->signal('reset-release'));
        self::assertSame(204, $this->workerResponse($reset)['status']);
        self::assertSame(422, $this->workerResponse($login)['status']);
    }

    public function test_a_stale_redis_write_cannot_revive_a_reset_session(): void
    {
        $target = User::factory()->create();
        $session = $this->login($target);
        $stale = $this->worker($session + ['session_operation' => 'stale-save', 'ready' => $this->signal('stale-ready'), 'release' => $this->signal('stale-release')]);
        $this->awaitSignal('stale-ready', $stale);
        self::assertSame(204, $this->request(['database_operation' => 'reset-auth-revision', 'user_id' => $target->id])['status']);
        touch($this->signal('stale-release'));
        self::assertSame(204, $this->workerResponse($stale)['status']);
        self::assertSame(401, $this->request($session + ['path' => '/api/v1/me'])['status']);
    }

    public function test_authentication_store_failure_fails_closed_and_does_not_revive_old_sessions(): void
    {
        $target = User::factory()->create();
        $session = $this->login($target);
        self::assertSame(204, $this->request(['database_operation' => 'reset-auth-revision', 'user_id' => $target->id])['status']);
        self::assertSame(503, $this->request($session + ['path' => '/api/v1/me', 'redis_failure' => true])['status']);
        self::assertSame(401, $this->request($session + ['path' => '/api/v1/me'])['status']);
    }

    public function test_concurrent_registration_reuses_one_company_and_technical_subscription(): void
    {
        $account = Account::factory()->create();
        $actor = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $actor->id, 'role' => 'admin']);
        $client = Client::factory()->company()->create(['account_id' => $account->id]);
        $first = $this->login($actor);
        $second = $this->login($actor);
        $barrier = $this->signal('register-barrier');
        foreach (['first' => $first, 'second' => $second] as $name => $session) {
            $session['headers']['Idempotency-Key'] = 'registration-race-'.bin2hex(random_bytes(6));
            $workers[$name] = $this->worker($session + ['path' => '/api/v1/erp-commercial/companies', 'method' => 'POST',
                'body' => ['client_ids' => [$client->id]], 'barrier' => $barrier, 'started' => $this->signal($name.'-started')]);
        }
        $this->awaitSignal('first-started', $workers['first']);
        $this->awaitSignal('second-started', $workers['second']);
        touch($barrier);
        $results = array_map($this->workerResponse(...), $workers);
        self::assertSame([201, 201], array_column(array_values($results), 'status'));
        $created = $results['first']['body']['data']['created'];
        $created = [...$created, ...$results['second']['body']['data']['created']];
        $unchanged = [...$results['first']['body']['data']['unchanged'], ...$results['second']['body']['data']['unchanged']];
        self::assertCount(1, $created);
        self::assertCount(1, $unchanged);
        self::assertSame($created[0]['company_id'], $unchanged[0]['company_id']);
        self::assertSame(1, OfficeErpCompanyRegistration::query()->where('account_id', $account->id)->count());
        self::assertSame(1, ErpSubscription::query()->where('erp_company_id', $created[0]['company_id'])->count());
        self::assertSame(0, DB::table('office_erp_licence_allocations')->where('account_id', $account->id)->count());
        self::assertSame(0, DB::table('office_erp_invoices')->where('account_id', $account->id)->count());
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/request-worker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $this->processes[] = $process;
        $process->start();

        return $process;
    }

    private function workerResponse(Process $process): array
    {
        $process->wait();
        self::assertTrue($process->isSuccessful(), 'Worker failed; diagnostic fingerprint: '.hash('sha256', $process->getErrorOutput()));

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function request(array $input): array
    {
        return $this->workerResponse($this->worker($input));
    }

    private function anonymous(): array
    {
        $ip = '127.0.0.'.random_int(2, 250);
        $csrf = $this->request(['path' => '/api/v1/csrf', 'ip' => $ip]);
        self::assertSame(200, $csrf['status']);

        return ['cookies' => $csrf['cookies'], 'ip' => $ip, 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']]];
    }

    private function login(User $user): array
    {
        $response = $this->request($this->anonymous() + ['path' => '/api/v1/login', 'method' => 'POST', 'body' => ['email' => $user->email, 'password' => 'password']]);
        self::assertSame(200, $response['status']);
        $csrf = $this->request(['cookies' => $response['cookies'], 'path' => '/api/v1/csrf']);
        $headers = ['X-CSRF-Token' => $csrf['body']['csrf_token']];
        if (isset($response['body']['current_account']['id'])) {
            $headers['X-Account-Id'] = (string) $response['body']['current_account']['id'];
        }
        if (isset($response['body']['current_company']['id'])) {
            $headers['X-Company-Id'] = (string) $response['body']['current_company']['id'];
        }

        return ['cookies' => $csrf['cookies'] ?: $response['cookies'], 'headers' => $headers];
    }

    private function signal(string $name): string
    {
        return $this->directory.'/'.$name;
    }

    private function awaitSignal(string $name, Process $process): array
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($this->signal($name))) {
            if (! $process->isRunning() || microtime(true) >= $deadline) {
                self::fail('Worker stopped or exceeded its signal deadline.');
            }
            usleep(5000);
        }
        $contents = file_get_contents($this->signal($name));

        return $contents === '' ? [] : json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }

    private function assertWaitingBeforeCompany(int $contender, int $holder, ?int $companyId = null): void
    {
        $deadline = microtime(true) + 10;
        do {
            $state = DB::selectOne('select wait_event, ? = ANY(pg_blocking_pids(pid)) as blocked from pg_stat_activity where pid = ?', [$holder, $contender]);
            if ($state?->wait_event === 'advisory' && in_array($state->blocked, [true, 1, 't'], true)) {
                if ($companyId !== null) {
                    self::assertFalse((bool) DB::selectOne("select exists(select 1 from pg_locks where pid = ? and relation = 'erp_companies'::regclass) as held", [$contender])->held);
                }

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        self::fail('Expected an identity advisory wait before Company acquisition.');
    }
}
