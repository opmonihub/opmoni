<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Illuminate\Support\Facades\DB;
use Tests\OfficeErpCommercialTestCase;

class CatalogPreservationTest extends OfficeErpCommercialTestCase
{
    public function test_erp_company_catalog_initializer_preserves_existing_office_retail_and_linked_company_records(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $company = ErpCompany::factory()->create();
        $contract = $this->acceptedContract($ctx, ['price' => '137.50', 'module_keys' => ['purchases']]);
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        $invoice = $this->issuedInvoice($ctx, $contract, '137.50');
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'office_erp_contract_id' => $contract->id,
            'status' => 'active',
        ]);

        $retailPlan = OfficeErpSalesPlan::query()->findOrFail($contract->sales_plan_id);
        $retailPlanRevision = OfficeErpSalesPlanRevision::query()
            ->where('sales_plan_id', $retailPlan->id)
            ->firstOrFail();
        $contractRevision = $contract->currentRevision()->firstOrFail();
        $this->assertSame((int) $retailPlanRevision->id, (int) $retailPlan->current_revision_id);
        $this->assertSame('137.50', (string) $contractRevision->price);
        $this->assertSame('issued', $invoice->document_status);
        $this->assertSame((int) $contract->id, (int) $subscription->office_erp_contract_id);

        $tables = [
            'office_erp_sales_plans',
            'office_erp_sales_plan_revisions',
            'office_erp_contracts',
            'office_erp_contract_revisions',
            'office_erp_invoices',
            'erp_subscriptions',
            'erp_plans',
            'erp_plan_modules',
        ];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $this->tableSnapshot($table);
        }
        $countsBefore = array_map('count', $before);

        $catalog = app(ErpCommercialCatalog::class);
        $catalog->ensureBasicPlan();
        $catalog->ensureBasicPlan();

        $after = [];
        foreach ($tables as $table) {
            $after[$table] = $this->tableSnapshot($table);
        }

        $this->assertSame($countsBefore, array_map('count', $after));
        $this->assertSame($before, $after);
        $this->assertSame('137.50', (string) $contract->fresh()->currentRevision()->firstOrFail()->price);
        $this->assertSame('issued', OfficeErpInvoice::query()->findOrFail($invoice->id)->document_status);
        $this->assertSame((int) $contract->id, (int) $subscription->fresh()->office_erp_contract_id);
    }

    /** @return list<array<string, mixed>> */
    private function tableSnapshot(string $table): array
    {
        return DB::table($table)
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
