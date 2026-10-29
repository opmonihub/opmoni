<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Tests\OfficeErpCommercialTestCase;

class CompanyPortfolioTest extends OfficeErpCommercialTestCase
{
    public function test_registered_company_list_excludes_unregistered_and_foreign_clients(): void
    {
        $ctx = $this->officeContext();
        $this->clientFor($ctx);
        $registration = OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId, 'client_id' => $this->clientFor($ctx)->id,
        ]);
        OfficeErpCompanyRegistration::factory()->create();
        $response = $this->actingAs($ctx->actor)->getJson('/api/v1/erp-commercial/companies')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $registration->erp_company_id)
            ->assertJsonMissingPath('data.0.members');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_actual_subscription_link_selects_current_contract_instead_of_latest_contract(): void
    {
        $ctx = $this->officeContext();
        $current = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $current->forceFill(['erp_company_id' => $company->id])->save();
        $later = OfficeErpContract::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $current->client_id, 'erp_company_id' => $company->id, 'agreement_status' => 'canceled']);
        self::assertGreaterThan($current->id, $later->id);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $current->id]);
        OfficeErpCompanyRegistration::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $current->client_id, 'erp_company_id' => $company->id]);
        $this->actingAs($ctx->actor)->getJson('/api/v1/erp-commercial/companies')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.current_contract_id', $current->id)
            ->assertJsonPath('data.0.capabilities.relationship', 'current');
    }

    public function test_historical_registration_does_not_authorize_current_foreign_management(): void
    {
        $ctx = $this->officeContext();
        $foreign = $this->officeContext();
        $contract = $this->acceptedContract($foreign);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
        OfficeErpCompanyRegistration::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $this->clientFor($ctx)->id, 'erp_company_id' => $company->id]);
        $this->actingAs($ctx->actor)->getJson('/api/v1/erp-commercial/companies/'.$company->id)
            ->assertOk()->assertJsonPath('data.current_contract_id', null)
            ->assertJsonPath('data.capabilities.relationship', 'historical')
            ->assertJsonPath('data.capabilities.can_manage_members', false)->assertJsonPath('data.capabilities.can_edit_profile', false);
        $other = OfficeErpCompanyRegistration::factory()->create();
        $this->getJson('/api/v1/erp-commercial/companies/'.$other->erp_company_id)->assertNotFound();
    }

    public function test_registration_reads_use_stable_server_sort_and_authoritative_pagination(): void
    {
        $ctx = $this->officeContext();
        $ids = [];
        foreach (range(1, 26) as $index) {
            $company = ErpCompany::factory()->create(['name' => 'Same name']);
            $ids[] = $company->id;
            OfficeErpCompanyRegistration::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $this->clientFor($ctx)->id, 'erp_company_id' => $company->id, 'registered_at' => '2026-10-01 00:00:00']);
        }
        $this->actingAs($ctx->actor)->getJson('/api/v1/erp-commercial/companies?sort=name&direction=asc&page=2')
            ->assertOk()->assertJsonPath('meta.total', 26)->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[25]);
        $this->getJson('/api/v1/erp-commercial/companies?sort=registered_at&direction=desc')
            ->assertOk()->assertJsonPath('data.0.id', $ids[25]);
        $this->getJson('/api/v1/erp-commercial/companies?sort=arbitrary')->assertUnprocessable();
        $this->getJson('/api/v1/erp-commercial/companies?foreign_filter=1')->assertUnprocessable();
    }

    public function test_portfolio_picker_identifies_registered_unassigned_clients_on_every_server_page(): void
    {
        $ctx = $this->officeContext();
        $registered = $this->clientFor($ctx);
        $other = $this->clientFor($ctx);
        $company = ErpCompany::factory()->create(['owner_setup_status' => 'unclaimed']);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id]);
        $registration = OfficeErpCompanyRegistration::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $registered->id, 'erp_company_id' => $company->id]);
        $response = $this->actingAs($ctx->actor)->getJson('/api/v1/erp-commercial/clients?sort=id&direction=asc')->assertOk();
        $rows = collect($response->json('data'))->keyBy('id');
        self::assertSame(['registration_id' => $registration->id, 'company_id' => $company->id], $rows[$registered->id]['erp_registration']);
        self::assertNull($rows[$registered->id]['subscription']);
        self::assertNull($rows[$other->id]['erp_registration']);
        self::assertSame(2, $response->json('meta.total'));
    }

    public function test_same_office_canceled_assignment_can_be_recreated_only_after_release(): void
    {
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id, 'agreement_status' => 'canceled'])->save();
        ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id, 'status' => 'canceled']);
        OfficeErpLicenceAllocation::factory()->create(['account_id' => $ctx->accountId, 'contract_id' => $contract->id, 'erp_company_id' => $company->id, 'state' => 'released', 'released_at' => now()]);
        OfficeErpCompanyRegistration::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $contract->client_id, 'erp_company_id' => $company->id]);
        $this->actingAs($ctx->actor)->getJson('/api/v1/erp-commercial/companies/'.$company->id)->assertOk()
            ->assertJsonPath('data.current_contract_id', null)->assertJsonPath('data.capabilities.relationship', 'historical')
            ->assertJsonPath('data.capabilities.can_assign', true)->assertJsonPath('data.capabilities.can_manage_members', false)
            ->assertJsonPath('data.capabilities.can_edit_profile', false);
        $company->subscription()->update(['status' => 'active']);
        $this->getJson('/api/v1/erp-commercial/companies/'.$company->id)->assertOk()->assertJsonPath('data.capabilities.can_assign', false);
    }

    public function test_unassigned_registration_requires_pending_unclaimed_origin_and_consumes_no_capacity(): void
    {
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 0, 'module_keys' => []]);
        $company = ErpCompany::factory()->create(['owner_setup_status' => 'unclaimed']);
        ErpSubscription::factory()->create(['erp_company_id' => $company->id]);
        OfficeErpCompanyRegistration::factory()->create(['account_id' => $ctx->accountId, 'client_id' => $this->clientFor($ctx)->id, 'erp_company_id' => $company->id]);
        $this->actingAs($ctx->actor)->getJson('/api/v1/erp-commercial/companies?state=unassigned')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.capabilities.relationship', 'unassigned')
            ->assertJsonPath('data.0.capabilities.can_edit_profile', true)->assertJsonPath('data.0.capabilities.can_assign', false);
        $this->getJson('/api/v1/erp-commercial/context')->assertOk()->assertJsonPath('data.can_register_company', true);
        $this->assertDatabaseCount('office_erp_licence_allocations', 0);
    }
}
