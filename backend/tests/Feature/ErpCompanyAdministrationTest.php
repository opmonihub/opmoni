<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ErpCompanyAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private ErpCompany $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        $this->owner = User::factory()->create();
        $this->company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $this->owner->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'X-Company-Id' => (string) $this->company->id]);
    }

    private function memberPayload(User $user): array
    {
        return ['user_id' => $user->id, 'role' => 'operator', 'action_permissions' => [], 'establishment_scope' => 'all'];
    }

    public function test_owner_can_lookup_associate_edit_and_detach_existing_identity_without_changing_it(): void
    {
        $user = User::factory()->create();
        $officeMembership = AccountMembership::factory()->create(['user_id' => $user->id, 'role' => 'admin']);
        $history = ErpPayment::query()->create(['erp_company_id' => $this->company->id, 'amount' => '10', 'currency' => 'BRL', 'method' => 'cash', 'status' => 'posted', 'posted_by_user_id' => $user->id]);
        $before = $user->fresh()->getRawOriginal();
        $lookup = $this->postJson('/api/v1/erp/members/identity-lookup', ['email' => strtoupper($user->email)])->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertSame(['id', 'name', 'email'], array_keys($lookup->json('data')));
        $this->assertStringContainsString('no-store', $lookup->headers->get('Cache-Control'));
        $this->assertDatabaseCount('erp_company_memberships', 1);
        $response = $this->postJson('/api/v1/erp/members', $this->memberPayload($user))->assertCreated()->assertJsonPath('data.effective_permissions', ['erp.read'])->assertJsonPath('data.establishment_scope', 'all')->assertJsonPath('data.establishment_ids', []);
        $id = $response->json('data.id');
        $this->getJson('/api/v1/erp/members?search='.urlencode($user->email))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.user.email', $user->email);
        $this->getJson('/api/v1/erp/members/'.$id)->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->postJson('/api/v1/erp/members', $this->memberPayload($user))->assertConflict();
        $this->patchJson('/api/v1/erp/members/'.$id, ['role' => 'manager', 'action_permissions' => []])->assertOk()->assertJsonPath('data.effective_permissions', ['*']);
        $this->patchJson('/api/v1/erp/members/'.$id, ['action_permissions' => ['erp.brands.store']])->assertOk()->assertJsonPath('data.effective_permissions', ['erp.brands.store']);
        $this->deleteJson('/api/v1/erp/members/'.$id)->assertNoContent();
        $this->assertTrue($before === $user->fresh()->getRawOriginal(), 'The global identity must remain byte-for-byte unchanged.');
        $this->assertSame('admin', $officeMembership->fresh()->role);
        $this->assertSame($user->id, $history->fresh()->posted_by_user_id);
        $this->assertDatabaseCount('erp_company_memberships', 1);
    }

    public function test_owner_and_association_identity_are_immutable_and_foreign_members_are_hidden(): void
    {
        $ownerId = ErpCompanyMembership::where('user_id', $this->owner->id)->value('id');
        $this->patchJson('/api/v1/erp/members/'.$ownerId, ['role' => 'manager'])->assertForbidden();
        $this->deleteJson('/api/v1/erp/members/'.$ownerId)->assertForbidden();
        $foreign = ErpCompanyMembership::factory()->create();
        foreach (['get', 'patch', 'delete'] as $verb) {
            $this->{$verb.'Json'}('/api/v1/erp/members/'.$foreign->id, ['role' => 'viewer'])->assertNotFound();
        }
        $user = User::factory()->create();
        foreach ([['role' => 'owner'], ['establishment_scope' => 'selected'], ['establishment_ids' => [1]], ['action_permissions' => ['*']], ['action_permissions' => ['erp.members.store']], ['action_permissions' => ['made.up']], ['user_id' => 9007199254740991], ['erp_company_id' => $foreign->erp_company_id], ['email' => 'replace@example.com']] as $bad) {
            $this->postJson('/api/v1/erp/members', array_replace($this->memberPayload($user), $bad))->assertUnprocessable();
        }
        $this->assertDatabaseCount('erp_company_memberships', 2);
        $this->postJson('/api/v1/erp/members/identity-lookup', ['email' => 'missing@example.com'])->assertNotFound();
    }

    public function test_nonowners_with_wildcards_cannot_administer_members(): void
    {
        foreach (['manager', 'operator', 'viewer'] as $role) {
            ErpCompanyMembership::where('user_id', $this->owner->id)->update(['role' => $role, 'action_permissions' => json_encode(['*'])]);
            $this->getJson('/api/v1/erp/members')->assertForbidden();
            $this->getJson('/api/v1/erp/member-permissions')->assertForbidden();
            $this->postJson('/api/v1/erp/members/identity-lookup', ['email' => $this->owner->email])->assertForbidden();
            $this->postJson('/api/v1/erp/members', $this->memberPayload(User::factory()->create()))->assertForbidden();
        }
    }

    public function test_permission_catalog_and_member_pagination_are_bounded(): void
    {
        $catalog = $this->getJson('/api/v1/erp/member-permissions')->assertOk()->json('data');
        $permissions = array_column($catalog, 'permission');
        $this->assertContains('erp.brands.store', $permissions);
        $this->assertContains('erp.establishments.update', $permissions);
        $this->assertNotContains('erp.members.store', $permissions);
        $this->assertNotContains('*', $permissions);
        foreach (['per_page=101', 'page=0', 'search[]=x'] as $query) {
            $this->getJson('/api/v1/erp/members?'.$query)->assertUnprocessable();
        }
    }

    public function test_establishment_crud_preserves_inactive_history_and_validates_tax_identity(): void
    {
        $response = $this->postJson('/api/v1/erp/establishments', ['name' => 'Filial', 'cnpj' => '30.288.513/0001-00', 'status' => 'active'])->assertCreated()->assertJsonPath('data.cnpj', '30288513000100');
        $this->getJson('/api/v1/erp/establishments?status=all')->assertOk()->assertJsonPath('meta.all_establishments', true);
        $id = $response->json('data.id');
        $this->patchJson('/api/v1/erp/establishments/'.$id, ['status' => 'inactive'])->assertOk();
        $this->getJson('/api/v1/erp/establishments/'.$id)->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson('/api/v1/erp/establishments?status=inactive&search=Filial')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/erp/establishments', ['name' => 'Second', 'cnpj' => '30288513000100', 'status' => 'active'])->assertCreated();
        $this->postJson('/api/v1/erp/establishments', ['name' => 'Invalid', 'cnpj' => '123', 'status' => 'active'])->assertUnprocessable();
        $foreign = ErpEstablishment::factory()->create();
        $this->getJson('/api/v1/erp/establishments/'.$foreign->id)->assertNotFound();
        $this->patchJson('/api/v1/erp/establishments/'.$id, ['erp_company_id' => $foreign->erp_company_id])->assertUnprocessable();
        foreach (['status=archived', 'per_page=101', 'page=0'] as $query) {
            $this->getJson('/api/v1/erp/establishments?'.$query)->assertUnprocessable();
        }
    }

    public function test_selected_direct_member_scope_limits_establishment_list_and_detail(): void
    {
        $allowed = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $denied = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = User::factory()->create();

        $response = $this->postJson('/api/v1/erp/members', [
            'user_id' => $member->id,
            'role' => 'manager',
            'action_permissions' => [],
            'establishment_scope' => 'selected',
            'establishment_ids' => [$allowed->id],
        ])->assertCreated()
            ->assertJsonPath('data.establishment_scope', 'selected')
            ->assertJsonPath('data.establishment_ids', [$allowed->id]);

        $this->assertNotEmpty($response->json('data.id'));
        $this->actingAs($member);
        $this->getJson('/api/v1/erp/establishments')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.all_establishments', false)
            ->assertJsonPath('data.0.id', $allowed->id);
        $this->getJson('/api/v1/erp/establishments/'.$denied->id)->assertNotFound();
    }

    public function test_establishment_writes_require_role_or_exact_grant_and_contract_release(): void
    {
        $membership = ErpCompanyMembership::where('user_id', $this->owner->id)->firstOrFail();
        foreach ([['manager', [], 201], ['operator', [], 403], ['operator', ['*'], 403], ['operator', ['erp.write'], 403], ['operator', ['erp.establishments.store'], 201], ['viewer', ['erp.establishments.store'], 403]] as [$role, $permissions, $status]) {
            $membership->update(['role' => $role, 'action_permissions' => $permissions]);
            if ($role === 'manager') {
                $this->getJson('/api/v1/erp/establishments?status=all')->assertOk()->assertJsonPath('meta.all_establishments', true);
            }
            $this->postJson('/api/v1/erp/establishments', ['name' => 'Filial', 'status' => 'active'])->assertStatus($status);
        }
        $membership->update(['role' => 'owner', 'action_permissions' => []]);
        ErpSubscription::where('erp_company_id', $this->company->id)->update(['status' => 'pending']);
        $this->postJson('/api/v1/erp/establishments', ['name' => 'Denied', 'status' => 'active'])->assertForbidden();
        $this->postJson('/api/v1/erp/members', $this->memberPayload(User::factory()->create()))->assertForbidden();
        ErpSubscription::where('erp_company_id', $this->company->id)->update(['status' => 'active']);
        config(['erp.operating_writes_enabled' => false]);
        $this->postJson('/api/v1/erp/establishments', ['name' => 'Denied', 'status' => 'active'])->assertForbidden();
        $this->postJson('/api/v1/erp/members', $this->memberPayload(User::factory()->create()))->assertForbidden();
    }

    public function test_rendered_validation_failure_after_insert_rolls_back_the_membership(): void
    {
        $target = User::factory()->create();
        ErpCompanyMembership::created(function (): void {
            throw ValidationException::withMessages(['role' => 'Injected failure']);
        });
        $this->postJson('/api/v1/erp/members', $this->memberPayload($target))->assertUnprocessable();
        $this->assertDatabaseMissing('erp_company_memberships', ['user_id' => $target->id]);
    }

    public function test_inactive_finance_locations_deny_new_effects_but_preserve_accepted_results(): void
    {
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $payload = ['direction' => 'in', 'amount' => '100', 'method' => 'manual', 'erp_establishment_id' => $establishment->id, 'source_operation_key' => 'original-payment'];
        $payment = $this->postJson('/api/v1/erp/payments', $payload)->assertCreated()->json('data.id');
        $obligation = $this->postJson('/api/v1/erp/obligations', ['amount' => '100', 'direction' => 'receivable', 'category' => 'manual', 'erp_establishment_id' => $establishment->id], ['Idempotency-Key' => 'original-obligation'])->assertCreated()->json('data.id');
        $allocation = ['erp_obligation_id' => $obligation, 'amount' => '20'];
        $original = $this->postJson('/api/v1/erp/payments/'.$payment.'/allocate', $allocation, ['Idempotency-Key' => 'accepted'])->assertOk()->json();
        $establishment->update(['status' => 'inactive']);
        $this->postJson('/api/v1/erp/payments', array_replace($payload, ['source_operation_key' => 'new-payment']))->assertUnprocessable();
        $this->postJson('/api/v1/erp/payments/'.$payment.'/allocate', $allocation, ['Idempotency-Key' => 'new-allocation'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/payments', $payload)->assertCreated()->assertJsonPath('data.id', $payment);
        $this->postJson('/api/v1/erp/payments/'.$payment.'/allocate', $allocation, ['Idempotency-Key' => 'accepted'])->assertOk()->assertExactJson($original);
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate', ['Idempotency-Key' => 'accepted'])->assertOk()->assertJsonPath('data.result', $original['data']);
        $this->getJson('/api/v1/erp/payments/'.$payment)->assertOk();
        $this->assertDatabaseCount('erp_payments', 1);
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 3);
    }

    public function test_inactive_obligation_location_alone_blocks_new_allocation(): void
    {
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $payment = $this->postJson('/api/v1/erp/payments', ['direction' => 'in', 'amount' => '100', 'method' => 'manual'], ['Idempotency-Key' => 'original-payment'])->assertCreated()->json('data.id');
        $obligation = $this->postJson('/api/v1/erp/obligations', ['amount' => '100', 'direction' => 'receivable', 'category' => 'manual', 'erp_establishment_id' => $establishment->id], ['Idempotency-Key' => 'original-obligation'])->assertCreated()->json('data.id');
        $establishment->update(['status' => 'inactive']);
        $this->postJson('/api/v1/erp/payments/'.$payment.'/allocate', ['erp_obligation_id' => $obligation, 'amount' => '20'], ['Idempotency-Key' => 'new-allocation'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_payment_allocations', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 2);
    }

    public function test_super_admin_and_delegated_office_admin_have_no_owner_authority(): void
    {
        $this->owner->forceFill(['is_super_admin' => true])->save();
        $membership = ErpCompanyMembership::where('user_id', $this->owner->id)->firstOrFail();
        $membership->update(['role' => 'manager', 'action_permissions' => ['*']]);
        $this->getJson('/api/v1/erp/members')->assertForbidden();
        $membership->delete();
        $this->getJson('/api/v1/erp/members')->assertForbidden();
        $office = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $this->owner->id, 'role' => 'admin']);
        ErpOfficeDelegation::factory()->create(['erp_company_id' => $this->company->id, 'account_id' => $office->id, 'authorized_by_user_id' => $this->owner->id, 'permission_allowlist' => ['*']]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/account/switch', ['account_id' => $office->id])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])->assertOk()->assertJsonPath('current_company.access_kind', 'delegated');
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf);
        $this->getJson('/api/v1/erp/members')->assertForbidden();
        $this->getJson('/api/v1/erp/member-permissions')->assertForbidden();
        $this->postJson('/api/v1/erp/members/identity-lookup', ['email' => $this->owner->email])->assertForbidden();
        $this->postJson('/api/v1/erp/members', $this->memberPayload(User::factory()->create()))->assertForbidden();
    }

    public function test_lookup_is_exact_throttled_and_never_grants_membership(): void
    {
        $user = User::factory()->create(['email' => 'exact-identity@example.com']);
        $this->postJson('/api/v1/erp/members/identity-lookup', ['email' => 'identity@example.com'])->assertNotFound();
        for ($attempt = 0; $attempt < 29; $attempt++) {
            $this->postJson('/api/v1/erp/members/identity-lookup', ['email' => $user->email])->assertOk();
        }
        $this->postJson('/api/v1/erp/members/identity-lookup', ['email' => $user->email])->assertStatus(429);
        $this->assertDatabaseMissing('erp_company_memberships', ['user_id' => $user->id]);
    }

    public function test_member_patch_rejects_reassignment_and_replaces_explicit_grants(): void
    {
        $target = User::factory()->create();
        $id = $this->postJson('/api/v1/erp/members', $this->memberPayload($target))->assertCreated()->json('data.id');
        foreach ([['user_id' => $this->owner->id], ['erp_company_id' => 123], ['company_id' => 123], ['account_id' => 123], ['password' => 'changed'], ['role' => 'owner'], ['establishment_scope' => 'all', 'establishment_ids' => [1]], ['action_permissions' => ['erp.write']], ['action_permissions' => ['erp.delegations.accept']], []] as $bad) {
            $this->patchJson('/api/v1/erp/members/'.$id, $bad)->assertUnprocessable();
        }
        $this->patchJson('/api/v1/erp/members/'.$id, ['establishment_scope' => 'selected', 'establishment_ids' => []])
            ->assertOk()->assertJsonPath('data.establishment_scope', 'selected')->assertJsonPath('data.establishment_ids', []);
        $this->patchJson('/api/v1/erp/members/'.$id, ['action_permissions' => ['erp.brands.store', 'erp.brands.index']])->assertOk();
        $this->patchJson('/api/v1/erp/members/'.$id, ['action_permissions' => ['erp.brands.index']])->assertOk()->assertJsonPath('data.effective_permissions', ['erp.brands.index']);
        $this->patchJson('/api/v1/erp/members/'.$id, ['action_permissions' => null])->assertOk()->assertJsonPath('data.effective_permissions', ['erp.read']);
        $this->assertSame($target->id, ErpCompanyMembership::findOrFail($id)->user_id);
    }

    public function test_owner_administration_is_independent_of_narrow_ordinary_grants(): void
    {
        ErpCompanyMembership::where('user_id', $this->owner->id)->update(['action_permissions' => json_encode(['erp.brands.index'])]);
        $this->getJson('/api/v1/erp/members')->assertOk();
        $this->getJson('/api/v1/erp/member-permissions')->assertOk();
        $target = User::factory()->create();
        $this->postJson('/api/v1/erp/members/identity-lookup', ['email' => $target->email])->assertOk();
        $id = $this->postJson('/api/v1/erp/members', $this->memberPayload($target))->assertCreated()->json('data.id');
        $this->patchJson('/api/v1/erp/members/'.$id, ['role' => 'viewer'])->assertOk();
        $this->deleteJson('/api/v1/erp/members/'.$id)->assertNoContent();
        $this->postJson('/api/v1/erp/brands', ['name' => 'Not permitted'])->assertForbidden();
        $this->postJson('/api/v1/erp/establishments', ['name' => 'Not permitted', 'status' => 'active'])->assertForbidden();
    }

    public function test_member_identity_requires_integer_or_numeric_string_not_json_boolean_or_float(): void
    {
        $target = User::factory()->create();
        foreach ([true, false, (float) $target->id] as $identifier) {
            $this->postJson('/api/v1/erp/members', array_replace($this->memberPayload($target), ['user_id' => $identifier]), [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable();
            $this->assertDatabaseCount('erp_company_memberships', 1);
        }
        $this->postJson('/api/v1/erp/members', array_replace($this->memberPayload($target), ['user_id' => (string) $target->id]))->assertCreated();
    }
}
