<?php

namespace Tests\Integration;

use App\Models\Account;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCommercialConcurrencyTest extends TestCase
{
    private const VALID_CNPJ = '30288513000100';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        Artisan::call('migrate:fresh', ['--force' => true]);
        Redis::connection('cache')->flushdb();
        Redis::connection('sessions')->flushdb();
        config(['erp.registration_enabled' => true]);
        $this->seed(PlanSeeder::class);
        User::factory()->create();
        Account::factory()->create();
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/request-worker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);

        return $process;
    }

    /** @return array<string, mixed> */
    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<int, array<string, mixed>> $inputs */
    private function concurrent(array $inputs): array
    {
        $barrier = sys_get_temp_dir().'/opmoni-erp-commercial-'.bin2hex(random_bytes(8));
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

    /** @return array<string, mixed> */
    private function request(array $input): array
    {
        $process = $this->worker($input);
        $process->start();

        return $this->response($process);
    }

    /** @param array<string, mixed> $session */
    private function mutation(array $session, string $path, array $body = [], string $method = 'POST'): array
    {
        return $session + ['method' => $method, 'path' => $path, 'body' => $body];
    }

    /** @return array<string, mixed> */
    private function anonymous(string $ip = '127.0.0.1'): array
    {
        $response = $this->request(['path' => '/api/v1/csrf', 'ip' => $ip]);
        $this->assertSame(200, $response['status']);

        return ['cookies' => $response['cookies'], 'headers' => ['X-CSRF-Token' => $response['body']['csrf_token']], 'ip' => $ip];
    }

    public function test_concurrent_company_registration_creates_single_company_and_subscription(): void
    {
        $payload = [
            'name' => 'Race Owner',
            'email' => 'race-owner@example.test',
            'password' => 'Exact password á 123',
            'company_name' => 'Race Co',
            'cnpj' => self::VALID_CNPJ,
        ];
        $anonymous = $this->anonymous('192.0.2.40');
        $results = $this->concurrent([
            $this->mutation($anonymous, '/api/v1/erp/register', $payload) + ['diagnose_errors' => true],
            $this->mutation($anonymous, '/api/v1/erp/register', $payload) + ['diagnose_errors' => true],
        ]);
        $statuses = array_column($results, 'status');
        $this->assertSame(1, count(array_filter($statuses, fn (int $status): bool => $status === 201)));
        $diagnostics = array_map(fn (array $result): array => ['status' => $result['status'], 'code' => $result['body']['code'] ?? null, 'exceptions' => $result['exception_types'] ?? []], $results);
        $this->assertSame(1, count(array_filter($statuses, fn (int $status): bool => in_array($status, [422, 403, 503], true))), json_encode($diagnostics, JSON_THROW_ON_ERROR));
        foreach ($results as $result) {
            if ($result['status'] === 503) {
                $this->assertSame('service_unavailable', $result['body']['code']);
                $this->assertSame([LockTimeoutException::class], $result['exception_types']);
            }
        }
        $retry = $this->request($this->mutation($this->anonymous('192.0.2.40'), '/api/v1/erp/register', $payload));
        $this->assertSame(422, $retry['status']);
        $this->assertArrayHasKey('email', $retry['body']['errors']);
        $this->assertSame(1, DB::table('erp_companies')->where('cnpj', self::VALID_CNPJ)->count());
        $this->assertSame(1, DB::table('erp_subscriptions')->count());
        $this->assertSame(2, DB::table('users')->count());
    }

    public function test_distinct_sessions_and_emails_cannot_register_the_same_cnpj(): void
    {
        $first = $this->anonymous('192.0.2.41');
        $second = $this->anonymous('192.0.2.42');
        $payload = [
            'name' => 'Company Owner', 'password' => 'Exact password á 123',
            'company_name' => 'Shared Company', 'cnpj' => self::VALID_CNPJ,
        ];
        $results = $this->concurrent([
            $this->mutation($first, '/api/v1/erp/register', $payload + ['email' => 'first-cnpj@example.test']),
            $this->mutation($second, '/api/v1/erp/register', $payload + ['email' => 'second-cnpj@example.test']),
        ]);
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $loser = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 422))[0];
        $this->assertArrayHasKey('cnpj', $loser['body']['errors']);
        $this->assertSame(1, DB::table('erp_companies')->where('cnpj', self::VALID_CNPJ)->count());
        $this->assertSame(1, DB::table('erp_subscriptions')->count());
        $this->assertSame(1, DB::table('users')->whereIn('email', ['first-cnpj@example.test', 'second-cnpj@example.test'])->count());
    }

    public function test_committed_registration_recovers_by_login_after_session_failure(): void
    {
        $payload = [
            'name' => 'Recover Owner',
            'email' => 'recover-erp@example.test',
            'password' => 'Exact password á 123',
            'company_name' => 'Recover Co',
            'cnpj' => '11222333000181',
        ];
        $failed = $this->request($this->mutation($this->anonymous(), '/api/v1/erp/register', $payload) + ['session_write_failure' => true]);
        $this->assertSame(503, $failed['status']);
        $this->assertSame(1, DB::table('users')->where('email', 'recover-erp@example.test')->count());
        $this->assertSame(1, DB::table('erp_companies')->where('cnpj', '11222333000181')->count());
        $login = $this->request($this->mutation($this->anonymous(), '/api/v1/login', [
            'email' => 'recover-erp@example.test',
            'password' => $payload['password'],
        ]));
        $this->assertSame(200, $login['status']);
        $this->assertSame('Recover Co', $login['body']['current_company']['name'] ?? null);
    }
}
