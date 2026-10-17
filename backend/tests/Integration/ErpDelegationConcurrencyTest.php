<?php

namespace Tests\Integration;

use App\Models\Erp\ErpOfficeDelegation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpDelegationConcurrencyTest extends TestCase
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
        $barrier = sys_get_temp_dir().'/opmoni-deleg-race-'.bin2hex(random_bytes(8));
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

    /** @return array{session: array<string, mixed>, company_id: int, office_a: int, office_b: int} */
    private function ownerWithOffices(): array
    {
        $session = $this->request($this->mutation($this->anonymous(), '/api/v1/register', [
            'name' => 'Delegation Owner',
            'email' => 'deleg-owner@example.test',
            'password' => 'Exact password á 123',
            'company' => 'Primary Office',
            'size' => 'solo',
        ]));
        $this->assertSame(201, $session['status']);
        $csrf = $this->request(['cookies' => $session['cookies'], 'path' => '/api/v1/csrf']);
        $session = ['cookies' => $csrf['cookies'] ?: $session['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']]];

        $officeA = (int) DB::table('accounts')->value('id');
        $officeB = DB::table('accounts')->insertGetId(['name' => 'Second Office', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $userId = (int) DB::table('users')->value('id');
        DB::table('account_memberships')->insert(['account_id' => $officeB, 'user_id' => $userId, 'role' => 'admin', 'created_at' => now(), 'updated_at' => now()]);

        $companyId = DB::table('erp_companies')->insertGetId(['name' => 'Delegated Co', 'cnpj' => '33683111000107', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_company_memberships')->insert(['erp_company_id' => $companyId, 'user_id' => $userId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);

        return [
            'session' => $session,
            'company_id' => $companyId,
            'office_a' => $officeA,
            'office_b' => $officeB,
        ];
    }

    public function test_concurrent_delegation_replacement_leaves_one_active_office(): void
    {
        ['session' => $session, 'company_id' => $companyId, 'office_a' => $officeA, 'office_b' => $officeB] = $this->ownerWithOffices();
        $results = $this->concurrent([
            $this->mutation($session, '/api/v1/erp/delegations', ['company_id' => $companyId, 'account_id' => $officeA, 'permissions' => ['erp.read']]),
            $this->mutation($session, '/api/v1/erp/delegations', ['company_id' => $companyId, 'account_id' => $officeB, 'permissions' => ['erp.read']]),
        ]);
        foreach ($results as $result) {
            $this->assertContains($result['status'], [201, 503]);
        }
        $this->assertSame(1, ErpOfficeDelegation::query()->where('erp_company_id', $companyId)->where('status', 'active')->count());
        $activeOffice = (int) ErpOfficeDelegation::query()->where('erp_company_id', $companyId)->where('status', 'active')->value('account_id');
        $this->assertContains($activeOffice, [$officeA, $officeB]);
    }

    public function test_concurrent_delegation_is_denied_when_company_is_suspended(): void
    {
        ['session' => $session, 'company_id' => $companyId, 'office_a' => $officeA] = $this->ownerWithOffices();
        $switch = $this->request($this->mutation($session, '/api/v1/erp/company/switch', ['company_id' => $companyId]));
        $this->assertSame(200, $switch['status']);
        $csrf = $this->request(['cookies' => $switch['cookies'] ?: $session['cookies'], 'path' => '/api/v1/csrf']);
        $this->assertSame(200, $csrf['status']);
        $session = ['cookies' => $csrf['cookies'] ?: $switch['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']]];
        $results = $this->concurrent([
            $this->mutation($session, '/api/v1/erp/delegations', ['company_id' => $companyId, 'account_id' => $officeA, 'permissions' => ['erp.read']]),
            ['database_operation' => 'suspend-erp-company', 'company_id' => $companyId],
        ]);
        $this->assertContains($results[0]['status'], [201, 403, 503]);
        $this->assertSame(204, $results[1]['status']);
        $this->assertSame('suspended', DB::table('erp_companies')->where('id', $companyId)->value('status'));
        $denied = $this->request([
            'cookies' => $session['cookies'],
            'path' => '/api/v1/erp/establishments',
            'headers' => array_merge($session['headers'], ['X-Company-Id' => (string) $companyId]),
        ]);
        $this->assertSame(403, $denied['status']);
        $this->assertSame('membership_required', $denied['body']['code']);
    }
}
