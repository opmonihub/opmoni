<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpOperationIdentityConcurrencyTest extends TestCase
{
    private User $actor;

    private ErpCompany $company;

    private ErpPayment $payment;

    private ErpObligation $obligation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task1-20261018', getenv('OPERATION_IDENTITY_ISOLATED'));
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
        $this->payment = ErpPayment::create(['erp_company_id' => $this->company->id, 'amount' => '100', 'currency' => 'BRL', 'method' => 'cash', 'status' => 'posted']);
        $this->obligation = ErpObligation::create(['erp_company_id' => $this->company->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '100', 'open_amount' => '100', 'currency' => 'BRL', 'status' => 'open']);
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpOperationIdentityConcurrencyWorker.php')], base_path());
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

        return ['cookies' => $csrf['cookies'] ?: $login['cookies'], 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token'], 'X-Company-Id' => (string) $this->company->id, 'Idempotency-Key' => 'known-before-send']];
    }

    private function allocation(array $session, string $amount = '40'): array
    {
        return $session + ['method' => 'POST', 'path' => '/api/v1/erp/payments/'.$this->payment->id.'/allocate', 'body' => ['erp_obligation_id' => $this->obligation->id, 'amount' => $amount]];
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

    private function race(string $secondAmount): array
    {
        $a = $this->login();
        $b = $this->login();
        $this->assertNotSame($a['cookies']['opmoni-session'], $b['cookies']['opmoni-session']);
        $readyA = tempnam(sys_get_temp_dir(), 'operation-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'operation-b-');
        unlink($readyA);
        unlink($readyB);
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker($this->allocation($a) + ['label' => 'operation-race-a', 'ready' => $readyA]);
            $workerB = $this->worker($this->allocation($b, $secondAmount) + ['label' => 'operation-race-b', 'ready' => $readyB]);
            $this->waitForDatabaseLock('operation-race-a', $readyA);
            $this->waitForDatabaseLock('operation-race-b', $readyB);
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

    public function test_independent_authenticated_sessions_converge_on_one_original_result(): void
    {
        [$a, $b] = $this->race('40');
        $this->assertSame(200, $a['status'], json_encode($a['body']));
        $this->assertSame(200, $b['status'], json_encode($b['body']));
        $this->assertNotSame($a['pid'], $b['pid']);
        $this->assertSame($a['body'], $b['body']);
        $this->assertSame('60.0000', $a['body']['data']['obligation_open_amount']);
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
    }

    public function test_changed_competitor_conflicts_after_the_company_barrier(): void
    {
        [$a, $b] = $this->race('41');
        $statuses = [$a['status'], $b['status']];
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
    }

    public function test_membership_revoked_while_waiting_denies_effect(): void
    {
        $session = $this->login();
        $ready = tempnam(sys_get_temp_dir(), 'operation-revoke-');
        unlink($ready);
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $worker = $this->worker($this->allocation($session) + ['label' => 'operation-revoke', 'ready' => $ready]);
            $this->waitForDatabaseLock('operation-revoke', $ready);
            ErpCompanyMembership::where('erp_company_id', $this->company->id)->delete();
            DB::commit();
            $result = $this->response($worker);
            $this->assertSame(403, $result['status']);
            $this->assertArrayNotHasKey('data', $result['body']);
            $this->assertDatabaseCount('erp_payment_allocations', 0);
            $this->assertDatabaseCount('erp_operation_receipts', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            @unlink($ready);
        }
    }

    public function test_session_lease_loss_and_rendered_failure_after_receipt_leave_no_effect(): void
    {
        foreach (['lose_lease_after_receipt', 'fail_after_receipt', 'lose_lease_after_controller'] as $fault) {
            $result = $this->request($this->allocation($this->login()) + [$fault => true]);
            $this->assertSame(503, $result['status'], json_encode($result['body']));
            $this->assertDatabaseCount('erp_payment_allocations', 0);
            $this->assertDatabaseCount('erp_operation_receipts', 0);
            $this->assertSame('100.0000', $this->obligation->fresh()->open_amount);
        }
    }

    public function test_response_loss_after_commit_recovers_original_result_by_preknown_key(): void
    {
        $result = $this->request($this->allocation($this->login()) + ['session_write_failure' => true]);
        $this->assertSame(503, $result['status'], json_encode($result['body']));
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
        $session = $this->login();
        $lookup = $this->request($session + ['path' => '/api/v1/erp/operations/lookup?operation=erp.payments.allocate']);
        $this->assertSame(200, $lookup['status'], json_encode($lookup['body']));
        $this->assertSame('completed', $lookup['body']['data']['state']);
        $this->assertSame('60.0000', $lookup['body']['data']['result']['obligation_open_amount']);
        $replay = $this->request($this->allocation($session));
        $this->assertSame(200, $replay['status']);
        $this->assertSame($lookup['body']['data']['result'], $replay['body']['data']);
        $this->assertDatabaseCount('erp_payment_allocations', 1);
    }
}
