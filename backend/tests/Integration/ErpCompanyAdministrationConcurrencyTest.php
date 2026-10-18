<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCompanyAdministrationConcurrencyTest extends TestCase
{
    private User $actor;

    private ErpCompany $company;

    private User $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task2-20261018', getenv('COMPANY_ADMIN_ISOLATED'));
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertStringStartsWith('18.', DB::selectOne('show server_version')->server_version);
        $this->assertStringStartsWith('8.', Redis::connection('cache')->info('server')['redis_version']);
        Artisan::call('migrate:fresh', ['--force' => true]);
        Redis::connection('cache')->flushdb();
        Redis::connection('sessions')->flushdb();
        $this->actor = User::factory()->create();
        $this->company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->actor->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $this->target = User::factory()->create();
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpCompanyAdministrationConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);

        return $result;
    }

    private function request(array $input): array
    {
        return $this->response($this->worker($input));
    }

    private function login(): array
    {
        $csrf = $this->request(['path' => '/api/v1/csrf']);
        $login = $this->request(['path' => '/api/v1/login', 'method' => 'POST', 'cookies' => $csrf['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']], 'body' => ['email' => $this->actor->email, 'password' => 'password']]);
        $this->assertSame(200, $login['status'], json_encode($login['body']));
        $csrf = $this->request(['path' => '/api/v1/csrf', 'cookies' => $login['cookies']]);

        return ['cookies' => $csrf['cookies'] ?: $login['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token'], 'X-Company-Id' => (string) $this->company->id]];
    }

    private function association(array $session): array
    {
        return $session + ['method' => 'POST', 'path' => '/api/v1/erp/members', 'body' => ['user_id' => $this->target->id, 'role' => 'operator', 'establishment_scope' => 'all']];
    }

    private function waitForDatabaseLock(string $label, string $ready): void
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$label])->count;
            if (file_exists($ready) && (int) $waiting === 1) {
                fwrite(STDERR, "Barrier confirmed: {$label}, PID ".file_get_contents($ready).", waiting on Company row lock\n");

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('HTTP worker did not reach the Company lock barrier: '.$label);
    }

    private function race(): array
    {
        $a = $this->login();
        $b = $this->login();
        $this->assertNotSame($a['cookies']['opmoni-session'], $b['cookies']['opmoni-session']);
        $readyA = tempnam(sys_get_temp_dir(), 'company-admin-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'company-admin-b-');
        unlink($readyA);
        unlink($readyB);
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker($this->association($a) + ['label' => 'company-admin-race-a', 'ready' => $readyA]);
            $workerB = $this->worker($this->association($b) + ['label' => 'company-admin-race-b', 'ready' => $readyB]);
            $this->waitForDatabaseLock('company-admin-race-a', $readyA);
            $this->waitForDatabaseLock('company-admin-race-b', $readyB);
            DB::commit();

            return [$this->response($workerA), $this->response($workerB)];
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            @unlink($readyA);
            @unlink($readyB);
        }
    }

    public function test_independent_owner_sessions_create_exactly_one_association(): void
    {
        [$a, $b] = $this->race();
        $statuses = [$a['status'], $b['status']];
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertNotSame($a['pid'], $b['pid']);
        $this->assertDatabaseCount('erp_company_memberships', 2);
        $this->assertSame(1, ErpCompanyMembership::where('user_id', $this->target->id)->count());
    }

    public function test_owner_demoted_while_request_waits_cannot_add_members(): void
    {
        $session = $this->login();
        $ready = tempnam(sys_get_temp_dir(), 'company-admin-demote-');
        unlink($ready);
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $worker = $this->worker($this->association($session) + ['label' => 'company-admin-demote', 'ready' => $ready]);
            $this->waitForDatabaseLock('company-admin-demote', $ready);
            ErpCompanyMembership::where('erp_company_id', $this->company->id)->where('user_id', $this->actor->id)->update(['role' => 'manager']);
            DB::commit();
            $this->assertSame(403, $this->response($worker)['status']);
            $this->assertDatabaseMissing('erp_company_memberships', ['user_id' => $this->target->id]);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            @unlink($ready);
        }
    }

    public function test_lease_loss_and_validation_after_first_insert_roll_back_before_http_rendering(): void
    {
        foreach (['lease' => 503, 'validation' => 422] as $fault => $status) {
            $result = $this->request($this->association($this->login()) + ['fault' => $fault]);
            $this->assertSame($status, $result['status'], json_encode($result['body']));
            $this->assertDatabaseMissing('erp_company_memberships', ['user_id' => $this->target->id]);
            $session = $this->login();
            $result = $this->request($session + ['method' => 'POST', 'path' => '/api/v1/erp/establishments', 'body' => ['name' => 'Rollback branch', 'status' => 'active'], 'fault' => $fault, 'model' => 'establishment']);
            $this->assertSame($status, $result['status']);
            $this->assertDatabaseCount('erp_establishments', 0);
        }
    }

    public function test_lease_loss_after_member_update_and_delete_restores_original_membership(): void
    {
        $member = ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->target->id, 'role' => 'operator']);
        foreach (['updated' => 'PATCH', 'deleted' => 'DELETE'] as $event => $method) {
            $result = $this->request($this->login() + ['method' => $method, 'path' => '/api/v1/erp/members/'.$member->id, 'body' => $method === 'PATCH' ? ['role' => 'manager'] : [], 'fault' => 'lease', 'event' => $event]);
            $this->assertSame(503, $result['status']);
            $this->assertSame('operator', $member->fresh()->role);
        }
        $branch = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id, 'status' => 'active']);
        $result = $this->request($this->login() + ['method' => 'PATCH', 'path' => '/api/v1/erp/establishments/'.$branch->id, 'body' => ['status' => 'inactive'], 'fault' => 'lease', 'event' => 'updated', 'model' => 'establishment']);
        $this->assertSame(503, $result['status']);
        $this->assertSame('active', $branch->fresh()->status);
    }
}
