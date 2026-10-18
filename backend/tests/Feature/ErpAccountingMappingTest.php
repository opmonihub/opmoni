<?php

namespace Tests\Feature;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\ErpCompanyContext;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\TestCase;

class ErpAccountingMappingTest extends TestCase
{
    use ApprovesErpAccountingMappings;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('fiscal');
        config(['access.ingress_key' => 'test-key', 'erp.release_enabled' => true, 'erp.operating_writes_enabled' => true]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    public function test_matching_cnpj_cannot_publish_without_explicit_office_approval(): void
    {
        $s = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureDeliveryIntent($s['document']);
        $result = $handoff->deliver($intent);
        $this->assertSame('blocked', $result->status);
        $this->assertSame('client_mapping_missing', $result->reason_code);
        $this->assertSame(0, $result->attempts);
        $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_new_intent_records_exact_delegation_even_without_mapping(): void
    {
        $s = $this->scenario();
        $intent = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($s['document']);
        $this->assertSame($s['delegation']->id, $intent->delegation_id);
    }

    public function test_office_admin_explicitly_approves_and_repeated_approval_is_same_mapping(): void
    {
        $s = $this->scenario();
        $this->loginOffice($s);
        $response = $this->postJson('/api/v1/account/erp-accounting-mappings', $this->payload($s))->assertSuccessful();
        $id = $response->json('data.id');
        $this->assertNotNull($id);
        $this->postJson('/api/v1/account/erp-accounting-mappings', $this->payload($s))->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_foreign_client_delegation_and_establishment_are_rejected_without_disclosure(): void
    {
        $s = $this->scenario();
        $foreignAccount = Account::factory()->create();
        $foreignClient = resolve(CurrentTenant::class)->run($foreignAccount->id, fn () => Client::factory()->create(['account_id' => $foreignAccount->id]));
        $foreignDelegation = ErpOfficeDelegation::factory()->create(['account_id' => $foreignAccount->id]);
        $foreignEstablishment = ErpEstablishment::factory()->create();
        $this->loginOffice($s);
        foreach (['client_id' => $foreignClient->id, 'delegation_id' => $foreignDelegation->id, 'erp_establishment_id' => $foreignEstablishment->id] as $key => $value) {
            $this->postJson('/api/v1/account/erp-accounting-mappings', array_replace($this->payload($s), [$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertDatabaseCount('erp_accounting_mappings', 0);
    }

    public function test_operator_cannot_approve_read_client_details_or_revoke(): void
    {
        $s = $this->scenario();
        $mapping = $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $this->loginOffice($s, 'operador');
        $this->postJson('/api/v1/account/erp-accounting-mappings', $this->payload($s))->assertForbidden();
        $this->getJson('/api/v1/account/erp-accounting-mappings')->assertForbidden();
        $this->deleteJson('/api/v1/account/erp-accounting-mappings/'.$mapping->id)->assertForbidden();
        $this->assertSame('active', $mapping->fresh()->status);
    }

    public function test_selected_office_assertion_is_required_and_stale_context_is_conflict(): void
    {
        $s = $this->scenario();
        $this->loginOffice($s);
        $this->withHeader('X-Account-Id', '')->postJson('/api/v1/account/erp-accounting-mappings', $this->payload($s))->assertUnprocessable();
        $this->withHeader('X-Account-Id', (string) ($s['account']->id + 100))->postJson('/api/v1/account/erp-accounting-mappings', $this->payload($s))->assertConflict();
        $this->assertDatabaseCount('erp_accounting_mappings', 0);
    }

    public function test_replacement_preserves_approval_history_and_blocks_old_bound_intent(): void
    {
        $s = $this->scenario();
        $this->loginOffice($s);
        $first = $this->postJson('/api/v1/account/erp-accounting-mappings', $this->payload($s))->assertCreated()->json('data');
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureDeliveryIntent($s['document']);
        $other = resolve(CurrentTenant::class)->run($s['account']->id, fn () => Client::factory()->create(['account_id' => $s['account']->id]));
        $second = $this->postJson('/api/v1/account/erp-accounting-mappings', array_replace($this->payload($s), ['client_id' => $other->id]))->assertCreated()->json('data');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, $second['revision']);
        $this->assertDatabaseHas('erp_accounting_mappings', ['id' => $first['id'], 'status' => 'revoked', 'client_id' => $s['client']->id]);
        $this->assertSame('mapping_revoked', $handoff->retry($intent)->reason_code);
        $this->assertSame(0, $intent->fresh()->attempts);
    }

    public function test_mapping_for_other_establishment_does_not_authorize_publication(): void
    {
        $s = $this->scenario();
        $other = ErpEstablishment::factory()->create(['erp_company_id' => $s['company']->id]);
        $this->approveAccountingMapping($s['delegation'], $other, $s['client']);
        $handoff = app(ErpAccountingHandoffService::class);
        $this->assertSame('client_mapping_missing', $handoff->deliver($handoff->ensureDeliveryIntent($s['document']))->reason_code);
    }

    public function test_missing_mapping_recovers_only_original_grant_and_delivers_once(): void
    {
        $s = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->deliver($handoff->ensureDeliveryIntent($s['document']));
        $mapping = $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $pending = $handoff->retry($intent);
        $this->assertSame('pending', $pending->status);
        $this->assertSame('accounting_worker_not_configured', $pending->reason_code);
        $this->assertSame($mapping->id, $pending->mapping_id);
        $this->assertSame(0, $pending->attempts);
        $result = $handoff->deliver($pending);
        $this->assertSame('delivered', $result->status);
        $snapshot = $result->getAttributes();
        $this->assertSame($snapshot, $handoff->deliver($result)->getAttributes());
        $this->assertSame(1, $result->attempts);
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_revoked_original_grant_cannot_adopt_regrant_to_same_account(): void
    {
        $s = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->deliver($handoff->ensureDeliveryIntent($s['document']));
        $s['delegation']->update(['status' => 'revoked']);
        $newGrant = ErpOfficeDelegation::factory()->create(['erp_company_id' => $s['company']->id, 'account_id' => $s['account']->id]);
        $this->approveAccountingMapping($newGrant, $s['establishment'], $s['client']);
        $blocked = $handoff->retry($intent);
        $this->assertSame('blocked', $blocked->status);
        $this->assertSame('delegation_revoked', $blocked->reason_code);
        $this->assertSame($s['delegation']->id, $blocked->delegation_id);
        $this->assertNull($blocked->mapping_id);
        $this->assertSame(0, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_legacy_unbound_intent_never_adopts_current_grant(): void
    {
        $s = $this->scenario();
        $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureDeliveryIntent($s['document']);
        $intent->update(['delegation_id' => null, 'mapping_id' => null, 'mapping_revision' => null]);
        $this->assertSame('intent_delegation_unverified', $handoff->retry($intent)->reason_code);
        $this->assertNull($intent->fresh()->delegation_id);
    }

    public function test_revocation_preserves_delivered_history_and_blocks_pending_publication(): void
    {
        $s = $this->scenario();
        $mapping = $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $handoff = app(ErpAccountingHandoffService::class);
        $delivered = $handoff->deliver($handoff->ensureDeliveryIntent($s['document']));
        $snapshot = $delivered->getAttributes();
        $this->loginOffice($s);
        $this->deleteJson('/api/v1/account/erp-accounting-mappings/'.$mapping->id)->assertOk()->assertJsonPath('data.status', 'revoked');
        $this->assertSame($snapshot, $handoff->retry($delivered)->getAttributes());
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_inactive_client_blocks_without_attempt_and_context_is_restored(): void
    {
        $s = $this->scenario();
        $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $s['client']->update(['status' => 'inactive']);
        resolve(CurrentTenant::class)->accountId = 123456;
        resolve(ErpCompanyContext::class)->companyId = 654321;
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureDeliveryIntent($s['document']);
        $this->assertSame('client_inactive', $handoff->deliver($intent)->reason_code);
        $this->assertSame(0, $intent->fresh()->attempts);
        $this->assertSame(123456, resolve(CurrentTenant::class)->accountId);
        $this->assertSame(654321, resolve(ErpCompanyContext::class)->companyId);
        $s['client']->update(['status' => 'active']);
        $this->assertSame('blocked', $handoff->retry($intent)->status);
    }

    public function test_missing_source_readiness_does_not_recover_merely_because_mapping_exists(): void
    {
        $s = $this->scenario();
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->deliver($handoff->ensureDeliveryIntent($s['document']));
        $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $s['document']->update(['status' => 'queued']);
        $result = $handoff->retry($intent);
        $this->assertSame('blocked', $result->status);
        $this->assertSame('fiscal_document_not_authorized', $result->reason_code);
        $this->assertSame(0, $result->attempts);
    }

    public function test_foreign_request_account_guard_is_not_swallowed_as_storage_failure(): void
    {
        $s = $this->scenario();
        $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $other = Account::factory()->create();
        $member = AccountMembership::factory()->create(['account_id' => $other->id]);
        request()->attributes->set(CurrentAccount::class, new CurrentAccount($other, $member));
        $handoff = app(ErpAccountingHandoffService::class);
        $intent = $handoff->ensureDeliveryIntent($s['document']);
        try {
            $handoff->deliver($intent);
            $this->fail('Foreign request office guard must propagate.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        } finally {
            request()->attributes->remove(CurrentAccount::class);
        }
        $this->assertSame(0, $intent->fresh()->attempts);
        $this->assertSame('pending', $intent->fresh()->status);
    }

    public function test_owner_has_opaque_status_but_cannot_approve_client_and_new_intent_requires_approval(): void
    {
        $s = $this->scenario();
        $this->loginOwner($s);
        $this->postJson('/api/v1/account/erp-accounting-mappings', $this->payload($s))->assertForbidden();
        $this->postJson('/api/v1/erp/accounting-deliveries', ['erp_fiscal_document_id' => $s['document']->id])->assertUnprocessable();
        $mapping = $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $view = $this->getJson('/api/v1/erp/accounting-mappings')->assertOk()->json('data');
        $this->assertSame($mapping->id, $view['mappings'][0]['id']);
        $this->assertArrayNotHasKey('client_id', $view['mappings'][0]);
        $this->assertArrayNotHasKey('approved_by_user_id', $view['mappings'][0]);
        $created = $this->postJson('/api/v1/erp/accounting-deliveries', ['erp_fiscal_document_id' => $s['document']->id])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->postJson('/api/v1/erp/accounting-deliveries', ['erp_fiscal_document_id' => $s['document']->id])->assertOk()->assertJsonPath('data.id', $created['id']);
    }

    public function test_explicit_owner_regrant_creates_distinct_intent_and_converges_without_rewriting_history(): void
    {
        $s = $this->scenario();
        $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $handoff = app(ErpAccountingHandoffService::class);
        $delivered = $handoff->deliver($handoff->ensureDeliveryIntent($s['document']));
        $history = $delivered->getAttributes();
        $s['delegation']->update(['status' => 'revoked']);
        $new = ErpOfficeDelegation::factory()->create(['erp_company_id' => $s['company']->id, 'account_id' => $s['account']->id]);
        $this->approveAccountingMapping($new, $s['establishment'], $s['client']);
        $owner = $this->loginOwner($s);
        $id = $this->postJson('/api/v1/erp/accounting-deliveries', ['erp_fiscal_document_id' => $s['document']->id])->assertCreated()->assertJsonPath('data.delegation_id', $new->id)->json('data.id');
        $this->assertNotSame($delivered->id, $id);
        $next = ErpAccountingDelivery::query()->findOrFail($id);
        $this->assertSame($owner->id, $next->requested_by_user_id);
        $this->assertSame($delivered->fiscal_document_id, $handoff->deliver($next)->fiscal_document_id);
        $this->assertSame($history, $delivered->fresh()->getAttributes());
        $this->assertSame(1, FiscalDocument::withoutGlobalScope('account')->count());
    }

    public function test_platform_role_without_office_membership_cannot_approve(): void
    {
        $s = $this->scenario();
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->loginUser($user);
        $this->withHeader('X-Account-Id', (string) $s['account']->id);
        $this->postJson('/api/v1/account/erp-accounting-mappings', $this->payload($s))->assertForbidden();
        $this->assertDatabaseCount('erp_accounting_mappings', 0);
    }

    public function test_foreign_mapping_resource_is_not_found(): void
    {
        $s = $this->scenario();
        $mapping = $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $other = Account::factory()->create();
        $this->loginOffice(['account' => $other]);
        $this->deleteJson('/api/v1/account/erp-accounting-mappings/'.$mapping->id)->assertNotFound();
        $this->getJson('/api/v1/account/erp-accounting-mappings?company_id='.$s['company']->id)->assertOk()->assertExactJson(['data' => []]);
    }

    private function loginOwner(array $s): User
    {
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $s['company']->id, 'user_id' => $user->id, 'role' => 'owner']);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create(['erp_company_id' => $s['company']->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $this->loginUser($user);
        $this->withHeader('X-Company-Id', (string) $s['company']->id);

        return $user;
    }

    private function loginUser(User $user): void
    {
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
    }

    public function test_explicit_owner_can_create_new_intent_for_replaced_mapping_under_same_delegation(): void
    {
        $s = $this->scenario();
        $first = $this->approveAccountingMapping($s['delegation'], $s['establishment'], $s['client']);
        $handoff = app(ErpAccountingHandoffService::class);
        $old = $handoff->ensureDeliveryIntent($s['document']);
        $nextClient = resolve(CurrentTenant::class)->run($s['account']->id, fn () => Client::factory()->create(['account_id' => $s['account']->id]));
        $next = $this->approveAccountingMapping($s['delegation'], $s['establishment'], $nextClient);
        $this->assertSame('mapping_revoked', $handoff->retry($old)->reason_code);
        $snapshot = $old->fresh()->getAttributes();
        $this->loginOwner($s);
        $id = $this->postJson('/api/v1/erp/accounting-deliveries', ['erp_fiscal_document_id' => $s['document']->id])->assertCreated()->assertJsonPath('data.mapping_id', $next->id)->json('data.id');
        $this->assertNotSame($old->id, $id);
        $new = ErpAccountingDelivery::findOrFail($id);
        $this->assertSame($nextClient->id, $handoff->deliver($new)->client_id);
        $this->assertSame($snapshot, $old->fresh()->getAttributes());
        $this->assertSame($first->id, $old->fresh()->mapping_id);
        $this->postJson('/api/v1/erp/accounting-deliveries', ['erp_fiscal_document_id' => $s['document']->id])->assertOk()->assertJsonPath('data.id', $id);
        $migration = require database_path('migrations/2026_10_18_003343_create_erp_accounting_mappings_table.php');
        try {
            $migration->down();
            $this->fail('Narrowing distinct approval histories must be refused.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot narrow', $error->getMessage());
        }
        $this->assertDatabaseCount('erp_accounting_deliveries', 2);
        $this->assertDatabaseCount('erp_accounting_mappings', 2);
    }

    private function payload(array $s): array
    {
        return ['delegation_id' => $s['delegation']->id, 'erp_establishment_id' => $s['establishment']->id, 'client_id' => $s['client']->id];
    }

    private function loginOffice(array $s, string $role = 'admin'): User
    {
        $user = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $s['account']->id, 'user_id' => $user->id, 'role' => $role]);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->withHeader('X-Account-Id', (string) $s['account']->id);

        return $user;
    }

    private function scenario(): array
    {
        $account = Account::factory()->create();
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $client = resolve(CurrentTenant::class)->run($account->id, fn () => Client::factory()->company()->create(['account_id' => $account->id, 'tax_id' => $company->cnpj]));
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $account->id]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        Storage::disk('local')->put('erp/fiscal/mapping.xml', '<nfeProc/>');
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id, 'erp_establishment_id' => $establishment->id,
            'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => 9001,
            'access_key' => '35251030288513000100550010000090011234567890', 'status' => 'authorized',
            'idempotency_key' => fake()->uuid(), 'storage_path' => 'erp/fiscal/mapping.xml',
            'artifact_byte_length' => 10, 'artifact_sha256' => hash('sha256', '<nfeProc/>'), 'authorized_at' => now(),
        ]);

        return compact('account', 'company', 'client', 'delegation', 'establishment', 'document');
    }
}
