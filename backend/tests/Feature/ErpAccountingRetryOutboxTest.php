<?php

namespace Tests\Feature;

use App\Erp\Accounting\ErpAccountingDispatchOutbox;
use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\Accounting\ErpAccountingMappingService;
use App\Erp\ErpCommercialCatalog;
use App\Jobs\DeliverErpAccountingDocument;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\Accounting\ErpAccountingDeliveryDispatch;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpOperationReceipt;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\Support\BuildsVerifiedErpFiscalEvidence;
use Tests\TestCase;

class ErpAccountingRetryOutboxTest extends TestCase
{
    use ApprovesErpAccountingMappings;
    use BuildsVerifiedErpFiscalEvidence;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('fiscal');
        Queue::fake();
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
            'erp.release_mode' => 'production',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
            'queue.default' => 'database',
        ]);
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
    }

    public function test_retry_requires_task_one_key_and_records_one_receipt_and_dispatch_generation(): void
    {
        $scenario = $this->scenario();
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);
        $headers = [...$scenario['headers'], 'X-CSRF-Token' => $this->csrf()];

        $this->withHeaders($scenario['headers'])
            ->getJson('/api/v1/erp/accounting-deliveries?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.can_retry', true);

        $this->withHeaders($headers)->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')->assertUnprocessable();

        $this->withHeaders([...$headers, 'Idempotency-Key' => 'accounting-retry-0001'])
            ->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.id', $delivery->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_retry', false);

        $this->assertDatabaseCount('erp_operation_receipts', 1);
        $receipt = ErpOperationReceipt::query()->sole();
        $this->assertSame('accounting-v1', $receipt->canonical_version);
        $this->assertLessThanOrEqual(16, strlen($receipt->canonical_version));
        $this->assertDatabaseHas('erp_accounting_delivery_dispatches', [
            'erp_accounting_delivery_id' => $delivery->id,
            'generation' => 1,
            'status' => 'leased',
            'dispatch_attempts' => 1,
        ]);

        $this->withHeaders([...$headers, 'Idempotency-Key' => 'accounting-retry-0001'])
            ->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.id', $delivery->id);

        $this->assertSame(1, ErpOperationReceipt::query()->count());
        $this->assertSame(1, ErpAccountingDeliveryDispatch::query()->count());
        Queue::assertPushed(DeliverErpAccountingDocument::class, 1);

        ErpAccountingDelivery::query()->whereKey($delivery->id)->update(['status' => 'failed']);
        $this->withHeaders($scenario['headers'])
            ->getJson('/api/v1/erp/accounting-deliveries?status=pending')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->withHeaders($scenario['headers'])
            ->getJson('/api/v1/erp/accounting-deliveries?status=failed')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'failed');
    }

    public function test_retry_receipt_is_bound_to_delivery_and_detail_projection_is_safe(): void
    {
        $scenario = $this->scenario();
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);
        $secondDocument = $this->authorizedFiscalDocument($scenario['company'], $scenario['establishment'], 9002);
        $secondDelivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($secondDocument);
        $headers = [...$scenario['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'accounting-retry-shared-key'];

        $this->withHeaders($headers)->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/erp/accounting-deliveries/'.$secondDelivery->id.'/retry')->assertStatus(409);

        $detail = $this->withHeaders($scenario['headers'])
            ->getJson('/api/v1/erp/accounting-deliveries/'.$delivery->id)
            ->assertOk()
            ->json('data');
        $this->assertSame($delivery->id, $detail['id']);
        $this->assertSame('55', $detail['erp_fiscal_document']['model']);
        $this->assertArrayNotHasKey('client', $detail);
        $this->assertArrayNotHasKey('client_name', $detail);
        $this->assertArrayNotHasKey('storage_path', $detail);
        $this->assertArrayNotHasKey('xml', $detail);
        $this->withHeaders($scenario['headers'])->getJson('/api/v1/erp/accounting-deliveries/999999')->assertNotFound();
        $this->withHeaders($scenario['headers'])->getJson('/api/v1/erp/accounting-deliveries?status=unknown')->assertUnprocessable();
    }

    public function test_old_serialized_delivery_job_keeps_its_original_delivery_id_and_default_dispatch_state(): void
    {
        $class = DeliverErpAccountingDocument::class;
        $serialized = 'O:'.strlen($class).':"'.$class.'":1:{s:10:"deliveryId";i:4321;}';
        $job = unserialize($serialized, ['allowed_classes' => [$class]]);

        $this->assertInstanceOf(DeliverErpAccountingDocument::class, $job);
        $this->assertSame(4321, $job->deliveryId);
        $this->assertNull($job->dispatchId);
        $this->assertNull($job->dispatchGeneration);
        $this->assertSame(1, $job->attemptSequence);
        $this->assertNull($job->dispatchLeaseToken);
    }

    public function test_sync_worker_keeps_new_outbox_dispatch_pending_for_the_scanner(): void
    {
        $scenario = $this->scenario();
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);
        $dispatch = ErpAccountingDeliveryDispatch::query()->create([
            'erp_accounting_delivery_id' => $delivery->id,
            'generation' => 1,
            'status' => 'pending',
            'attempt_sequence' => 1,
            'available_at' => now(),
        ]);
        config(['queue.default' => 'sync']);

        $this->assertFalse(app(ErpAccountingDispatchOutbox::class)->publish($dispatch->id));
        $dispatch->refresh();
        $this->assertSame('pending', $dispatch->status);
        $this->assertSame('accounting_worker_not_configured', $dispatch->last_error);
        $this->assertSame(1, $dispatch->dispatch_attempts);
        $this->withHeaders($scenario['headers'])
            ->getJson('/api/v1/erp/accounting-deliveries?status=pending')
            ->assertOk()
            ->assertJsonPath('data.0.can_retry', false);
    }

    public function test_committed_queue_push_failure_is_recovered_from_only_the_due_outbox_row(): void
    {
        $scenario = $this->scenario();
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);
        $headers = [...$scenario['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'accounting-retry-push-failure'];
        $queueFake = Queue::getFacadeRoot();
        Queue::shouldReceive('connection')->with('database')->once()->andThrow(new \RuntimeException('queue unavailable after commit'));

        $this->withHeaders($headers)
            ->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')
            ->assertOk()
            ->assertJsonPath('data.can_retry', false);

        $dispatch = ErpAccountingDeliveryDispatch::query()->where('erp_accounting_delivery_id', $delivery->id)->firstOrFail();
        $this->assertSame('pending', $dispatch->status);
        $this->assertSame('queue_publication_failed', $dispatch->last_error);
        $this->assertNull($dispatch->lease_token);
        $this->assertSame(1, $dispatch->dispatch_attempts);

        Queue::swap($queueFake);
        $dispatch->forceFill(['available_at' => now()])->save();
        $this->assertSame(1, app(ErpAccountingDispatchOutbox::class)->dispatchDue());
        $this->assertSame('leased', $dispatch->fresh()->status);
        Queue::assertPushed(DeliverErpAccountingDocument::class, 1);
        $this->assertSame(1, ErpAccountingDeliveryDispatch::query()->count());
    }

    public function test_receipt_lookup_returns_original_result_and_reauthorizes_current_actor(): void
    {
        $scenario = $this->scenario();
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);
        $headers = [...$scenario['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'accounting-retry-lookup-0001'];

        $this->withHeaders($headers)->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')->assertOk();
        $this->assertSame(1, ErpAccountingDeliveryDispatch::query()->count());
        $lookupHeaders = [...$scenario['headers'], 'Idempotency-Key' => 'accounting-retry-lookup-0001'];
        $this->withHeaders($lookupHeaders)
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.accounting-deliveries.retry')
            ->assertOk()
            ->assertJsonPath('data.state', 'completed')
            ->assertJsonPath('data.result.id', $delivery->id)
            ->assertJsonMissingPath('data.result.erp_fiscal_document.storage_path');
        $this->assertSame(1, ErpAccountingDeliveryDispatch::query()->count());

        ErpCompanyMembership::query()->where('erp_company_id', $scenario['company']->id)
            ->where('user_id', $scenario['user']->id)->update(['role' => 'viewer', 'action_permissions' => ['erp.accounting-deliveries.retry']]);
        $this->withHeaders($lookupHeaders)
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.accounting-deliveries.retry')
            ->assertForbidden();
    }

    public function test_receipt_lookup_rechecks_release_and_preview_allowlist(): void
    {
        $scenario = $this->scenario();
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);
        $key = 'accounting-retry-release-gate-0001';
        config(['erp.release_mode' => 'production', 'erp.release_enabled' => true]);
        $this->withHeaders([...$scenario['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $key])
            ->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')
            ->assertOk();
        $queueCount = count(Queue::pushed(DeliverErpAccountingDocument::class));

        config(['erp.release_mode' => 'disabled', 'erp.release_enabled' => false]);
        $lookupHeaders = [...$scenario['headers'], 'Idempotency-Key' => $key];
        $this->withHeaders($lookupHeaders)
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.accounting-deliveries.retry')
            ->assertForbidden()
            ->assertJsonPath('code', 'erp_release_disabled');

        config([
            'erp.release_mode' => 'preview',
            'erp.preview_allowlist' => [[
                'company_id' => $scenario['company']->id,
                'user_id' => $scenario['user']->id,
            ]],
        ]);
        $this->withHeaders($lookupHeaders)
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.accounting-deliveries.retry')
            ->assertOk()
            ->assertJsonPath('data.result.id', $delivery->id);

        config(['erp.preview_allowlist' => []]);
        $this->withHeaders($lookupHeaders)
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.accounting-deliveries.retry')
            ->assertForbidden()
            ->assertJsonPath('code', 'erp_release_disabled');
        $this->assertSame(1, ErpOperationReceipt::query()->count());
        $this->assertSame(1, ErpAccountingDeliveryDispatch::query()->count());
        $this->assertSame($queueCount, count(Queue::pushed(DeliverErpAccountingDocument::class)));
    }

    public function test_receipt_lookup_cannot_cross_actor_or_company_scope(): void
    {
        $scenario = $this->scenario();
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);
        $key = 'accounting-retry-cross-scope-0001';
        $headers = [...$scenario['headers'], 'X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $key];
        $this->withHeaders($headers)->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')->assertOk();

        $otherActor = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $scenario['company']->id,
            'user_id' => $otherActor->id,
            'role' => 'owner',
        ]);
        $this->login($otherActor);
        $lookup = ['X-Company-Id' => (string) $scenario['company']->id, 'Idempotency-Key' => $key];
        $this->withHeaders($lookup)
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.accounting-deliveries.retry')
            ->assertNotFound();

        $otherCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $otherCompany->id,
            'user_id' => $otherActor->id,
            'role' => 'owner',
        ]);
        ErpSubscription::factory()->create([
            'erp_company_id' => $otherCompany->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'active',
        ]);
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $otherCompany->id])
            ->assertOk()
            ->assertJsonPath('current_company.id', $otherCompany->id);
        $this->withHeaders(['X-Company-Id' => (string) $otherCompany->id, 'Idempotency-Key' => $key])
            ->getJson('/api/v1/erp/operations/lookup?operation=erp.accounting-deliveries.retry')
            ->assertNotFound();
        $this->assertSame(1, ErpAccountingDeliveryDispatch::query()->count());
    }

    public function test_outer_rollback_removes_retry_dispatch_and_recorded_mapping_cannot_retarget(): void
    {
        $scenario = $this->scenario();
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);

        try {
            DB::transaction(function () use ($delivery): void {
                app(ErpAccountingHandoffService::class)->retry($delivery);
                throw new \RuntimeException('rollback outbox intent');
            });
            $this->fail('Expected transaction rollback.');
        } catch (\RuntimeException $error) {
            $this->assertSame('rollback outbox intent', $error->getMessage());
        }
        $this->assertSame(0, ErpAccountingDeliveryDispatch::query()->where('erp_accounting_delivery_id', $delivery->id)->count());
        Queue::assertNotPushed(DeliverErpAccountingDocument::class);

        $original = ErpAccountingMapping::query()->where('delegation_id', $delivery->delegation_id)->firstOrFail();
        $newClient = resolve(CurrentTenant::class)->run($scenario['office']->id, fn (): Client => Client::factory()->company()->create([
            'account_id' => $scenario['office']->id,
            'tax_id' => '11222333000181',
        ]));
        $membership = AccountMembership::query()->where('account_id', $scenario['office']->id)->where('role', 'admin')->firstOrFail();
        app(ErpAccountingMappingService::class)->approve(new CurrentAccount($scenario['office'], $membership), $delivery->delegation_id, $scenario['establishment']->id, $newClient->id);

        $this->assertSame('blocked', app(ErpAccountingHandoffService::class)->deliver($delivery->fresh())->status);
        $this->assertSame($original->id, $delivery->fresh()->mapping_id);
        $this->assertSame($original->revision, $delivery->fresh()->mapping_revision);
    }

    public function test_placeholder_delivery_binds_only_once_to_the_first_active_mapping(): void
    {
        $scenario = $this->scenario(withMapping: false);
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($scenario['document']);
        $blocked = app(ErpAccountingHandoffService::class)->deliver($delivery);
        $this->assertSame('blocked', $blocked->status);
        $this->assertSame('client_mapping_missing', $blocked->reason_code);
        $this->assertNull($blocked->mapping_id);

        $firstMapping = app(ErpAccountingMappingService::class)->approve(
            $this->officeAdmin($scenario['office']), $scenario['delegation']->id, $scenario['establishment']->id, $scenario['client']->id,
        );
        $firstResult = app(ErpAccountingHandoffService::class)->retry($blocked);
        $this->assertSame($firstMapping->id, $firstResult->mapping_id);
        $this->assertSame($firstMapping->revision, $firstResult->mapping_revision);

        $replacementClient = resolve(CurrentTenant::class)->run($scenario['office']->id, fn (): Client => Client::factory()->company()->create([
            'account_id' => $scenario['office']->id,
            'tax_id' => '11222333000181',
        ]));
        app(ErpAccountingMappingService::class)->approve(
            $this->officeAdmin($scenario['office']), $scenario['delegation']->id, $scenario['establishment']->id, $replacementClient->id,
        );
        $revokedResult = app(ErpAccountingHandoffService::class)->deliver($firstResult->fresh());
        $this->assertSame('blocked', $revokedResult->status);
        $this->assertSame('mapping_revoked', $revokedResult->reason_code);
        $this->assertSame($firstMapping->id, $revokedResult->mapping_id);
    }

    /** @return array{user: User, company: ErpCompany, establishment: ErpEstablishment, document: ErpFiscalDocument, office: Account, delegation: ErpOfficeDelegation, client: Client, headers: array<string, string>} */
    private function scenario(bool $withMapping = true): array
    {
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $user = User::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $user->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $this->login($user);
        $office = Account::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $user->id,
        ]);
        $client = resolve(CurrentTenant::class)->run($office->id, fn (): Client => Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => '30288513000100',
        ]));
        if ($withMapping) {
            $this->approveAccountingMapping($delegation, $establishment, $client);
        } else {
            AccountMembership::factory()->create(['account_id' => $office->id, 'role' => 'admin']);
        }
        $document = $this->authorizedFiscalDocument($company, $establishment, 9001);

        return compact('user', 'company', 'establishment', 'document', 'office', 'delegation', 'client') + ['headers' => ['X-Company-Id' => (string) $company->id]];
    }

    private function officeAdmin(Account $office): CurrentAccount
    {
        $membership = AccountMembership::query()->where('account_id', $office->id)->where('role', 'admin')->firstOrFail();

        return new CurrentAccount($office, $membership);
    }

    private function csrf(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function login(User $user): void
    {
        $this->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
    }

    private function authorizedFiscalDocument(ErpCompany $company, ErpEstablishment $establishment, int $number): ErpFiscalDocument
    {
        $path = 'erp/fiscal/retry-'.$number.'.xml';
        Storage::disk('local')->put($path, '<nfeProc/>');

        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => $number,
            'access_key' => '352510302885130001005500100000'.str_pad((string) $number, 4, '0', STR_PAD_LEFT).'1234567890',
            'status' => 'authorized',
            'idempotency_key' => 'retry-doc-'.$number,
            'storage_path' => $path,
            'artifact_byte_length' => Storage::disk('local')->size($path),
            'artifact_sha256' => hash('sha256', Storage::disk('local')->get($path)),
            'authorized_at' => now(),
        ]);
        $this->attachVerifiedFiscalEvidence($document);

        return $document;
    }
}
