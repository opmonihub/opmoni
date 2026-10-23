<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpDelegatedAccessConcurrencyTest extends TestCase
{
    private User $actor;

    private Account $office;

    private AccountMembership $membership;

    private ErpCompany $company;

    private ErpOfficeDelegation $delegation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task21-20261018', getenv('DELEGATED_ACCESS_ISOLATED'));
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertStringStartsWith('18.', DB::selectOne('show server_version')->server_version);
        $this->assertStringStartsWith('8.', Redis::connection('cache')->info('server')['redis_version']);
        Artisan::call('migrate:fresh', ['--force' => true]);
        Redis::connection('cache')->flushdb();
        Redis::connection('sessions')->flushdb();
        $this->actor = User::factory()->create();
        $this->office = Account::factory()->create();
        $this->membership = AccountMembership::factory()->create(['user_id' => $this->actor->id, 'account_id' => $this->office->id, 'role' => 'admin']);
        $this->company = ErpCompany::factory()->create();
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $this->delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $this->company->id, 'account_id' => $this->office->id, 'permission_allowlist' => ['erp.read', 'erp.brands.store']]);
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpDelegatedAccessConcurrencyWorker.php')], base_path());
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
        $cookies = $login['cookies'];
        $csrf = $this->request(['path' => '/api/v1/csrf', 'cookies' => $cookies]);
        $selection = $this->request(['path' => '/api/v1/erp/company/switch', 'method' => 'POST', 'cookies' => $cookies, 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']], 'body' => ['company_id' => $this->company->id]]);
        $this->assertSame(200, $selection['status'], json_encode($selection['body']));
        $cookies = $selection['cookies'] ?: $cookies;
        $csrf = $this->request(['path' => '/api/v1/csrf', 'cookies' => $cookies]);

        return ['cookies' => $csrf['cookies'] ?: $cookies, 'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token'], 'X-Company-Id' => (string) $this->company->id]];
    }

    private function brand(array $session): array
    {
        return $session + ['path' => '/api/v1/erp/brands', 'method' => 'POST', 'body' => ['name' => 'Delegated effect']];
    }

    #[DataProvider('officeChanges')]
    public function test_held_office_fence_fails_fast_then_current_authority_denies(string $change): void
    {
        $session = $this->login();
        DB::beginTransaction();
        Account::query()->whereKey($this->office->id)->lockForUpdate()->firstOrFail();
        try {
            if ($change === 'delete') {
                $this->membership->delete();
            } elseif ($change === 'suspend') {
                $this->office->update(['status' => 'suspended']);
            } else {
                $this->membership->update(['role' => 'user']);
            }
            $busy = $this->request($this->brand($session));
            $this->assertSame(503, $busy['status'], json_encode($busy['body']));
            $this->assertSame('company_access_busy', $busy['body']['code']);
            $this->assertSame('1', $busy['retry_after']);
            $this->assertStringContainsString('FOR UPDATE NOWAIT', $busy['fence_query']);
            $this->assertSame($this->office->id, $busy['fence_account_id']);
            $this->assertSame(1, $busy['rollback_probe']);
            $this->assertLessThan(5, $busy['seconds']);
            $this->assertNull($busy['company_context']);
            $this->assertNull($busy['tenant_context']);
            $this->assertDatabaseCount('erp_brands', 0);
            $this->assertDatabaseCount('erp_operation_receipts', 0);
            $this->assertDatabaseCount('erp_accounting_deliveries', 0);
            $this->assertDatabaseCount('jobs', 0);
            DB::commit();
            $this->assertSame(403, $this->request($this->brand($session))['status']);
            $this->assertDatabaseCount('erp_brands', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public static function officeChanges(): array
    {
        return [['demote'], ['delete'], ['suspend']];
    }

    public function test_direct_membership_does_not_acquire_the_delegated_office_fence(): void
    {
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->actor->id, 'role' => 'manager']);
        $session = $this->login();
        DB::beginTransaction();
        Account::query()->whereKey($this->office->id)->lockForUpdate()->firstOrFail();
        try {
            $response = $this->request($this->brand($session));
            $this->assertSame(201, $response['status'], json_encode($response['body']));
            $this->assertNull($response['fence_query']);
            $this->assertDatabaseCount('erp_brands', 1);
        } finally {
            DB::rollBack();
        }
    }

    public function test_company_revocation_commits_before_waiting_request_rechecks_authority(): void
    {
        $session = $this->login();
        DB::beginTransaction();
        ErpCompany::query()->whereKey($this->company->id)->lockForUpdate()->firstOrFail();
        try {
            $this->delegation->update(['status' => 'revoked']);
            $request = $this->worker($this->brand($session) + ['label' => 'delegated-revocation-waiter']);
            $deadline = microtime(true) + 5;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = 'delegated-revocation-waiter' and wait_event_type = 'Lock'")->count;
                if ((int) $waiting === 1) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(1, (int) $waiting, 'Request must cross the held Company fence.');
            DB::commit();
            $this->assertSame(403, $this->response($request)['status']);
            $this->assertDatabaseCount('erp_brands', 0);
            $this->assertDatabaseCount('erp_operation_receipts', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    private function waitForFile(string $file): void
    {
        $deadline = microtime(true) + 8;
        while (! file_exists($file)) {
            if (microtime(true) > $deadline) {
                $this->fail('Delegated HTTP barrier missing.');
            }
            usleep(10000);
        }
    }

    public function test_office_change_waits_until_authorized_effect_commits(): void
    {
        $session = $this->login();
        $ready = tempnam(sys_get_temp_dir(), 'delegated-ready-');
        $release = tempnam(sys_get_temp_dir(), 'delegated-release-');
        $editorReady = tempnam(sys_get_temp_dir(), 'delegated-editor-');
        unlink($ready);
        unlink($release);
        unlink($editorReady);
        try {
            $operation = $this->worker($this->brand($session) + ['before_effect' => $ready, 'release' => $release]);
            $this->waitForFile($ready);
            $editor = $this->worker(['mode' => 'demote', 'label' => 'delegated-office-editor', 'ready' => $editorReady, 'account_id' => $this->office->id, 'membership_id' => $this->membership->id]);
            $this->waitForFile($editorReady);
            $deadline = microtime(true) + 5;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = 'delegated-office-editor' and wait_event_type = 'Lock'")->count;
                if ((int) $waiting === 1) {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertSame(1, (int) $waiting, 'Office editor must wait on the operation Account fence.');
            file_put_contents($release, 'go');
            $effect = $this->response($operation);
            $changed = $this->response($editor);
            $this->assertNotSame($effect['pid'], $changed['pid']);
            $this->assertSame(201, $effect['status'], json_encode($effect['body']));
            $this->assertSame('user', $this->membership->fresh()->role);
            $this->assertDatabaseCount('erp_brands', 1);
            $this->assertSame(403, $this->request($this->brand($session))['status']);
        } finally {
            file_put_contents($release, 'go');
            @unlink($ready);
            @unlink($release);
            @unlink($editorReady);
        }
    }

    public function test_revocation_and_lost_session_leave_no_effect_or_receipt(): void
    {
        $session = $this->login();
        $lost = $this->request($this->brand($session) + ['lose_lease' => true]);
        $this->assertSame(503, $lost['status'], json_encode($lost['body']));
        $this->assertDatabaseCount('erp_brands', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
        $this->assertNull($lost['company_context']);
        $this->assertNull($lost['tenant_context']);
        $this->delegation->update(['status' => 'revoked']);
        $this->assertSame(403, $this->request($this->brand($session))['status']);
        $this->assertDatabaseCount('erp_brands', 0);
    }
}
