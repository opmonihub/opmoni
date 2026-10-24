<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpProductTaxProfile;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ErpProductTaxProfileDelegationTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/erp/tax-settings/product-tax-profiles';

    private const ACTIONS = ['erp.product-tax-profiles.index', 'erp.product-tax-profiles.store', 'erp.product-tax-profiles.update', 'erp.product-tax-profiles.destroy'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
    }

    /** @return array{ErpOfficeDelegation, AccountMembership, ErpProductTaxProfile} */
    private function delegatedContext(?array $stored): array
    {
        $company = ErpCompany::factory()->create();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        $office = Account::factory()->create();
        $membership = AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $actor->id, 'role' => 'operador']);
        $delegation = ErpOfficeDelegation::factory()->create(['erp_company_id' => $company->id, 'account_id' => $office->id, 'authorized_by_user_id' => $owner->id, 'permission_allowlist' => $stored ?? []]);
        if ($stored === null) {
            DB::table('erp_office_delegations')->where('id', $delegation->id)->update(['permission_allowlist' => 'null']);
            $delegation->refresh();
        }
        $profile = ErpProductTaxProfile::factory()->create(['erp_company_id' => $company->id]);
        $profile->revisions()->create(['erp_company_id' => $company->id, 'revision' => 1, 'name' => $profile->name, 'status' => 'active', 'fields' => ['pis_cst' => '01'], 'applicability' => [], 'actor_user_id' => $owner->id, 'access_kind' => 'direct']);
        $profile->update(['current_revision' => 1]);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);

        return [$delegation, $membership, $profile];
    }

    /** @return iterable<string, array{?array}> */
    public static function historicalAllowlists(): iterable
    {
        yield 'JSON null defaults to erp.read' => [null];
        yield 'empty defaults to erp.read' => [[]];
        yield 'global read' => [['erp.read']];
        yield 'global write' => [['erp.write']];
        yield 'global wildcard' => [['*']];
        yield 'family wildcard' => [['erp.product-tax-profiles.*']];
        yield 'family read' => [['erp.product-tax-profiles.read']];
        yield 'family write' => [['erp.product-tax-profiles.write']];
    }

    #[DataProvider('historicalAllowlists')]
    public function test_historical_delegations_cannot_read_or_mutate_new_profiles_or_advertise_their_grants(?array $stored): void
    {
        [$delegation, , $profile] = $this->delegatedContext($stored);
        if ($stored === null || $stored === []) {
            $this->assertSame(['erp.read'], $delegation->effectivePermissions());
        }
        $this->getJson(self::URL)->assertForbidden();
        $this->getJson(self::URL.'/'.$profile->id)->assertForbidden();
        $this->postJson(self::URL, ['name' => 'Denied'])->assertForbidden();
        $this->patchJson(self::URL.'/'.$profile->id, ['name' => 'Denied'])->assertForbidden();
        $this->deleteJson(self::URL.'/'.$profile->id)->assertForbidden();
        $permissions = $this->getJson('/api/v1/me')->assertOk()->json('current_company.permissions');
        foreach (self::ACTIONS as $action) {
            $this->assertNotContains($action, $permissions);
        }
        $this->assertSame(1, $profile->fresh()->current_revision);
        $this->assertDatabaseCount('erp_product_tax_profiles', 1);
        $this->assertDatabaseCount('erp_product_tax_profile_revisions', 1);
        $this->assertDatabaseCount('erp_catalog_attributions', 0);
    }

    public function test_exact_grants_authorize_only_their_profile_actions_and_retain_role_revocation_and_attribution_fences(): void
    {
        [$delegation, $membership, $profile] = $this->delegatedContext(['erp.product-tax-profiles.index']);
        $this->getJson(self::URL)->assertOk();
        $this->getJson(self::URL.'/'.$profile->id)->assertOk()->assertJsonPath('data.fields.pis_cst', '01');
        $this->postJson(self::URL, ['name' => 'Denied'])->assertForbidden();
        $this->patchJson(self::URL.'/'.$profile->id, ['name' => 'Denied'])->assertForbidden();
        $this->deleteJson(self::URL.'/'.$profile->id)->assertForbidden();

        $delegation->update(['permission_allowlist' => ['erp.product-tax-profiles.store']]);
        $id = $this->postJson(self::URL, ['name' => 'Exact grant', 'fields' => ['pis_cst' => '01']])->assertCreated()->json('data.id');
        $this->getJson(self::URL.'/'.$id)->assertForbidden();
        $this->patchJson(self::URL.'/'.$id, ['name' => 'Denied'])->assertForbidden();
        $this->deleteJson(self::URL.'/'.$id)->assertForbidden();

        $delegation->update(['permission_allowlist' => ['erp.product-tax-profiles.update']]);
        $this->patchJson(self::URL.'/'.$id, ['name' => 'Updated by exact grant'])->assertOk();
        $this->deleteJson(self::URL.'/'.$id)->assertForbidden();
        $delegation->update(['permission_allowlist' => ['erp.product-tax-profiles.destroy']]);
        $this->deleteJson(self::URL.'/'.$id)->assertOk()->assertJsonPath('data.status', 'archived');
        $events = DB::table('erp_catalog_attributions')->where('resource_type', 'product_tax_profile')->where('resource_id', $id)->get();
        $this->assertSame(['created', 'updated', 'archived'], $events->pluck('action')->all());
        foreach ($events as $event) {
            $this->assertSame('delegated', $event->access_kind);
            $this->assertSame($membership->user_id, $event->actor_user_id);
            $this->assertSame($delegation->account_id, $event->account_id);
            $this->assertSame($delegation->id, $event->erp_office_delegation_id);
        }

        $delegation->update(['permission_allowlist' => self::ACTIONS]);
        $membership->update(['role' => 'user']);
        $this->getJson(self::URL.'/'.$id)->assertOk();
        $this->postJson(self::URL, ['name' => 'Read only'])->assertForbidden();
        $this->patchJson(self::URL.'/'.$id, ['name' => 'Read only'])->assertForbidden();
        $this->deleteJson(self::URL.'/'.$id)->assertForbidden();
        $this->assertSame(['erp.product-tax-profiles.index'], $this->getJson('/api/v1/me')->assertOk()->json('current_company.permissions'));
        $membership->update(['role' => 'operador']);
        $delegation->update(['permission_allowlist' => ['*']]);
        $this->getJson(self::URL.'/'.$id)->assertForbidden();
        $delegation->update(['permission_allowlist' => self::ACTIONS, 'status' => 'revoked']);
        $this->getJson(self::URL)->assertForbidden();
        $this->postJson(self::URL, ['name' => 'Revoked'])->assertForbidden();
        $this->assertDatabaseCount('erp_catalog_attributions', 3);
    }
}
