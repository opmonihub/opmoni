<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\ErpCommercialCatalog;
use App\Erp\ErpCommercialGate;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Tests\OfficeErpCommercialTestCase;

class CompanyAccessTest extends OfficeErpCommercialTestCase
{
    public function test_linked_company_uses_accepted_contract_grants_after_office_resale_rights_loss(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($ctx, ['module_keys' => ['purchases']]);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id, 'agreement_status' => 'effective'])->save();
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'office_erp_contract_id' => $contract->id,
            'status' => 'active',
        ]);
        $plan = $ctx->account->subscription->plan;
        $allowance = $plan->erp_commercial;
        $allowance['enabled'] = false;
        $allowance['module_keys'] = [];
        $plan->forceFill(['erp_commercial' => $allowance])->save();

        $gate = app(ErpCommercialGate::class);
        self::assertTrue($gate->moduleEnabled($company, 'purchases'));
        self::assertFalse($gate->moduleEnabled($company, 'sales'));
        self::assertSame('active', $subscription->fresh()->status);
    }

    public function test_unlinked_direct_subscription_keeps_legacy_plan_grants(): void
    {
        $company = ErpCompany::factory()->create();
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $subscription = ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
        ]);
        $before = $subscription->fresh()->only(['erp_plan_id', 'status', 'office_erp_contract_id']);

        $gate = app(ErpCommercialGate::class);

        self::assertTrue($gate->allowsOperatingWrites($company));
        self::assertSame($before, $subscription->fresh()->only(['erp_plan_id', 'status', 'office_erp_contract_id']));
        self::assertNull($subscription->fresh()->office_erp_contract_id);
    }
}
