<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Reports\ErpReportExport;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpEstablishmentScopeConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task22-20261018', getenv('ERP_ESTABLISHMENT_SCOPE_ISOLATED'));
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertSame('14', config('database.redis.cache.database'));
        $this->assertSame('15', config('database.redis.sessions.database'));
        $this->assertStringStartsWith('18.', DB::selectOne('show server_version')->server_version);
        $this->assertStringStartsWith('8.', Redis::connection('cache')->info('server')['redis_version']);
        Artisan::call('migrate:fresh', ['--force' => true]);
        Redis::connection('cache')->flushdb();
        Redis::connection('sessions')->flushdb();
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpEstablishmentScopeConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @return array<string, mixed> */
    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);

        return $result;
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function request(array $input): array
    {
        return $this->response($this->worker($input));
    }

    /** @return array{cookies: array<string, string>, headers: array<string, string>} */
    private function login(User $user, int $companyId): array
    {
        $csrf = $this->request(['path' => '/api/v1/csrf']);
        $login = $this->request([
            'path' => '/api/v1/login',
            'method' => 'POST',
            'cookies' => $csrf['cookies'],
            'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']],
            'body' => ['email' => $user->email, 'password' => 'password'],
        ]);
        $this->assertSame(200, $login['status'], json_encode($login['body']));
        $nextCsrf = $this->request(['path' => '/api/v1/csrf', 'cookies' => $login['cookies']]);

        return [
            'cookies' => $nextCsrf['cookies'] ?: $login['cookies'],
            'headers' => ['X-CSRF-Token' => $nextCsrf['body']['csrf_token'], 'X-Company-Id' => (string) $companyId],
        ];
    }

    /** @return array{owner: User, member: User, company: ErpCompany, location: ErpEstablishment, membership: ErpCompanyMembership} */
    private function scenario(array $scopeIds = []): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $membership = ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $member->id,
            'role' => 'manager',
            'access_all_establishments' => false,
        ]);
        $location = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $membership->establishments()->attach($scopeIds === [] ? $location->id : $scopeIds, ['erp_company_id' => $company->id]);
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
        ]);

        return compact('owner', 'member', 'company', 'location', 'membership');
    }

    /** @param array<string, mixed> $session @param array<string, mixed> $request */
    private function mutation(array $session, string $path, array $request = [], string $method = 'POST'): array
    {
        return [...$request,
            'cookies' => $session['cookies'],
            'headers' => array_merge($session['headers'], $request['headers'] ?? []),
            'method' => $method,
            'path' => $path,
        ];
    }

    private function waitForCompanyLock(string $label, string $ready): void
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$label])->count;
            if (file_exists($ready) && (int) $waiting === 1) {
                fwrite(STDERR, "Task22 barrier confirmed: {$label}, PID ".file_get_contents($ready).", waiting on Company row lock\n");

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent HTTP worker did not reach the observed Company lock wait: '.$label);
    }

    public function test_source_write_waiting_on_company_fence_is_denied_after_owner_narrows_retained_member_scope(): void
    {
        ['owner' => $owner, 'member' => $member, 'company' => $company, 'location' => $location, 'membership' => $membership] = $this->scenario();
        $ownerSession = $this->login($owner, $company->id);
        $memberSession = $this->login($member, $company->id);
        $baseline = $this->request($this->mutation($memberSession, '/api/v1/erp/quotes', [
            'headers' => ['Idempotency-Key' => 'scope-race-before-revoke'],
            'body' => ['erp_establishment_id' => $location->id, 'quote_no' => 'scope-race-before-revoke', 'lines' => [['description' => 'Synthetic scope quote', 'quantity' => '1', 'unit_price' => '5.00']]],
        ]));
        $this->assertSame(201, $baseline['status'], json_encode($baseline['body']));

        $narrowReady = tempnam(sys_get_temp_dir(), 'task22-narrow-');
        $writeReady = tempnam(sys_get_temp_dir(), 'task22-source-');
        @unlink($narrowReady);
        @unlink($writeReady);
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $company->id)->lockForUpdate()->first();
        try {
            $narrow = $this->worker($this->mutation($ownerSession, '/api/v1/erp/members/'.$membership->id, [
                'body' => ['establishment_scope' => 'selected', 'establishment_ids' => []],
            ], 'PATCH') + ['label' => 'task22-owner-narrow', 'ready' => $narrowReady]);
            $this->waitForCompanyLock('task22-owner-narrow', $narrowReady);
            $write = $this->worker($this->mutation($memberSession, '/api/v1/erp/quotes', [
                'headers' => ['Idempotency-Key' => 'scope-race-after-narrow'],
                'body' => ['erp_establishment_id' => $location->id, 'quote_no' => 'scope-race-after-narrow', 'lines' => [['description' => 'Synthetic denied quote', 'quantity' => '1', 'unit_price' => '7.00']]],
            ]) + ['label' => 'task22-source-write', 'ready' => $writeReady]);
            $this->waitForCompanyLock('task22-source-write', $writeReady);

            DB::commit();

            $narrowed = $this->response($narrow);
            $denied = $this->response($write);
            $this->assertSame(200, $narrowed['status'], json_encode($narrowed['body']));
            $this->assertSame(422, $denied['status'], json_encode($denied['body']));
            $this->assertNotSame($narrowed['pid'], $denied['pid']);
            $this->assertDatabaseCount('erp_quotes', 1);
            $this->assertDatabaseMissing('erp_quotes', ['quote_no' => 'scope-race-after-narrow']);
            $this->assertDatabaseCount('erp_operation_receipts', 1);
            $this->assertDatabaseMissing('erp_operation_receipts', ['key_digest' => hash('sha256', 'scope-race-after-narrow')]);
            $this->assertDatabaseHas('erp_company_memberships', ['id' => $membership->id, 'access_all_establishments' => false]);
            $this->assertDatabaseCount('erp_company_membership_establishments', 0);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            @unlink($narrowReady);
            @unlink($writeReady);
        }
    }

    public function test_scope_edit_validation_and_session_loss_after_pivot_write_roll_back_on_postgres(): void
    {
        ['owner' => $owner, 'member' => $member, 'company' => $company, 'location' => $first, 'membership' => $membership] = $this->scenario();
        $second = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $ownerSession = $this->login($owner, $company->id);

        foreach ([['scope-pivot-validation', 422], ['scope-pivot-lease-loss', 503]] as [$fault, $expectedStatus]) {
            $result = $this->request($this->mutation($ownerSession, '/api/v1/erp/members/'.$membership->id, [
                'body' => ['establishment_scope' => 'selected', 'establishment_ids' => [$second->id]],
                'fault' => $fault,
            ], 'PATCH'));
            $this->assertSame($expectedStatus, $result['status'], json_encode($result['body']));
            $this->assertTrue($result['injected_failure']);
            $this->assertFalse($membership->fresh()->access_all_establishments);
            $this->assertSame([$first->id], $membership->fresh()->establishments()->pluck('erp_establishments.id')->map(fn ($id): int => (int) $id)->all());
        }
    }

    public function test_pg_http_receipt_and_export_authority_are_rechecked_after_selected_scope_narrows(): void
    {
        ['owner' => $owner, 'member' => $member, 'company' => $company, 'location' => $location, 'membership' => $membership] = $this->scenario();
        $ownerSession = $this->login($owner, $company->id);
        $memberSession = $this->login($member, $company->id);
        $payment = ErpPayment::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $location->id,
            'amount' => '10.0000',
            'currency' => 'BRL',
            'method' => 'cash',
            'direction' => 'in',
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        $obligation = ErpObligation::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $location->id,
            'direction' => 'receivable',
            'category' => 'manual',
            'amount' => '10.0000',
            'open_amount' => '10.0000',
            'currency' => 'BRL',
            'status' => 'open',
            'created_at' => now(),
        ]);
        $operationKey = 'task22-scope-receipt-narrow';
        $allocation = $this->request($this->mutation($memberSession, '/api/v1/erp/payments/'.$payment->id.'/allocate', [
            'headers' => ['Idempotency-Key' => $operationKey],
            'body' => ['erp_obligation_id' => $obligation->id, 'amount' => '10.00'],
        ]));
        $this->assertSame(200, $allocation['status'], json_encode($allocation['body']));
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);

        $storageRoot = sys_get_temp_dir().'/opmoni-task22-export-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($storageRoot);
        try {
            $exportResult = $this->request($this->mutation($memberSession, '/api/v1/erp/reports/overview/exports', [
                'storage_root' => $storageRoot,
                'body' => [],
            ]));
            $this->assertSame(201, $exportResult['status'], json_encode($exportResult['body']));
            $exportId = $exportResult['body']['data']['id'];
            $export = ErpReportExport::query()->findOrFail($exportId);
            $this->assertSame([$location->id], $export->authorized_establishment_ids);
            $artifactPath = $storageRoot.'/'.$export->storage_path;
            $this->assertFileExists($artifactPath);

            $narrow = $this->request($this->mutation($ownerSession, '/api/v1/erp/members/'.$membership->id, [
                'body' => ['establishment_scope' => 'selected', 'establishment_ids' => []],
            ], 'PATCH'));
            $this->assertSame(200, $narrow['status'], json_encode($narrow['body']));

            $lookup = $this->request($this->mutation($memberSession, '/api/v1/erp/operations/lookup?operation=erp.payments.allocate', [
                'headers' => ['Idempotency-Key' => $operationKey],
            ], 'GET'));
            $this->assertSame(404, $lookup['status'], json_encode($lookup['body']));
            $replay = $this->request($this->mutation($memberSession, '/api/v1/erp/payments/'.$payment->id.'/allocate', [
                'headers' => ['Idempotency-Key' => $operationKey],
                'body' => ['erp_obligation_id' => $obligation->id, 'amount' => '10.00'],
            ]));
            $this->assertSame(404, $replay['status'], json_encode($replay['body']));
            $exportRead = $this->request($this->mutation($memberSession, '/api/v1/erp/reports/exports/'.$exportId, [
                'storage_root' => $storageRoot,
            ], 'GET'));
            $this->assertSame(409, $exportRead['status'], json_encode($exportRead['body']));
            $this->assertSame('export_requires_regeneration', $exportRead['body']['code']);
            $this->assertArrayNotHasKey('report', $exportRead['body']['data'] ?? []);
            $this->assertFileExists($artifactPath);
            $this->assertDatabaseCount('erp_payment_allocations', 1);
            $this->assertDatabaseCount('erp_operation_receipts', 1);
            $this->assertSame('0.0000', $obligation->fresh()->open_amount);
        } finally {
            File::deleteDirectory($storageRoot);
        }
    }

    public function test_postgres_migration_rollback_guard_preserves_selected_scope_pivots_and_export_metadata(): void
    {
        ['member' => $member, 'company' => $company, 'location' => $location, 'membership' => $membership] = $this->scenario();
        $export = ErpReportExport::query()->create([
            'erp_company_id' => $company->id,
            'user_id' => $member->id,
            'report_type' => 'overview',
            'filters' => [],
            'status' => 'completed',
            'storage_path' => 'erp/report-exports/scoped.json',
            'authorized_establishment_scope' => 'selected',
            'authorized_establishment_ids' => [$location->id],
            'authorized_companywide_finance' => false,
        ]);
        $migration = require database_path('migrations/2026_10_18_152621_add_typed_establishment_scope_to_erp_memberships_and_exports.php');

        try {
            $migration->down();
            $this->fail('PostgreSQL migration rollback must refuse selected authority.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('selected membership scopes', $error->getMessage());
        }

        $this->assertTrue(Schema::hasTable('erp_company_membership_establishments'));
        $this->assertTrue(Schema::hasColumn('erp_company_memberships', 'access_all_establishments'));
        $this->assertTrue(Schema::hasColumn('erp_report_exports', 'authorized_companywide_finance'));
        $this->assertFalse($membership->fresh()->access_all_establishments);
        $this->assertSame([$location->id], $membership->fresh()->establishments()->pluck('erp_establishments.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([$location->id], $export->fresh()->authorized_establishment_ids);
        $this->assertFalse($export->fresh()->authorized_companywide_finance);
    }
}
