<?php

namespace Tests\Integration;

use App\Models\Account;
use App\Models\Client;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use App\Tenant\CurrentTenant;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AccessConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertSame('15', (string) config('database.redis.sessions.database'));
        $this->assertSame('14', (string) config('database.redis.cache.database'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        Redis::connection('cache')->flushdb();
        Redis::connection('sessions')->flushdb();
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/request-worker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);

        return $process;
    }

    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function request(array $input): array
    {
        $process = $this->worker($input);
        $process->start();

        return $this->response($process);
    }

    private function concurrent(array $inputs): array
    {
        $barrier = sys_get_temp_dir().'/opmoni-race-'.bin2hex(random_bytes(8));
        $workers = array_map(fn (array $input) => $this->worker($input + ['barrier' => $barrier]), $inputs);
        try {
            foreach ($workers as $worker) {
                $worker->start();
            }
            touch($barrier);

            return array_map(fn (Process $worker) => $this->response($worker), $workers);
        } finally {
            @unlink($barrier);
        }
    }

    private function anonymous(string $ip = '127.0.0.1'): array
    {
        $response = $this->request(['path' => '/api/v1/csrf', 'ip' => $ip]);
        $this->assertSame(200, $response['status']);

        return ['cookies' => $response['cookies'], 'headers' => ['X-CSRF-Token' => $response['body']['csrf_token']], 'ip' => $ip];
    }

    private function mutation(array $session, string $path, array $body = [], string $method = 'POST'): array
    {
        return $session + ['method' => $method, 'path' => $path, 'body' => $body];
    }

    private function authenticated(array $response): array
    {
        $session = ['cookies' => $response['cookies']];
        $csrf = $this->request($session + ['path' => '/api/v1/csrf']);
        $this->assertSame(200, $csrf['status']);

        return ['cookies' => $csrf['cookies'] ?: $session['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']]];
    }

    private function registration(string $email = 'bootstrap@example.test'): array
    {
        return ['name' => 'Initial Owner', 'email' => $email, 'password' => 'Exact password á 123', 'company' => 'Initial Office', 'size' => 'solo'];
    }

    private function bootstrap(): array
    {
        $response = $this->request($this->mutation($this->anonymous(), '/api/v1/register', $this->registration()));
        $this->assertSame(201, $response['status']);

        return $this->authenticated($response);
    }

    public function test_bootstrap_is_serialized_between_independent_postgresql_processes(): void
    {
        $first = $this->anonymous('192.0.2.1');
        $second = $this->anonymous('192.0.2.2');
        $results = $this->concurrent([
            $this->mutation($first, '/api/v1/register', $this->registration('one@example.test')),
            $this->mutation($second, '/api/v1/register', $this->registration('two@example.test')),
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 403], $statuses);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('accounts')->count());
        $this->assertSame(1, DB::table('account_memberships')->count());
        $status = $this->request(['path' => '/api/v1/registration-status']);
        $this->assertFalse($status['body']['registration_available']);
    }

    public function test_owner_activation_is_consumed_once_across_independent_processes(): void
    {
        $admin = $this->bootstrap();
        $issued = $this->request($this->mutation($admin, '/api/v1/admin/accounts', ['name' => 'Second Office', 'owner_name' => 'Second Owner', 'login_email' => 'second@example.test']));
        $this->assertSame(201, $issued['status']);
        parse_str(parse_url($issued['body']['first_access']['url'], PHP_URL_QUERY), $credential);
        $body = $credential + ['password' => 'Owner password á 123', 'password_confirmation' => 'Owner password á 123'];
        $results = $this->concurrent([
            $this->mutation($this->anonymous('192.0.2.3'), '/api/v1/first-access', $body),
            $this->mutation($this->anonymous('192.0.2.4'), '/api/v1/first-access', $body),
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertSame(1, DB::table('users')->where('email', 'second@example.test')->count());
        $this->assertSame(2, DB::table('account_memberships')->count());
        $status = $this->request(['path' => '/api/v1/first-access/status?'.http_build_query($credential)]);
        $this->assertFalse($status['body']['valid']);
    }

    public function test_email_reservations_are_shared_between_platform_sessions(): void
    {
        $first = $this->bootstrap();
        $login = $this->request($this->mutation($this->anonymous('192.0.2.8'), '/api/v1/login', ['email' => 'bootstrap@example.test', 'password' => 'Exact password á 123']));
        $this->assertSame(200, $login['status']);
        $second = $this->authenticated($login);
        $results = $this->concurrent([
            $this->mutation($first, '/api/v1/admin/accounts', ['name' => 'Office A', 'owner_name' => 'Reserved Owner', 'login_email' => 'reserved@example.test']),
            $this->mutation($second, '/api/v1/admin/accounts', ['name' => 'Office B', 'owner_name' => 'Reserved Owner', 'login_email' => ' RESERVED@EXAMPLE.TEST ']),
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertSame(2, DB::table('accounts')->count());
        $this->assertSame(1, DB::table('owner_activations')->where('email', 'reserved@example.test')->count());
    }

    public function test_logout_revocation_survives_a_stale_save_in_another_process(): void
    {
        $session = $this->bootstrap();
        $results = $this->concurrent([
            $session + ['path' => '/api/v1/me'],
            $this->mutation($session, '/api/v1/logout'),
        ]);
        $this->assertContains($results[0]['status'], [200, 401]);
        $this->assertSame(204, $results[1]['status']);
        $replay = $this->request($session + ['path' => '/api/v1/me']);
        $this->assertSame(401, $replay['status']);
    }

    public function test_normalized_email_rate_budget_is_atomic_across_instances(): void
    {
        $inputs = [];
        for ($index = 0; $index < 12; $index++) {
            $inputs[] = $this->mutation($this->anonymous('192.0.2.'.($index + 20)), '/api/v1/login', ['email' => $index % 2 ? 'rate@example.test' : ' RATE@EXAMPLE.TEST ', 'password' => 'wrong']);
        }
        $results = $this->concurrent($inputs);
        $statuses = array_count_values(array_column($results, 'status'));
        $this->assertSame(10, $statuses[422] ?? 0);
        $this->assertSame(2, $statuses[429] ?? 0);
        foreach ($results as $result) {
            if ($result['status'] === 429) {
                $this->assertGreaterThan(0, (int) $result['retry_after']);
            }
        }
    }

    public function test_logout_replay_fails_even_after_stale_redis_state_is_saved(): void
    {
        $session = $this->bootstrap();
        $base = sys_get_temp_dir().'/opmoni-stale-'.bin2hex(random_bytes(8));
        $worker = $this->worker($session + ['session_operation' => 'stale-save', 'ready' => $base.'.ready', 'release' => $base.'.release']);
        $worker->start();
        try {
            $deadline = microtime(true) + 10;
            while (! file_exists($base.'.ready')) {
                $this->assertLessThan($deadline, microtime(true), 'Worker must capture pre-logout state.');
                usleep(10000);
            }
            $logout = $this->request($this->mutation($session, '/api/v1/logout'));
            $this->assertSame(204, $logout['status']);
            touch($base.'.release');
            $this->assertSame(204, $this->response($worker)['status']);
            $replay = $this->request($session + ['path' => '/api/v1/me']);
            $this->assertSame(401, $replay['status']);
            $recovered = $this->request($session + ['path' => '/api/v1/csrf']);
            $this->assertSame(200, $recovered['status']);
            $this->assertIsString($recovered['body']['csrf_token']);
            $this->assertNotEmpty($recovered['body']['csrf_token']);
            $this->assertNotSame($session['headers']['X-CSRF-Token'], $recovered['body']['csrf_token']);

        } finally {
            touch($base.'.release');
            $worker->stop();
            @unlink($base.'.ready');
            @unlink($base.'.release');
        }
    }

    public function test_absolute_authenticated_and_anonymous_deadlines_reject_old_credentials(): void
    {
        $anonymous = $this->anonymous();
        $this->request($anonymous + ['session_operation' => 'expire']);
        $expiredMutation = $this->request($this->mutation($anonymous, '/api/v1/register', $this->registration()));
        $this->assertSame(403, $expiredMutation['status']);
        $this->assertSame(0, DB::table('users')->count());
        $session = $this->bootstrap();
        $this->request($session + ['session_operation' => 'expire']);
        $expiredIdentity = $this->request($session + ['path' => '/api/v1/me']);
        $this->assertSame(401, $expiredIdentity['status']);
    }

    public function test_expired_session_lock_ownership_cannot_commit_a_write(): void
    {
        $session = $this->bootstrap();
        $result = $this->request($this->mutation($session, '/api/v1/integration/lease') + ['fixture' => true]);
        $this->assertSame(503, $result['status']);
        $this->assertSame('Initial Office', DB::table('accounts')->value('name'));
    }

    public function test_member_email_normalization_survives_postgresql_constraints_and_real_login(): void
    {
        $admin = $this->bootstrap();
        $admin['headers']['X-Account-Id'] = (string) DB::table('accounts')->value('id');
        $created = $this->request($this->mutation($admin, '/api/v1/account/members', [
            'name' => 'New Member', 'email' => ' ANA@Example.test ',
            'password' => 'Member password 123', 'role' => 'user',
        ]));
        $this->assertSame(201, $created['status']);
        $this->assertSame('ana@example.test', $created['body']['email']);

        $login = $this->request($this->mutation($this->anonymous('192.0.2.91'), '/api/v1/login', [
            'email' => 'ANA@EXAMPLE.TEST', 'password' => 'Member password 123',
        ]));
        $this->assertSame(200, $login['status']);

        $duplicate = $this->request($this->mutation($admin, '/api/v1/account/members', [
            'name' => 'Duplicate', 'email' => 'Ana@Example.test',
            'password' => 'Another password 123', 'role' => 'user',
        ]));
        $this->assertSame(422, $duplicate['status']);
        $this->assertArrayHasKey('email', $duplicate['body']['errors']);
        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_member_password_longer_than_login_limit_is_rejected_without_creating_identity(): void
    {
        $admin = $this->bootstrap();
        $admin['headers']['X-Account-Id'] = (string) DB::table('accounts')->value('id');
        $rejected = $this->request($this->mutation($admin, '/api/v1/account/members', [
            'name' => 'Invalid Member', 'email' => 'invalid@example.test',
            'password' => str_repeat('a', 129), 'role' => 'user',
        ]));
        $this->assertSame(422, $rejected['status']);
        $this->assertArrayHasKey('password', $rejected['body']['errors']);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('account_memberships')->count());
    }

    public function test_concurrent_recurring_generation_keeps_a_complete_frozen_month(): void
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->run($account->id, function () use ($account): void {
            $template = ProcessTemplate::factory()->create(['account_id' => $account->id]);
            ProcessTemplateTask::factory()->count(2)->create(['account_id' => $account->id, 'template_id' => $template->id]);
            Client::factory()->company()->count(3)->create(['account_id' => $account->id]);
        });

        $results = $this->concurrent([
            ['database_operation' => 'generate-recurrences', 'month' => '2027-02'],
            ['database_operation' => 'generate-recurrences', 'month' => '2027-02'],
        ]);
        $this->assertSame([200, 200], array_column($results, 'status'));
        $this->assertSame(3, DB::table('processes')->count());
        $this->assertSame(3, DB::table('processes')->distinct()->count('client_id'));
        $this->assertSame(6, DB::table('tasks')->count());
        $this->assertSame(0, DB::table('tasks')->where('account_id', '!=', $account->id)->count());
        $this->assertSame(0, DB::table('processes')->whereDate('reference_month', '!=', '2027-02-01')->count());
    }

    public function test_committed_suspension_blocks_subsequent_selection_but_keeps_identity_and_logout(): void
    {
        $member = $this->bootstrap();
        $login = $this->request($this->mutation($this->anonymous('192.0.2.90'), '/api/v1/login', ['email' => 'bootstrap@example.test', 'password' => 'Exact password á 123']));
        $this->assertSame(200, $login['status']);
        $platform = $this->authenticated($login);
        $id = DB::table('accounts')->value('id');
        $results = $this->concurrent([
            $this->mutation($member, '/api/v1/account/switch', ['account_id' => $id]),
            $this->mutation($platform, '/api/v1/admin/accounts/'.$id, ['name' => 'Suspended Office', 'status' => 'suspended'], 'PATCH'),
        ]);
        $this->assertContains($results[0]['status'], [200, 403]);
        $this->assertSame(200, $results[1]['status']);
        $this->assertSame('Suspended Office', DB::table('accounts')->value('name'));
        if ($results[0]['status'] === 200) {
            $member = $this->authenticated($results[0]);
        }
        $rejected = $this->request($this->mutation($member, '/api/v1/account/switch', ['account_id' => $id]));
        $this->assertSame(403, $rejected['status']);
        $this->assertSame('account_suspended', $rejected['body']['code']);
        $this->assertSame(200, $this->request($member + ['path' => '/api/v1/me'])['status']);
        $this->assertSame(204, $this->request($this->mutation($member, '/api/v1/logout'))['status']);
    }

    public function test_either_existing_identity_or_office_disables_bootstrap(): void
    {
        $id = DB::table('accounts')->insertGetId(['name' => 'Office without members', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($this->request(['path' => '/api/v1/registration-status'])['body']['registration_available']);
        $denied = $this->request($this->mutation($this->anonymous(), '/api/v1/register', $this->registration()));
        $this->assertSame(403, $denied['status']);
        $this->assertSame(0, DB::table('users')->count());
        DB::table('accounts')->where('id', $id)->delete();
        DB::table('users')->insert(['name' => 'Identity without office', 'email' => 'identity@example.test', 'password' => Hash::make('password'), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($this->request(['path' => '/api/v1/registration-status'])['body']['registration_available']);
        $denied = $this->request($this->mutation($this->anonymous(), '/api/v1/register', $this->registration()));
        $this->assertSame(403, $denied['status']);
        $this->assertSame(0, DB::table('accounts')->count());
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_session_lock_timeout_is_retryable_without_mutation(): void
    {
        $session = $this->bootstrap();
        $id = CookieValuePrefix::remove(app('encrypter')->decrypt($session['cookies']['opmoni-session'], false));
        $lock = Cache::lock('access-session:'.hash('sha256', $id), 10);
        $this->assertTrue($lock->get());
        try {
            $result = $this->request($this->mutation($session, '/api/v1/logout'));
            $this->assertSame(503, $result['status']);
        } finally {
            $lock->release();
        }
        $this->assertSame(200, $this->request($session + ['path' => '/api/v1/me'])['status']);
    }

    public function test_http_cookie_attributes_and_rotation_prevent_old_id_replay(): void
    {
        $anonymousResponse = $this->request(['path' => '/api/v1/csrf']);
        $this->assertSame(200, $anonymousResponse['status']);
        $cookie = $anonymousResponse['cookie_attributes'][0];
        $this->assertTrue($cookie['http_only']);
        $this->assertSame('lax', $cookie['same_site']);
        $this->assertSame('/', $cookie['path']);
        $this->assertNull($cookie['domain']);
        $anonymous = ['cookies' => $anonymousResponse['cookies'], 'headers' => ['X-CSRF-Token' => $anonymousResponse['body']['csrf_token']]];
        $authenticated = $this->request($this->mutation($anonymous, '/api/v1/register', $this->registration()));
        $this->assertSame(201, $authenticated['status']);
        $this->assertSame(401, $this->request($anonymous + ['path' => '/api/v1/me'])['status']);
        $session = $this->authenticated($authenticated);
        $this->assertSame(200, $this->request($session + ['path' => '/api/v1/me'])['status']);
    }

    public function test_postgresql_constraints_and_migration_reruns(): void
    {
        $this->bootstrap();
        $membership = (array) DB::table('account_memberships')->first();
        unset($membership['id']);
        $this->rejectsDatabaseWrite(fn () => DB::table('account_memberships')->insert($membership), '23505');
        $this->rejectsDatabaseWrite(fn () => DB::table('account_memberships')->insert([...$membership, 'user_id' => 999999]), '23503');
        $this->rejectsDatabaseWrite(fn () => DB::table('account_memberships')->insert([...$membership, 'role' => 'super-admin']), '23514');
        $this->rejectsDatabaseWrite(fn () => DB::table('accounts')->insert(['name' => 'Invalid', 'status' => 'deleted']), '23514');
        $this->rejectsDatabaseWrite(fn () => DB::table('accounts')->insert(['name' => 'Invalid', 'status' => 'active', 'team_size' => 'unknown']), '23514');
        $user = (array) DB::table('users')->first();
        unset($user['id']);
        $this->rejectsDatabaseWrite(fn () => DB::table('users')->insert($user), '23505');
        $this->rejectsDatabaseWrite(fn () => DB::table('users')->insert([...$user, 'email' => 'NONNORMALIZED@EXAMPLE.TEST']), '23514');
        $history = DB::table('migrations')->count();
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame($history, DB::table('migrations')->count());
        $this->assertSame(1, DB::table('users')->count());
    }

    private function rejectsDatabaseWrite(callable $write, string $state): void
    {
        try {
            DB::transaction($write);
            $this->fail('PostgreSQL must reject invalid persisted data.');
        } catch (QueryException $error) {
            $this->assertSame($state, $error->errorInfo[0]);
        }
    }

    public function test_redis_failure_denies_mutations_and_committed_bootstrap_recovers_by_login(): void
    {
        $anonymous = $this->anonymous();
        $denied = $this->request($this->mutation($anonymous, '/api/v1/register', $this->registration()) + ['redis_failure' => true]);
        $this->assertSame(503, $denied['status']);
        $this->assertSame(0, DB::table('users')->count());
        $failedSession = $this->request($this->mutation($anonymous, '/api/v1/register', $this->registration()) + ['session_write_failure' => true]);
        $this->assertSame(503, $failedSession['status']);
        $this->assertSame(1, DB::table('users')->count());
        $this->assertSame(1, DB::table('accounts')->count());
        $login = $this->request($this->mutation($this->anonymous(), '/api/v1/login', ['email' => 'bootstrap@example.test', 'password' => 'Exact password á 123']));
        $this->assertSame(200, $login['status']);
        $session = $this->authenticated($login);
        $this->assertSame(200, $this->request($session + ['path' => '/api/v1/me'])['status']);
    }

    public function test_contended_account_rows_fail_before_proxy_timeout_without_late_changes(): void
    {
        $session = $this->bootstrap();
        $original = DB::table('accounts')->value('id');
        $second = DB::table('accounts')->insertGetId(['name' => 'Contended Office', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('account_memberships')->insert(['account_id' => $second, 'user_id' => DB::table('users')->value('id'), 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        $base = sys_get_temp_dir().'/opmoni-row-lock-'.bin2hex(random_bytes(8));
        $holder = $this->worker(['database_operation' => 'hold-account', 'account_id' => $second, 'ready' => $base.'.ready', 'release' => $base.'.release']);
        $holder->start();
        try {
            $deadline = microtime(true) + 10;
            while (! file_exists($base.'.ready')) {
                $this->assertLessThan($deadline, microtime(true));
                usleep(10000);
            }
            $start = microtime(true);
            $switch = $this->request($this->mutation($session, '/api/v1/account/switch', ['account_id' => $second]));
            $this->assertSame(503, $switch['status']);
            $this->assertLessThan(10, microtime(true) - $start);
            $start = microtime(true);
            $update = $this->request($this->mutation($session, '/api/v1/admin/accounts/'.$second, ['name' => 'Must not commit', 'status' => 'suspended'], 'PATCH'));
            $this->assertSame(503, $update['status']);
            $this->assertLessThan(10, microtime(true) - $start);
            touch($base.'.release');
            $this->assertSame(204, $this->response($holder)['status']);
            $identity = $this->request($session + ['path' => '/api/v1/me']);
            $this->assertSame($original, $identity['body']['current_account']['id']);
            $this->assertSame('Contended Office', DB::table('accounts')->where('id', $second)->value('name'));
            $this->assertSame('active', DB::table('accounts')->where('id', $second)->value('status'));
        } finally {
            touch($base.'.release');
            $holder->stop();
            @unlink($base.'.ready');
            @unlink($base.'.release');
        }
    }

    public function test_committed_activation_recovers_by_login_after_session_persistence_failure(): void
    {
        $admin = $this->bootstrap();
        $issued = $this->request($this->mutation($admin, '/api/v1/admin/accounts', ['name' => 'Recovery Office', 'owner_name' => 'Recovery Owner', 'login_email' => 'recovery@example.test']));
        $this->assertSame(201, $issued['status']);
        parse_str(parse_url($issued['body']['first_access']['url'], PHP_URL_QUERY), $credential);
        $body = $credential + ['password' => 'Recovery exact password á 123', 'password_confirmation' => 'Recovery exact password á 123'];
        $activation = $this->request($this->mutation($this->anonymous(), '/api/v1/first-access', $body) + ['session_write_failure' => true]);
        $this->assertSame(503, $activation['status']);
        $this->assertSame(1, DB::table('users')->where('email', 'recovery@example.test')->count());
        $this->assertSame(2, DB::table('account_memberships')->count());
        $this->assertFalse($this->request(['path' => '/api/v1/first-access/status?'.http_build_query($credential)])['body']['valid']);
        $login = $this->request($this->mutation($this->anonymous(), '/api/v1/login', ['email' => 'recovery@example.test', 'password' => $body['password']]));
        $this->assertSame(200, $login['status']);
        $this->assertSame('Recovery Office', $login['body']['current_account']['name']);
    }
}
