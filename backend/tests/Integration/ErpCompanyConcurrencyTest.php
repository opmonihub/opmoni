<?php

namespace Tests\Integration;

use App\Models\Erp\ErpCompany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCompanyConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
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
        $barrier = sys_get_temp_dir().'/opmoni-erp-race-'.bin2hex(random_bytes(8));
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

    /** @return array<string, mixed> */
    private function bootstrap(): array
    {
        $response = $this->request($this->mutation($this->anonymous(), '/api/v1/register', [
            'name' => 'Company Owner',
            'email' => 'erp-owner@example.test',
            'password' => 'Exact password á 123',
            'company' => 'Office Shell',
            'size' => 'solo',
        ]));
        $this->assertSame(201, $response['status']);
        $csrf = $this->request(['cookies' => $response['cookies'], 'path' => '/api/v1/csrf']);
        $this->assertSame(200, $csrf['status']);

        return ['cookies' => $csrf['cookies'] ?: $response['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']]];
    }

    /** @param array<string, mixed> $session */
    private function refreshSession(array $session, array $response): array
    {
        $cookies = $response['cookies'] ?: $session['cookies'];
        $csrf = $this->request(['cookies' => $cookies, 'path' => '/api/v1/csrf']);

        return ['cookies' => $csrf['cookies'] ?: $cookies, 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']]];
    }

    /** @return array{session: array<string, mixed>, company_a: int, company_b: int} */
    private function ownerWithTwoCompanies(): array
    {
        $session = $this->bootstrap();
        $userId = (int) DB::table('users')->value('id');
        $companyA = DB::table('erp_companies')->insertGetId(['name' => 'Alpha ERP', 'cnpj' => '11222333000181', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $companyB = DB::table('erp_companies')->insertGetId(['name' => 'Beta ERP', 'cnpj' => '30288513000100', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$companyA, $companyB] as $companyId) {
            DB::table('erp_company_memberships')->insert(['erp_company_id' => $companyId, 'user_id' => $userId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        }

        return ['session' => $session, 'company_a' => $companyA, 'company_b' => $companyB];
    }

    public function test_concurrent_company_switch_leaves_one_session_selection(): void
    {
        ['session' => $session, 'company_a' => $a, 'company_b' => $b] = $this->ownerWithTwoCompanies();
        $results = $this->concurrent([
            $this->mutation($session, '/api/v1/erp/company/switch', ['company_id' => $a]),
            $this->mutation($session, '/api/v1/erp/company/switch', ['company_id' => $b]),
        ]);
        foreach ($results as $result) {
            $this->assertContains($result['status'], [200, 403, 503]);
        }
        $successful = array_values(array_filter($results, fn (array $row): bool => $row['status'] === 200));
        $this->assertGreaterThanOrEqual(1, count($successful));
        $selected = (int) $successful[0]['body']['current_company']['id'];
        $this->assertContains($selected, [$a, $b]);
        $session = $this->refreshSession($session, $successful[0]);
        $me = $this->request($session + ['path' => '/api/v1/me']);
        $this->assertSame(200, $me['status']);
        $this->assertSame($selected, (int) $me['body']['current_company']['id']);
    }

    public function test_stale_company_assertion_after_switch_returns_conflict(): void
    {
        ['session' => $session, 'company_a' => $a, 'company_b' => $b] = $this->ownerWithTwoCompanies();
        $switch = $this->request($this->mutation($session, '/api/v1/erp/company/switch', ['company_id' => $b]));
        $this->assertSame(200, $switch['status']);
        $session = $this->refreshSession($session, $switch);
        $stale = $this->request([
            'cookies' => $session['cookies'],
            'path' => '/api/v1/erp/establishments',
            'headers' => array_merge($session['headers'], ['X-Company-Id' => (string) $a]),
        ]);
        $this->assertSame(409, $stale['status']);
        $this->assertSame('company_context_changed', $stale['body']['code']);
        $this->assertSame(2, ErpCompany::query()->count());
    }

    public function test_operational_request_denied_when_membership_removed_during_company_lock(): void
    {
        ['session' => $session, 'company_a' => $companyId] = $this->ownerWithTwoCompanies();
        $userId = (int) DB::table('users')->value('id');
        $switch = $this->request($this->mutation($session, '/api/v1/erp/company/switch', ['company_id' => $companyId]));
        $this->assertSame(200, $switch['status']);
        $session = $this->refreshSession($session, $switch);

        $base = sys_get_temp_dir().'/opmoni-erp-membership-'.bin2hex(random_bytes(8));
        $holder = $this->worker(['database_operation' => 'hold-erp-company', 'company_id' => $companyId, 'ready' => $base.'.ready', 'release' => $base.'.release']);
        $holder->start();
        try {
            $deadline = microtime(true) + 10;
            while (! file_exists($base.'.ready')) {
                $this->assertLessThan($deadline, microtime(true), 'Holder must acquire the company row lock.');
                usleep(10000);
            }
            $operational = $this->worker($session + [
                'path' => '/api/v1/erp/establishments',
                'headers' => array_merge($session['headers'], ['X-Company-Id' => (string) $companyId]),
            ]);
            $operational->start();
            $blocked = false;
            $pollUntil = microtime(true) + 1;
            while (microtime(true) < $pollUntil) {
                if (! $operational->isRunning()) {
                    break;
                }
                $blocked = true;
                usleep(5000);
            }
            $this->assertTrue($blocked, 'Operational request must wait on the company row lock.');
            DB::table('erp_company_memberships')->where('erp_company_id', $companyId)->where('user_id', $userId)->delete();
            touch($base.'.release');
            $response = $this->response($operational);
            $this->assertSame(403, $response['status']);
            $this->assertSame('membership_required', $response['body']['code']);
            $this->assertSame(204, $this->response($holder)['status']);
        } finally {
            touch($base.'.release');
            $holder->stop();
            @unlink($base.'.ready');
            @unlink($base.'.release');
        }
    }

    public function test_committed_company_suspension_blocks_concurrent_selection(): void
    {
        ['session' => $session, 'company_a' => $companyId, 'company_b' => $otherCompanyId] = $this->ownerWithTwoCompanies();
        $results = $this->concurrent([
            $this->mutation($session, '/api/v1/erp/company/switch', ['company_id' => $companyId]),
            ['database_operation' => 'suspend-erp-company', 'company_id' => $companyId],
        ]);
        $this->assertContains($results[0]['status'], [200, 403]);
        $this->assertSame(204, $results[1]['status']);
        $this->assertSame('suspended', DB::table('erp_companies')->where('id', $companyId)->value('status'));
        $denied = $this->request($this->mutation($session, '/api/v1/erp/company/switch', ['company_id' => $companyId]));
        $this->assertSame(403, $denied['status']);
        $allowed = $this->request($this->mutation($session, '/api/v1/erp/company/switch', ['company_id' => $otherCompanyId]));
        $this->assertSame(200, $allowed['status']);
    }
}
