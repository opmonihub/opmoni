<?php

namespace Tests\Integration;

use App\Actions\AccessAccounts;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MemberAccessConcurrencyTest extends TestCase
{
    /** @var list<Process> */
    private array $workers = [];

    /** @var list<string> */
    private array $signals = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertIsolatedEnvironment();
        Http::preventStrayRequests();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->signals as $signal) {
            @unlink($signal);
        }
        parent::tearDown();
    }

    public static function assertIsolatedEnvironment(): void
    {
        if (app()->environment() !== 'testing'
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'opmoni_test'
            || DB::selectOne('select current_database() as name')->name !== 'opmoni_test'
            || config('cache.default') !== 'redis'
            || config('session.driver') !== 'redis'
            || config('session.store') !== 'sessions') {
            throw new \RuntimeException('Refusing a non-isolated member integration environment.');
        }
        foreach (['default' => '13', 'cache' => '14', 'sessions' => '15'] as $connection => $database) {
            if ((string) config('database.redis.'.$connection.'.database') !== $database
                || (string) Redis::connection($connection)->client()->getDBNum() !== $database) {
                throw new \RuntimeException('Refusing a non-test Redis connection.');
            }
        }
    }

    public function test_busy_email_is_rejected_immediately_with_rollback(): void
    {
        [$account, $admin] = $this->office();
        DB::beginTransaction();
        app(AccessAccounts::class)->advisory('email:busy@example.test');
        $worker = $this->worker('member', [
            'account' => $account->id, 'actor' => $admin->id, 'email' => ' BUSY@example.test ',
        ]);
        $result = $this->workerResult($worker);
        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('email', $result['body']['errors']);
        $this->assertLessThan(1.5, $result['elapsed']);
        DB::rollBack();
        $this->assertSame(0, User::where('email', 'busy@example.test')->count());
        $this->assertSame(1, AccountMembership::count());
    }

    public function test_two_accounts_cannot_claim_the_same_normalized_email(): void
    {
        [$first, $adminA] = $this->office();
        [$second, $adminB] = $this->office();
        $winner = $this->worker('member', [
            'account' => $first->id, 'actor' => $adminA->id, 'email' => ' SAME@example.test ', 'pause' => 'email',
        ]);
        $this->waitFor($winner, 'held');
        $loser = $this->worker('member', ['account' => $second->id, 'actor' => $adminB->id, 'email' => 'same@example.test']);
        $this->assertSame(422, $this->workerResult($loser)['status']);
        $this->release($winner);
        $this->assertSame(201, $this->workerResult($winner)['status']);
        $member = User::where('email', 'same@example.test')->sole();
        $this->assertSame('user', $member->accountRole($first));
        $this->assertNull($member->accountRole($second));
        $this->assertSame(1, AccountMembership::where('user_id', $member->id)->count());
    }

    public function test_member_creation_cannot_steal_an_inflight_owner_reservation(): void
    {
        [$account, $admin] = $this->office();
        $issuer = $this->worker('issue', ['email' => 'reserved@example.test', 'pause' => 'email']);
        $this->waitFor($issuer, 'held');
        $member = ['account' => $account->id, 'actor' => $admin->id, 'email' => ' RESERVED@example.test '];
        $this->assertSame(422, $this->workerResult($this->worker('member', $member))['status']);
        $this->release($issuer);
        $issued = $this->workerResult($issuer);
        $this->assertSame(201, $issued['status']);
        $this->assertSame(422, $this->workerResult($this->worker('member', $member))['status']);
        $this->assertSame(0, User::where('email', 'reserved@example.test')->count());
        $activated = $this->workerResult($this->worker('activate', ['credential' => $issued['credential']]));
        $this->assertSame(201, $activated['status']);
        $owner = User::where('email', 'reserved@example.test')->sole();
        $this->assertSame('admin', $owner->accountRole($issued['account']));
        $this->assertNull($owner->accountRole($account));
    }

    public function test_member_claim_wins_before_platform_reservation_without_creating_an_empty_office(): void
    {
        [$account, $admin] = $this->office();
        $member = $this->worker('member', [
            'account' => $account->id, 'actor' => $admin->id, 'email' => 'winner@example.test', 'pause' => 'email',
        ]);
        $this->waitFor($member, 'held');
        $issuer = $this->worker('issue', ['email' => 'winner@example.test']);
        $this->waitFor($issuer, 'ready');
        $this->waitForDatabaseLock($issuer, (int) file_get_contents($this->signal($issuer, 'ready')), 'pg_advisory_xact_lock');
        $this->release($member);
        $this->assertSame(201, $this->workerResult($member)['status']);
        $this->assertSame(422, $this->workerResult($issuer)['status']);
        $this->assertSame(1, Account::count());
        $this->assertSame(0, DB::table('owner_activations')->count());
        $this->assertSame(1, User::where('email', 'winner@example.test')->count());
    }

    public function test_member_creation_cannot_race_an_owner_activation(): void
    {
        [$account, $admin] = $this->office();
        $issued = $this->workerResult($this->worker('issue', ['email' => 'activation@example.test']));
        $activation = $this->worker('activate', ['credential' => $issued['credential'], 'pause' => 'email']);
        $this->waitFor($activation, 'held');
        $member = $this->worker('member', ['account' => $account->id, 'actor' => $admin->id, 'email' => 'ACTIVATION@example.test']);
        $this->assertSame(422, $this->workerResult($member)['status']);
        $this->release($activation);
        $this->assertSame(201, $this->workerResult($activation)['status']);
        $this->assertSame(1, User::where('email', 'activation@example.test')->count());
        $this->assertSame(1, AccountMembership::where('account_id', $issued['account'])->count());
        $this->assertNotNull(DB::table('owner_activations')->where('account_id', $issued['account'])->value('consumed_at'));
    }

    public function test_inverse_account_email_lock_order_finishes_without_deadlock(): void
    {
        [$account, $admin] = $this->office();
        $member = $this->worker('member', [
            'account' => $account->id, 'actor' => $admin->id, 'email' => 'inverse@example.test', 'pause' => 'account',
        ]);
        $this->waitFor($member, 'held');
        $issuer = $this->worker('issue', ['account' => $account->id, 'email' => 'inverse@example.test']);
        $this->waitFor($issuer, 'ready');
        $pid = (int) file_get_contents($this->signal($issuer, 'ready'));
        $this->waitForDatabaseLock($issuer, $pid, 'accounts');
        $this->release($member);
        $result = $this->workerResult($member);
        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('email', $result['body']['errors']);
        $this->assertSame(409, $this->workerResult($issuer)['status']);
        $this->assertSame(0, User::where('email', 'inverse@example.test')->count());
        $this->assertSame(0, DB::table('owner_activations')->count());
        $this->assertSame(1, AccountMembership::count());
    }

    public function test_postgresql_email_index_collision_returns_422_and_rolls_back(): void
    {
        [$account, $admin] = $this->office();
        $result = $this->workerResult($this->worker('member', [
            'account' => $account->id, 'actor' => $admin->id, 'email' => 'collision@example.test', 'collision' => 'email',
        ]));
        $this->assertSame(422, $result['status']);
        $this->assertArrayHasKey('email', $result['body']['errors']);
        $this->assertSame(0, User::where('email', 'collision@example.test')->count());
        $this->assertSame(1, AccountMembership::count());
    }

    public function test_postgresql_unrelated_unique_collision_is_not_mapped_to_email(): void
    {
        [$account, $admin] = $this->office();
        $result = $this->workerResult($this->worker('member', [
            'account' => $account->id, 'actor' => $admin->id, 'email' => 'primary@example.test', 'collision' => 'primary',
        ]));
        $this->assertSame(503, $result['status']);
        $this->assertArrayNotHasKey('errors', $result['body']);
        $this->assertSame(0, User::where('email', 'primary@example.test')->count());
        $this->assertSame(1, AccountMembership::count());
    }

    /** @return array{Account, User} */
    private function office(): array
    {
        $account = Account::factory()->create();
        $admin = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $admin->id, 'role' => 'admin']);

        return [$account, $admin];
    }

    private function worker(string $action, array $input): Process
    {
        $base = sys_get_temp_dir().'/opmoni-member-race-'.bin2hex(random_bytes(12));
        foreach (['ready', 'held', 'release'] as $suffix) {
            $this->signals[] = $base.'.'.$suffix;
        }
        $code = <<<'PHP'
require 'tests/Integration/bootstrap.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
require 'tests/Integration/MemberAccessConcurrencyTest.php';
Tests\Integration\MemberAccessConcurrencyTest::runWorker($kernel);
PHP;
        $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['FISCAL_ENVIRONMENT' => 'producao']);
        $worker->setInput(json_encode($input + ['action' => $action, 'base' => $base], JSON_THROW_ON_ERROR));
        $worker->setTimeout(20);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    public static function runWorker(Kernel $kernel): void
    {
        self::assertIsolatedEnvironment();
        Http::preventStrayRequests();
        app()->instance('request', Request::create('http://localhost:3000'));
        $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
        $base = $input['base'];
        $pause = $input['pause'] ?? null;
        $paused = false;
        DB::listen(function ($query) use ($input, $base, $pause, &$paused): void {
            $emailLock = $input['action'] === 'member' ? 'pg_try_advisory_xact_lock' : 'pg_advisory_xact_lock';
            $accountLock = str_contains($query->sql, '"accounts"') && str_contains($query->sql, 'for update');
            if ($paused || ! (($pause === 'email' && str_contains($query->sql, $emailLock)) || ($pause === 'account' && $accountLock))) {
                return;
            }
            $paused = true;
            touch($base.'.held');
            $deadline = microtime(true) + 12;
            while (! file_exists($base.'.release')) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Member worker barrier timed out.');
                }
                usleep(10000);
            }
        });
        file_put_contents($base.'.ready', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
        if ($input['action'] === 'issue') {
            try {
                [$account, $link] = app(AccessAccounts::class)->issue([
                    'name' => 'Pending office', 'owner_name' => 'Pending owner', 'login_email' => $input['email'],
                ], $input['account'] ?? null);
                parse_str(parse_url($link['url'], PHP_URL_QUERY), $credential);
                echo json_encode(['status' => 201, 'account' => $account->id, 'credential' => $credential], JSON_THROW_ON_ERROR);
            } catch (ValidationException) {
                echo json_encode(['status' => 422], JSON_THROW_ON_ERROR);
            } catch (HttpException $exception) {
                echo json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR);
            }

            return;
        }
        if (isset($input['collision'])) {
            User::creating(function (User $member) use ($input): void {
                if ($input['collision'] === 'primary') {
                    $member->id = $input['actor'];
                } else {
                    User::withoutEvents(fn () => User::factory()->create(['email' => $member->email]));
                }
            });
        }
        config(['session.cookie' => 'opmoni-session']);
        $session = app('session')->driver();
        $session->start();
        $session->put(['access_deadline' => time() + 43200, 'access_authenticated' => isset($input['actor'])]);
        if (isset($input['actor'])) {
            $session->put([app('auth')->guard('web')->getName() => $input['actor'], 'current_account_id' => $input['account']]);
        }
        $token = $session->token();
        $session->save();
        $cookie = app('encrypter')->encrypt(
            CookieValuePrefix::create('opmoni-session', app('encrypter')->getKey()).$session->getId(), false,
        );
        $body = $input['action'] === 'member'
            ? ['name' => 'Concurrent member', 'email' => $input['email'], 'password' => 'member-password', 'role' => 'user']
            : [...$input['credential'], 'password' => 'owner-password', 'password_confirmation' => 'owner-password'];
        $request = Request::create('http://localhost:3000/api/v1/'.($input['action'] === 'member' ? 'account/members' : 'first-access'), 'POST', [], ['opmoni-session' => $cookie], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_CSRF_TOKEN' => $token,
            'HTTP_X_ACCOUNT_ID' => (string) ($input['account'] ?? ''),
            'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key',
            'HTTP_X_OPMONI_CLIENT_IP' => '192.0.2.55', 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http',
        ], json_encode($body, JSON_THROW_ON_ERROR));
        $started = microtime(true);
        $response = $kernel->handle($request);
        echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'elapsed' => microtime(true) - $started], JSON_THROW_ON_ERROR);
        $kernel->terminate($request, $response);
    }

    private function signal(Process $worker, string $suffix): string
    {
        $index = array_search($worker, $this->workers, true);

        return preg_replace('/\.ready$/', '.'.$suffix, $this->signals[$index * 3]);
    }

    private function waitFor(Process $worker, string $suffix): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($this->signal($worker, $suffix))) {
            $this->assertTrue($worker->isRunning(), $worker->getErrorOutput().$worker->getOutput());
            $this->assertLessThan($deadline, microtime(true), 'Worker barrier timed out: '.$suffix);
            usleep(10000);
        }
    }

    private function release(Process $worker): void
    {
        touch($this->signal($worker, 'release'));
    }

    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());

        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function waitForDatabaseLock(Process $worker, int $pid, string $queryFragment): void
    {
        $deadline = microtime(true) + 5;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne('select wait_event_type, query from pg_stat_activity where pid = ?', [$pid]);
            if ($activity?->wait_event_type === 'Lock') {
                $this->assertStringContainsString($queryFragment, $activity->query);
                if ($queryFragment === 'accounts') {
                    $this->assertStringContainsString('for update', $activity->query);
                }

                return;
            }
            $this->assertTrue($worker->isRunning(), $worker->getErrorOutput().$worker->getOutput());
            $this->assertLessThan($deadline, microtime(true), 'Expected independent Account lock wait.');
            usleep(10000);
        } while (true);
    }
}
