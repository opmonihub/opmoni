<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\OfficeErpCommercialTestCase;

class ClientAccessAdmissionTest extends OfficeErpCommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(Route::has('office-erp-commercial.members.index'));
        self::assertTrue(Route::has('office-erp-commercial.members.password-recovery'));
    }

    public function test_suspended_account_denies_contract_member_and_credential_routes_without_effects(): void
    {
        [$ctx, $contract, $company, $membership] = $this->managedCompanyWithMember();
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        $before = $this->snapshot($ctx->accountId, $company->id);
        DB::table('accounts')->where('id', $ctx->accountId)->update(['status' => 'suspended']);

        $this->postJson('/api/v1/erp-commercial/contracts/'.$contract->id.'/activate', [], [
            'Idempotency-Key' => 'suspended-contract-activate',
        ])->assertForbidden();
        $this->getJson('/api/v1/erp-commercial/companies/'.$company->id.'/members')->assertForbidden();
        $this->deleteJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$membership->id, [
            'confirmed' => true,
        ], ['Idempotency-Key' => 'suspended-member-remove'])->assertForbidden();
        $this->postJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$membership->id.'/recovery', [], [
            'Idempotency-Key' => 'suspended-member-recovery',
        ])->assertForbidden();

        self::assertSame($before, $this->snapshot($ctx->accountId, $company->id));
        self::assertSame('suspended', DB::table('accounts')->where('id', $ctx->accountId)->value('status'));
    }

    public function test_canceled_office_subscription_allows_member_maintenance_but_blocks_new_company_registration(): void
    {
        [$ctx, , $company, $membership] = $this->managedCompanyWithMember();
        $newClient = $this->clientFor($ctx);
        $ctx->account->subscription()->firstOrFail()->forceFill(['status' => 'canceled'])->save();
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        $this->patchJson('/api/v1/erp-commercial/companies/'.$company->id.'/members/'.$membership->id, [
            'role' => 'manager',
            'permissions_mode' => 'defaults',
            'action_permissions' => [],
            'establishment_scope' => 'all',
            'establishment_ids' => [],
        ], ['Idempotency-Key' => 'canceled-subscription-member-maintenance'])
            ->assertOk()->assertJsonPath('data.role', 'manager');

        $this->withHeader('Idempotency-Key', 'canceled-subscription-company-register')
            ->postJson('/api/v1/erp-commercial/companies', ['client_ids' => [$newClient->id]])
            ->assertForbidden();
        $this->assertDatabaseCount('office_erp_company_registrations', 1);
        self::assertSame(0, DB::table('erp_companies')->where('cnpj', $newClient->tax_id)->count());
        self::assertSame('manager', $membership->fresh()->role);
        self::assertSame(0, DB::table('office_erp_commercial_operations')->where('idempotency_key', 'canceled-subscription-company-register')->count());
    }

    public function test_super_admin_without_current_office_admin_role_cannot_bypass_member_route_authority(): void
    {
        [$ctx, , $company] = $this->managedCompanyWithMember();
        $ctx->actor->forceFill(['is_super_admin' => true])->save();
        DB::table('account_memberships')->where('account_id', $ctx->accountId)
            ->where('user_id', $ctx->actor->id)->update(['role' => 'operador']);
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);
        $before = $this->snapshot($ctx->accountId, $company->id);

        $this->getJson('/api/v1/erp-commercial/companies/'.$company->id.'/members')->assertForbidden();

        self::assertSame($before, $this->snapshot($ctx->accountId, $company->id));
    }

    /** @return array{OfficeErpExecutionContext, OfficeErpContract, ErpCompany, ErpCompanyMembership} */
    private function managedCompanyWithMember(): array
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 3, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill([
            'erp_company_id' => $company->id,
            'agreement_status' => 'accepted',
            'accepted_at' => now(),
        ])->save();
        $technicalPlan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $technicalPlan->id,
            'office_erp_contract_id' => $contract->id,
        ]);
        OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'erp_company_id' => $company->id,
            'state' => 'occupied',
            'expires_at' => null,
        ]);
        OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $contract->client_id,
            'erp_company_id' => $company->id,
        ]);
        $recipient = User::factory()->create(['email' => 'admission-member-'.bin2hex(random_bytes(5)).'@example.test']);
        $membership = ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $recipient->id,
            'role' => 'operator',
        ]);

        return [$ctx, $contract->fresh(), $company, $membership];
    }

    /** @return array<string, string> */
    private function snapshot(int $accountId, int $companyId): array
    {
        $tables = [
            'office_erp_contracts',
            'office_erp_contract_revisions',
            'office_erp_invoices',
            'office_erp_licence_allocations',
            'users',
            'erp_company_memberships',
            'erp_company_member_invitations',
            'office_erp_access_deliveries',
            'office_erp_commercial_operations',
            'office_erp_audit_events',
        ];
        $snapshot = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            if (in_array($table, ['office_erp_contracts', 'office_erp_contract_revisions', 'office_erp_invoices', 'office_erp_licence_allocations', 'office_erp_commercial_operations', 'office_erp_audit_events'], true)) {
                $query->where('account_id', $accountId);
            } elseif (in_array($table, ['erp_company_memberships', 'erp_company_member_invitations'], true)) {
                $query->where('erp_company_id', $companyId);
            } elseif ($table === 'office_erp_access_deliveries') {
                $query->where('account_id', $accountId)->where('erp_company_id', $companyId);
            } elseif ($table === 'users') {
                $userIds = DB::table('account_memberships')->where('account_id', $accountId)->pluck('user_id')
                    ->merge(DB::table('erp_company_memberships')->where('erp_company_id', $companyId)->pluck('user_id'))
                    ->unique()->values();
                $query->whereIn('id', $userIds);
            }
            $rows = $query->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
            $snapshot[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $snapshot;
    }
}
