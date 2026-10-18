<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\ErpEstablishmentAccess;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\ErpCategory;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpTaxRate;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Fiscal\ErpFiscalCertificate;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use App\Models\Erp\Reports\ErpReportExport;
use App\Models\Erp\Sales\ErpOrder;
use App\Models\Erp\Sales\ErpQuote;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Models\Erp\Stock\ErpStockAdjustment;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\Erp\Stock\ErpStockTransfer;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class ErpEstablishmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private ErpCompany $company;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'access.ingress_key' => 'test-key',
            'erp.operating_writes_enabled' => true,
            'erp.release_enabled' => true,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'session.store' => null,
        ]);
        $this->withHeaders([
            'X-Opmoni-Ingress-Key' => 'test-key',
            'X-Opmoni-Client-IP' => '127.0.0.1',
            'X-Opmoni-Client-Proto' => 'http',
            'Origin' => 'http://localhost:3000',
        ]);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $this->owner = User::factory()->create();
        $this->company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->owner->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => $plan->id, 'status' => 'active']);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $this->owner->email, 'password' => 'password'])->assertOk();
        $this->withHeader('X-Company-Id', (string) $this->company->id);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
    }

    private function selectedMember(array $establishmentIds): User
    {
        $member = User::factory()->create();
        $this->postJson('/api/v1/erp/members', [
            'user_id' => $member->id,
            'role' => 'manager',
            'establishment_scope' => 'selected',
            'establishment_ids' => $establishmentIds,
        ])->assertCreated();

        return $member;
    }

    public function test_selected_scope_filters_stock_purchase_and_sale_lists_and_blocks_both_transfer_legs(): void
    {
        $allowed = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $denied = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$allowed->id]);

        ErpStockBalance::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'erp_product_variant_id' => 901, 'quantity_on_hand' => '10.0000', 'average_cost' => '2.0000']);
        ErpStockBalance::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'erp_product_variant_id' => 902, 'quantity_on_hand' => '50.0000', 'average_cost' => '3.0000']);
        $purchase = ErpPurchase::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'document_no' => 'scope-denied', 'status' => 'draft', 'total_amount' => '12.0000']);
        ErpPurchase::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'document_no' => 'scope-allowed', 'status' => 'draft', 'total_amount' => '10.0000']);
        $sale = ErpSale::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'invoice_no' => 'scope-denied', 'status' => 'draft', 'total_amount' => '12.0000']);
        ErpSale::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'invoice_no' => 'scope-allowed', 'status' => 'draft', 'total_amount' => '10.0000']);
        $quote = ErpQuote::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'quote_no' => 'scope-denied', 'status' => 'draft', 'total_amount' => '12.0000']);
        $order = ErpOrder::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'order_no' => 'scope-denied', 'status' => 'confirmed', 'total_amount' => '12.0000']);
        ErpStockAdjustment::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'erp_product_variant_id' => 904, 'quantity_delta' => '-1.0000', 'reason' => 'hidden', 'status' => 'posted']);
        ErpStockMovement::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'erp_product_variant_id' => 904, 'direction' => 'out', 'quantity' => '1.0000', 'source_type' => 'manual', 'source_id' => 0, 'source_operation_key' => 'hidden-stock-movement']);
        ErpStockTransfer::query()->create(['erp_company_id' => $this->company->id, 'from_establishment_id' => $denied->id, 'to_establishment_id' => $allowed->id, 'erp_product_variant_id' => 904, 'quantity' => '1.0000', 'status' => 'posted']);

        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/stock/balances')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.erp_establishment_id', $allowed->id);
        $this->getJson('/api/v1/erp/purchases')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/erp/purchases/'.$purchase->id)->assertNotFound();
        $this->getJson('/api/v1/erp/sales')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/erp/sales/'.$sale->id)->assertNotFound();
        $this->postJson('/api/v1/erp/sales/'.$sale->id.'/post')->assertNotFound();
        $this->getJson('/api/v1/erp/quotes')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/quotes/'.$quote->id)->assertNotFound();
        $this->getJson('/api/v1/erp/orders')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/orders/'.$order->id)->assertNotFound();
        $this->postJson('/api/v1/erp/quotes', ['erp_establishment_id' => $denied->id, 'quote_no' => 'new-hidden', 'total_amount' => '1'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/orders', ['erp_establishment_id' => $denied->id, 'order_no' => 'new-hidden', 'total_amount' => '1'])->assertUnprocessable();
        $this->getJson('/api/v1/erp/stock/adjustments')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/stock/movements')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/stock/transfers')->assertOk()->assertJsonPath('meta.total', 0);

        $this->postJson('/api/v1/erp/stock/transfers', [
            'from_establishment_id' => $allowed->id,
            'to_establishment_id' => $denied->id,
            'erp_product_variant_id' => 901,
            'quantity' => '1.0000',
            'source_operation_key' => 'selected-cross-location-transfer',
        ])->assertUnprocessable();
        $this->postJson('/api/v1/erp/stock/transfers', [
            'from_establishment_id' => $denied->id,
            'to_establishment_id' => $allowed->id,
            'erp_product_variant_id' => 901,
            'quantity' => '1.0000',
            'source_operation_key' => 'selected-cross-location-transfer-reverse',
        ])->assertUnprocessable();
        $this->assertSame(1, ErpStockTransfer::query()->count());
        $this->assertSame('10.0000', ErpStockBalance::query()->where('erp_establishment_id', $allowed->id)->value('quantity_on_hand'));
    }

    public function test_selected_scope_hides_company_global_finance_and_keeps_company_catalogs_global(): void
    {
        $allowed = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $denied = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$allowed->id]);
        $globalAccount = ErpFinancialAccount::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => null, 'name' => 'Company bank']);
        ErpFinancialAccount::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'name' => 'Allowed bank']);
        ErpFinancialAccount::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'name' => 'Private bank']);
        ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => null, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '20.0000', 'open_amount' => '20.0000']);
        ErpPayment::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => null, 'amount' => '10.0000', 'method' => 'cash', 'status' => 'posted']);
        ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '12.0000', 'open_amount' => '12.0000']);
        ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '99.0000', 'open_amount' => '99.0000']);
        ErpPayment::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'amount' => '8.0000', 'method' => 'cash', 'status' => 'posted']);
        ErpPayment::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'amount' => '99.0000', 'method' => 'cash', 'status' => 'posted']);
        $globalTax = ErpTaxRate::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => null, 'name' => 'Global ICMS', 'rate' => '17.0000']);
        ErpTaxRate::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'name' => 'Allowed ICMS', 'rate' => '18.0000']);
        $hiddenTax = ErpTaxRate::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'name' => 'Hidden ICMS', 'rate' => '19.0000']);
        ErpOperationNature::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => null, 'cfop' => '5102', 'description' => 'Global sale']);
        ErpOperationNature::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'cfop' => '5103', 'description' => 'Allowed sale']);
        $hiddenNature = ErpOperationNature::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'cfop' => '5104', 'description' => 'Hidden sale']);
        $category = ErpCategory::factory()->create(['erp_company_id' => $this->company->id, 'name' => 'Company category']);

        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/financial-accounts')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.erp_establishment_id', $allowed->id);
        $this->getJson('/api/v1/erp/financial-accounts/'.$globalAccount->id)->assertNotFound();
        $this->getJson('/api/v1/erp/obligations')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.erp_establishment_id', $allowed->id);
        $this->getJson('/api/v1/erp/payments')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.erp_establishment_id', $allowed->id);
        $this->getJson('/api/v1/erp/categories')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $category->id);
        $this->getJson('/api/v1/erp/tax-settings/tax-rates')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/erp/tax-settings/tax-rates/'.$globalTax->id)->assertOk();
        $this->getJson('/api/v1/erp/tax-settings/tax-rates/'.$hiddenTax->id)->assertNotFound();
        $this->getJson('/api/v1/erp/tax-settings/operation-natures')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/erp/tax-settings/operation-natures/'.$hiddenNature->id)->assertNotFound();
        $this->postJson('/api/v1/erp/financial-accounts', ['name' => 'Unscoped bank'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/financial-accounts', ['erp_establishment_id' => $denied->id, 'name' => 'Denied bank'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/obligations', ['direction' => 'receivable', 'category' => 'manual', 'amount' => '1'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/payments', ['amount' => '1', 'method' => 'cash'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/tax-settings/tax-rates', ['name' => 'New global rate', 'rate' => '5'])->assertCreated();
        $this->postJson('/api/v1/erp/tax-settings/tax-rates', ['erp_establishment_id' => $denied->id, 'name' => 'Denied local rate', 'rate' => '6'])->assertUnprocessable();
    }

    public function test_allocation_rejects_a_payment_and_obligation_from_different_member_scopes(): void
    {
        $allowed = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $denied = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$allowed->id]);
        $payment = ErpPayment::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'amount' => '100.0000', 'method' => 'cash', 'status' => 'posted']);
        $hiddenPayment = ErpPayment::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'amount' => '100.0000', 'method' => 'cash', 'status' => 'posted']);
        $obligation = ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $denied->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '100.0000', 'open_amount' => '100.0000']);
        $allowedObligation = ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $allowed->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '100.0000', 'open_amount' => '100.0000']);

        $this->actingAs($member, 'web');
        $this->postJson('/api/v1/erp/payments/'.$payment->id.'/allocate', [
            'erp_obligation_id' => $obligation->id,
            'amount' => '10.0000',
        ], ['Idempotency-Key' => 'cross-scope-allocation'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/payments/'.$hiddenPayment->id.'/allocate', [
            'erp_obligation_id' => $allowedObligation->id,
            'amount' => '10.0000',
        ], ['Idempotency-Key' => 'cross-scope-payment'])->assertNotFound();
        $this->assertDatabaseCount('erp_payment_allocations', 0);
        $this->assertDatabaseCount('erp_operation_receipts', 0);
    }

    public function test_empty_selected_scope_is_valid_and_grants_no_establishment_data(): void
    {
        $location = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = User::factory()->create();
        $created = $this->postJson('/api/v1/erp/members', [
            'user_id' => $member->id,
            'role' => 'manager',
            'establishment_scope' => 'selected',
            'establishment_ids' => [],
        ])->assertCreated()->assertJsonPath('data.establishment_scope', 'selected')->assertJsonPath('data.establishment_ids', []);
        $membershipId = $created->json('data.id');
        ErpStockBalance::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $location->id, 'erp_product_variant_id' => 903, 'quantity_on_hand' => '2.0000', 'average_cost' => '1.0000']);

        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/establishments')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/stock/balances')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/establishments/'.$location->id)->assertNotFound();
        $this->patchJson('/api/v1/erp/establishments/'.$location->id, ['name' => 'Hidden rename'])->assertNotFound();
        $this->postJson('/api/v1/erp/quotes', ['erp_establishment_id' => $location->id, 'quote_no' => 'empty-scope', 'total_amount' => '1'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/establishments', ['name' => 'No-scope branch', 'status' => 'active'])->assertUnprocessable();

        $this->actingAs($this->owner, 'web');
        $this->patchJson('/api/v1/erp/members/'.$membershipId, ['establishment_scope' => 'selected', 'establishment_ids' => [$location->id]])
            ->assertOk()->assertJsonPath('data.establishment_ids', [$location->id]);
        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/establishments')->assertOk()->assertJsonPath('meta.total', 1);
        $this->actingAs($this->owner, 'web');
        $this->patchJson('/api/v1/erp/members/'.$membershipId, ['establishment_scope' => 'selected', 'establishment_ids' => []])
            ->assertOk()->assertJsonPath('data.establishment_ids', []);
        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/establishments')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_foreign_resources_and_related_establishments_fail_for_all_and_selected_contexts(): void
    {
        $foreignCompany = ErpCompany::factory()->create();
        $foreignEstablishment = ErpEstablishment::factory()->create(['erp_company_id' => $foreignCompany->id]);
        $foreignAccount = ErpFinancialAccount::query()->create(['erp_company_id' => $foreignCompany->id, 'erp_establishment_id' => $foreignEstablishment->id, 'name' => 'Foreign bank']);
        $ownerMembership = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $this->owner->id)->firstOrFail();
        $allContext = new CurrentCompany($this->company, 'direct', $ownerMembership, null, null);
        try {
            app(ErpEstablishmentAccess::class)->assertResource($allContext, $foreignAccount);
            $this->fail('All-scope access must still reject a foreign-company resource.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
        $this->getJson('/api/v1/erp/financial-accounts/'.$foreignAccount->id)->assertNotFound();
        $this->postJson('/api/v1/erp/quotes', ['erp_establishment_id' => $foreignEstablishment->id, 'quote_no' => 'foreign-all', 'total_amount' => '1'])->assertUnprocessable();

        $inside = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$inside->id]);
        $memberMembership = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $member->id)->firstOrFail();
        $selectedContext = new CurrentCompany($this->company, 'direct', $memberMembership, null, null);
        try {
            app(ErpEstablishmentAccess::class)->assertResource($selectedContext, $foreignAccount);
            $this->fail('Selected access must reject a foreign-company resource.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }
        $this->actingAs($member, 'web');
        $this->postJson('/api/v1/erp/quotes', ['erp_establishment_id' => $foreignEstablishment->id, 'quote_no' => 'foreign-selected', 'total_amount' => '1'])->assertUnprocessable();
    }

    public function test_membership_establishment_ids_are_positive_safe_integers_owned_by_the_company(): void
    {
        $target = User::factory()->create();
        $foreignCompany = ErpCompany::factory()->create();
        $foreignEstablishment = ErpEstablishment::factory()->create(['erp_company_id' => $foreignCompany->id]);
        foreach ([true, false, 0, -1, 1.5, 'not-an-id', 9007199254740992, $foreignEstablishment->id] as $invalidId) {
            $this->postJson('/api/v1/erp/members', [
                'user_id' => $target->id,
                'role' => 'manager',
                'establishment_scope' => 'selected',
                'establishment_ids' => [$invalidId],
            ])->assertUnprocessable()->assertJsonValidationErrors('establishment_ids');
        }

        $location = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->postJson('/api/v1/erp/members', [
            'user_id' => $target->id,
            'role' => 'manager',
            'establishment_scope' => 'selected',
            'establishment_ids' => [(string) $location->id],
        ])->assertCreated()->assertJsonPath('data.establishment_ids', [$location->id]);
    }

    public function test_direct_all_and_delegated_all_have_distinct_nullable_finance_scope(): void
    {
        $first = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $second = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $global = ErpFinancialAccount::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => null, 'name' => 'Global delegated bank']);
        ErpFinancialAccount::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $first->id, 'name' => 'First bank']);
        ErpFinancialAccount::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $second->id, 'name' => 'Second bank']);

        $this->getJson('/api/v1/erp/financial-accounts')->assertOk()->assertJsonPath('meta.total', 3);
        $office = Account::factory()->create();
        $actor = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $actor->id, 'role' => 'admin']);
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $this->company->id,
            'account_id' => $office->id,
            'permission_allowlist' => ['erp.read', 'erp.financial-accounts.store', 'erp.obligations.store', 'erp.payments.store'],
        ]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'X-Company-Id' => (string) $this->company->id]);

        $this->getJson('/api/v1/erp/financial-accounts')->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonMissing(['id' => $global->id]);
        $this->postJson('/api/v1/erp/financial-accounts', ['name' => 'Delegated global'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/obligations', ['direction' => 'receivable', 'category' => 'manual', 'amount' => '12.00'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/payments', ['amount' => '12.00', 'method' => 'cash'])->assertUnprocessable();
    }

    public function test_scope_migration_rollback_preserves_selected_memberships_and_restricted_export_authority(): void
    {
        $location = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$location->id]);
        $membership = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $member->id)->firstOrFail();
        $export = ErpReportExport::query()->create([
            'erp_company_id' => $this->company->id,
            'user_id' => $member->id,
            'report_type' => 'overview',
            'filters' => [],
            'status' => 'ready',
            'storage_path' => 'erp/reports/scoped.csv',
            'authorized_establishment_scope' => 'selected',
            'authorized_establishment_ids' => [$location->id],
            'authorized_companywide_finance' => false,
        ]);
        $migration = require database_path('migrations/2026_10_18_152621_add_typed_establishment_scope_to_erp_memberships_and_exports.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse while selected membership authority exists.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('selected membership scopes', $error->getMessage());
        }

        $this->assertTrue(Schema::hasTable('erp_company_membership_establishments'));
        $this->assertTrue(Schema::hasColumn('erp_company_memberships', 'access_all_establishments'));
        $this->assertTrue(Schema::hasColumn('erp_report_exports', 'authorized_establishment_scope'));
        $this->assertFalse($membership->fresh()->access_all_establishments);
        $this->assertSame([$location->id], DB::table('erp_company_membership_establishments')
            ->where('erp_company_membership_id', $membership->id)->pluck('erp_establishment_id')->all());
        $this->assertSame('selected', $export->fresh()->authorized_establishment_scope);
        $this->assertSame([$location->id], $export->fresh()->authorized_establishment_ids);
        $this->assertFalse($export->fresh()->authorized_companywide_finance);

        $membership->update(['access_all_establishments' => true]);
        DB::table('erp_company_membership_establishments')->where('erp_company_membership_id', $membership->id)->delete();

        try {
            $migration->down();
            $this->fail('Rollback must refuse while restricted report export authority exists.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('scoped report export authority', $error->getMessage());
        }

        $this->assertTrue(Schema::hasTable('erp_company_membership_establishments'));
        $this->assertTrue(Schema::hasColumn('erp_report_exports', 'authorized_companywide_finance'));
        $this->assertSame('selected', $export->fresh()->authorized_establishment_scope);
        $this->assertSame([$location->id], $export->fresh()->authorized_establishment_ids);
        $this->assertFalse($export->fresh()->authorized_companywide_finance);
    }

    public function test_scope_migration_rollback_and_reapply_preserve_all_only_state(): void
    {
        $export = ErpReportExport::query()->create([
            'erp_company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'report_type' => 'overview',
            'filters' => [],
            'status' => 'ready',
            'storage_path' => 'erp/reports/all.csv',
            'authorized_establishment_scope' => 'all',
            'authorized_establishment_ids' => null,
            'authorized_companywide_finance' => true,
        ]);
        $migration = require database_path('migrations/2026_10_18_152621_add_typed_establishment_scope_to_erp_memberships_and_exports.php');

        $migration->down();

        $this->assertFalse(Schema::hasTable('erp_company_membership_establishments'));
        $this->assertFalse(Schema::hasColumn('erp_company_memberships', 'access_all_establishments'));
        $this->assertFalse(Schema::hasColumn('erp_report_exports', 'authorized_establishment_scope'));
        $this->assertDatabaseHas('erp_report_exports', ['id' => $export->id, 'storage_path' => 'erp/reports/all.csv']);

        $migration->up();

        $this->assertTrue(Schema::hasTable('erp_company_membership_establishments'));
        $this->assertTrue(Schema::hasColumn('erp_company_memberships', 'access_all_establishments'));
        $this->assertTrue(Schema::hasColumn('erp_report_exports', 'authorized_establishment_scope'));
        $this->assertTrue(ErpCompanyMembership::query()->whereKey(ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $this->owner->id)->value('id'))->value('access_all_establishments'));
        $this->assertNull($export->fresh()->authorized_establishment_scope);
    }

    public function test_selected_reports_intersect_every_category_and_exports_are_rejected_after_narrowing(): void
    {
        Storage::fake('local');
        $allowed = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $denied = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $outside = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$allowed->id, $denied->id]);
        foreach ([[$allowed, '10'], [$denied, '90']] as [$establishment, $amount]) {
            ErpSale::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $establishment->id, 'invoice_no' => 'report-sale-'.$establishment->id, 'status' => 'posted', 'subtotal_amount' => $amount, 'discount_amount' => '0', 'tax_amount' => '0', 'total_amount' => $amount, 'currency' => 'BRL', 'posted_at' => now()]);
            ErpPurchase::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $establishment->id, 'document_no' => 'report-purchase-'.$establishment->id, 'status' => 'posted', 'total_amount' => $amount, 'currency' => 'BRL', 'posted_at' => now()]);
            ErpStockBalance::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $establishment->id, 'erp_product_variant_id' => 910 + $establishment->id, 'quantity_on_hand' => $amount, 'average_cost' => '2.0000']);
            ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $establishment->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => $amount, 'open_amount' => $amount, 'currency' => 'BRL', 'status' => 'open', 'created_at' => now()]);
            ErpPayment::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $establishment->id, 'amount' => $amount, 'currency' => 'BRL', 'method' => 'cash', 'status' => 'posted', 'posted_at' => now()]);
            ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $establishment->id, 'model' => '55', 'environment' => 'homologation', 'series' => 1, 'number' => 9200 + $establishment->id, 'status' => $establishment->id === $allowed->id ? 'draft' : 'authorized', 'idempotency_key' => 'report-fiscal-'.$establishment->id]);
        }
        ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => null, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '900.0000', 'open_amount' => '900.0000', 'currency' => 'BRL', 'status' => 'open', 'created_at' => now()]);
        ErpPayment::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => null, 'amount' => '800.0000', 'currency' => 'BRL', 'method' => 'cash', 'status' => 'posted', 'posted_at' => now()]);

        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/reports/sales')->assertOk()->assertJsonPath('data.totals.document_count', 2)->assertJsonPath('data.totals.posted_total', '100.0000');
        $this->getJson('/api/v1/erp/reports/purchases')->assertOk()->assertJsonPath('data.totals.document_count', 2)->assertJsonPath('data.totals.posted_total', '100.0000');
        $this->getJson('/api/v1/erp/reports/stock')->assertOk()->assertJsonPath('data.totals.balance_lines', 2)->assertJsonPath('data.totals.quantity_on_hand', '100.0000');
        $this->getJson('/api/v1/erp/reports/financial')->assertOk()->assertJsonPath('data.totals.obligations_amount', '100.0000')->assertJsonPath('data.totals.payments_posted_total', '100.0000');
        $this->getJson('/api/v1/erp/reports/fiscal')->assertOk()->assertJsonPath('data.totals.draft_count', 1)->assertJsonPath('data.totals.authorized_count', 1);
        $this->getJson('/api/v1/erp/reports/overview')->assertOk()->assertJsonPath('data.sections.financial.obligations_amount', '100.0000');
        $this->getJson('/api/v1/erp/reports/sales?erp_establishment_id='.$outside->id)->assertUnprocessable();

        $export = $this->postJson('/api/v1/erp/reports/overview/exports', [])->assertCreated()->json('data');
        $exportRow = ErpReportExport::query()->findOrFail($export['id']);
        $this->assertSame('selected', $exportRow->authorized_establishment_scope);
        $this->assertSame([$allowed->id, $denied->id], $exportRow->authorized_establishment_ids);
        $this->assertFalse($exportRow->authorized_companywide_finance);
        $this->getJson('/api/v1/erp/reports/exports/'.$export['id'])->assertOk();

        $this->actingAs($this->owner, 'web');
        $membershipId = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $member->id)->value('id');
        $this->patchJson('/api/v1/erp/members/'.$membershipId, ['establishment_scope' => 'selected', 'establishment_ids' => [$allowed->id]])->assertOk();
        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/reports/exports/'.$export['id'])->assertStatus(409)->assertJsonPath('code', 'export_requires_regeneration');
        Storage::disk('local')->assertExists($exportRow->storage_path);
    }

    public function test_scope_edits_roll_back_membership_and_pivot_when_a_pivot_write_fails(): void
    {
        $first = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $second = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = User::factory()->create();
        $membershipId = $this->postJson('/api/v1/erp/members', [
            'user_id' => $member->id,
            'role' => 'manager',
            'establishment_scope' => 'all',
            'establishment_ids' => [],
        ])->assertCreated()->json('data.id');
        $injected = false;

        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if (! $injected
                && str_starts_with(strtolower(ltrim($query->sql)), 'insert')
                && str_contains($query->sql, 'erp_company_membership_establishments')) {
                $injected = true;
                throw ValidationException::withMessages(['establishment_ids' => 'Injected pivot failure']);
            }
        });

        $this->patchJson('/api/v1/erp/members/'.$membershipId, [
            'establishment_scope' => 'selected',
            'establishment_ids' => [$first->id, $second->id],
        ])->assertUnprocessable();

        $membership = ErpCompanyMembership::query()->findOrFail($membershipId);
        $this->assertTrue($injected);
        $this->assertTrue($membership->hasAllEstablishmentAccess());
        $this->assertSame(0, $membership->establishments()->count());
    }

    public function test_selected_scope_enforces_return_sources_and_cash_register_parents(): void
    {
        $allowed = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $hidden = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$allowed->id]);
        $purchase = ErpPurchase::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'document_no' => 'hidden-purchase-source',
            'status' => 'posted',
            'total_amount' => '20.0000',
        ]);
        $sale = ErpSale::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'invoice_no' => 'hidden-sale-source',
            'status' => 'posted',
            'total_amount' => '20.0000',
        ]);
        $purchaseReturn = ErpPurchaseReturn::query()->create([
            'erp_purchase_id' => $purchase->id,
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'status' => 'draft',
            'total_amount' => '20.0000',
        ]);
        $saleReturn = ErpSaleReturn::query()->create([
            'erp_sale_id' => $sale->id,
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'status' => 'draft',
            'total_amount' => '20.0000',
        ]);
        $register = ErpCashRegister::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'name' => 'Hidden cash register',
        ]);
        $session = ErpCashSession::query()->create([
            'erp_cash_register_id' => $register->id,
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'operator_user_id' => $member->id,
            'opening_amount' => '10.0000',
            'status' => 'open',
            'opened_by_user_id' => $member->id,
            'opened_at' => now(),
        ]);

        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/purchase-returns')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/purchase-returns/'.$purchaseReturn->id)->assertNotFound();
        $this->getJson('/api/v1/erp/sale-returns')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/sale-returns/'.$saleReturn->id)->assertNotFound();
        $this->postJson('/api/v1/erp/purchase-returns', ['erp_purchase_id' => $purchase->id, 'total_amount' => '20'])->assertUnprocessable();
        $this->postJson('/api/v1/erp/sale-returns', ['erp_sale_id' => $sale->id, 'total_amount' => '20'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_purchase_returns', 1);
        $this->assertDatabaseCount('erp_sale_returns', 1);

        $this->getJson('/api/v1/erp/cash-registers')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/cash-registers/'.$register->id)->assertNotFound();
        $this->getJson('/api/v1/erp/cash-sessions')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/cash-sessions/'.$session->id)->assertNotFound();
        $this->postJson('/api/v1/erp/cash-sessions', ['erp_cash_register_id' => $register->id])->assertUnprocessable();
        $this->postJson('/api/v1/erp/cash-sessions/'.$session->id.'/close', ['declared_closing_amount' => '10'])->assertNotFound();
        $this->assertSame('open', $session->fresh()->status);
    }

    public function test_selected_scope_enforces_fiscal_sources_before_storage_and_accounting_parent_reads(): void
    {
        Storage::fake('local');
        $allowed = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $hidden = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$allowed->id]);
        $artifactPath = 'erp/fiscal/hidden-document.xml';
        $xml = '<secret>hidden fiscal bytes</secret>';
        Storage::disk('local')->put($artifactPath, $xml);
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 9301,
            'status' => 'authorized',
            'idempotency_key' => 'hidden-fiscal-document',
            'storage_path' => $artifactPath,
            'artifact_byte_length' => strlen($xml),
            'artifact_sha256' => hash('sha256', $xml),
        ]);
        $event = ErpFiscalEvent::query()->create([
            'erp_fiscal_document_id' => $document->id,
            'event_type' => 'cancel',
            'status' => 'queued',
            'idempotency_key' => 'hidden-fiscal-event',
        ]);
        $invalidation = ErpFiscalInvalidation::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'model' => '55',
            'series' => 1,
            'number_from' => 9302,
            'number_to' => 9302,
            'status' => 'queued',
            'idempotency_key' => 'hidden-invalidation',
            'justification' => 'Hidden location numbering',
        ]);
        $certificate = ErpFiscalCertificate::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $hidden->id,
            'label' => 'Hidden certificate',
            'encrypted_certificate' => 'encrypted certificate',
            'encrypted_password' => 'encrypted password',
            'status' => 'active',
        ]);
        $office = Account::factory()->create();
        $delivery = ErpAccountingDelivery::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_fiscal_document_id' => $document->id,
            'account_id' => $office->id,
            'status' => 'blocked',
            'reason_code' => 'fiscal_document_not_authorized',
        ]);

        $this->actingAs($member, 'web');
        $this->getJson('/api/v1/erp/fiscal/documents')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/fiscal/documents/'.$document->id)->assertNotFound();
        $this->getJson('/api/v1/erp/fiscal/documents/'.$document->id.'/xml')->assertNotFound()->assertDontSee($xml);
        $this->getJson('/api/v1/erp/fiscal/documents/'.$document->id.'/pdf')->assertNotFound();
        config(['erp.fiscal_canary_operator_token' => 'scope-test-token']);
        $this->postJson('/api/v1/erp/fiscal/operator-canary/documents/'.$document->id.'/transmit', [], ['X-Erp-Fiscal-Canary-Token' => 'scope-test-token'])->assertNotFound();
        $this->getJson('/api/v1/erp/fiscal/events')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/fiscal/events/'.$event->id)->assertNotFound();
        $this->postJson('/api/v1/erp/fiscal/events/'.$event->id.'/submit')->assertNotFound();
        $this->getJson('/api/v1/erp/fiscal/invalidations')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/fiscal/invalidations/'.$invalidation->id)->assertNotFound();
        $this->getJson('/api/v1/erp/fiscal/certificates')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/fiscal/certificates/'.$certificate->id)->assertNotFound();
        $this->postJson('/api/v1/erp/fiscal/certificates/'.$certificate->id.'/rotate', [])->assertNotFound();
        $this->getJson('/api/v1/erp/accounting-deliveries')->assertOk()->assertJsonPath('meta.total', 0);
        $this->postJson('/api/v1/erp/accounting-deliveries/'.$delivery->id.'/retry')->assertNotFound();

        $this->postJson('/api/v1/erp/fiscal/documents', [
            'erp_establishment_id' => $hidden->id,
            'model' => '55',
            'number' => 9310,
            'idempotency_key' => 'new-hidden-document',
        ])->assertUnprocessable()->assertJsonValidationErrors('erp_establishment_id');
        $this->postJson('/api/v1/erp/fiscal/documents', [
            'erp_establishment_id' => $allowed->id,
            'model' => '55',
            'number' => 9301,
            'idempotency_key' => 'hidden-fiscal-document',
        ])->assertNotFound();
        $this->postJson('/api/v1/erp/fiscal/events', [
            'erp_fiscal_document_id' => $document->id,
            'event_type' => 'cancel',
            'idempotency_key' => 'new-hidden-event',
        ])->assertUnprocessable()->assertJsonValidationErrors('erp_fiscal_document_id');
        $this->postJson('/api/v1/erp/fiscal/invalidations', [
            'erp_establishment_id' => $hidden->id,
            'model' => '55',
            'series' => 1,
            'number_from' => 9311,
            'number_to' => 9311,
            'justification' => 'Hidden location numbering',
            'idempotency_key' => 'new-hidden-invalidation',
        ])->assertUnprocessable()->assertJsonValidationErrors('erp_establishment_id');
        $certificateData = ['label' => 'New certificate', 'certificate_content' => base64_encode('not a valid certificate'), 'password' => 'secret'];
        $this->postJson('/api/v1/erp/fiscal/certificates', $certificateData + ['erp_establishment_id' => $hidden->id])
            ->assertUnprocessable()->assertJsonValidationErrors('erp_establishment_id');
        $this->postJson('/api/v1/erp/fiscal/certificates', $certificateData)
            ->assertUnprocessable()->assertJsonValidationErrors('erp_establishment_id');
        $this->postJson('/api/v1/erp/purchases/xml-preview', ['erp_establishment_id' => $hidden->id, 'xml' => '<invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('erp_establishment_id');
    }

    public function test_allocation_receipt_replay_and_lookup_stop_after_membership_scope_narrows(): void
    {
        $location = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $member = $this->selectedMember([$location->id]);
        $payment = ErpPayment::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $location->id,
            'amount' => '100.0000',
            'method' => 'cash',
            'status' => 'posted',
        ]);
        $obligation = ErpObligation::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $location->id,
            'direction' => 'receivable',
            'category' => 'manual',
            'amount' => '100.0000',
            'open_amount' => '100.0000',
        ]);
        $path = '/api/v1/erp/payments/'.$payment->id.'/allocate';
        $body = ['erp_obligation_id' => $obligation->id, 'amount' => '40.0000'];
        $this->actingAs($member, 'web');
        $this->postJson($path, $body, ['Idempotency-Key' => 'narrow-after-allocation'])->assertOk();
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);

        $membershipId = ErpCompanyMembership::query()->where('erp_company_id', $this->company->id)->where('user_id', $member->id)->value('id');
        $this->actingAs($this->owner, 'web');
        $this->patchJson('/api/v1/erp/members/'.$membershipId, ['establishment_scope' => 'selected', 'establishment_ids' => []])
            ->assertOk()->assertJsonPath('data.establishment_ids', []);

        $this->actingAs($member, 'web');
        $this->postJson($path, $body, ['Idempotency-Key' => 'narrow-after-allocation'])->assertNotFound();
        $this->getJson('/api/v1/erp/operations/lookup?operation=erp.payments.allocate', ['Idempotency-Key' => 'narrow-after-allocation'])->assertNotFound();
        $this->assertDatabaseCount('erp_payment_allocations', 1);
        $this->assertDatabaseCount('erp_operation_receipts', 1);
        $this->assertSame('60.0000', $obligation->fresh()->open_amount);
    }

    public function test_direct_all_export_and_finance_reports_narrow_when_user_switches_to_delegated_access(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create();
        $membershipId = $this->postJson('/api/v1/erp/members', [
            'user_id' => $actor->id,
            'role' => 'manager',
            'establishment_scope' => 'all',
            'establishment_ids' => [],
        ])->assertCreated()->json('data.id');
        $office = Account::factory()->create();
        AccountMembership::factory()->create(['account_id' => $office->id, 'user_id' => $actor->id, 'role' => 'admin']);
        ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $this->company->id,
            'account_id' => $office->id,
            'permission_allowlist' => ['erp.read'],
        ]);
        $location = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        ErpObligation::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => null,
            'direction' => 'receivable',
            'category' => 'manual',
            'amount' => '900.0000',
            'open_amount' => '900.0000',
            'created_at' => now(),
        ]);
        ErpObligation::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $location->id,
            'direction' => 'receivable',
            'category' => 'manual',
            'amount' => '10.0000',
            'open_amount' => '10.0000',
            'created_at' => now(),
        ]);
        ErpPayment::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => null,
            'amount' => '800.0000',
            'method' => 'cash',
            'status' => 'posted',
            'posted_at' => now(),
        ]);
        ErpPayment::query()->create([
            'erp_company_id' => $this->company->id,
            'erp_establishment_id' => $location->id,
            'amount' => '5.0000',
            'method' => 'cash',
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])
            ->assertOk()->assertJsonPath('current_company.access_kind', 'direct');
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'X-Company-Id' => (string) $this->company->id]);

        $this->getJson('/api/v1/erp/reports/financial')->assertOk()
            ->assertJsonPath('data.totals.obligations_amount', '910.0000')
            ->assertJsonPath('data.totals.payments_posted_total', '805.0000');
        $this->getJson('/api/v1/erp/reports/overview')->assertOk()
            ->assertJsonPath('data.sections.financial.obligations_amount', '910.0000');
        $export = $this->postJson('/api/v1/erp/reports/overview/exports', [])->assertCreated()->json('data');
        $exportRow = ErpReportExport::query()->findOrFail($export['id']);
        $this->assertTrue($exportRow->authorized_companywide_finance);
        $locationExport = $this->postJson('/api/v1/erp/reports/financial/exports', ['erp_establishment_id' => $location->id])
            ->assertCreated()->json('data');
        $locationExportRow = ErpReportExport::query()->findOrFail($locationExport['id']);
        $this->assertFalse($locationExportRow->authorized_companywide_finance);
        $this->assertSame([$location->id], $locationExportRow->authorized_establishment_ids);

        $this->actingAs($this->owner, 'web');
        $this->deleteJson('/api/v1/erp/members/'.$membershipId)->assertNoContent();

        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $actor->email, 'password' => 'password'])->assertOk();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/erp/company/switch', ['company_id' => $this->company->id])
            ->assertOk()->assertJsonPath('current_company.access_kind', 'delegated');
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeaders(['X-CSRF-Token' => $csrf, 'X-Company-Id' => (string) $this->company->id]);

        $this->getJson('/api/v1/erp/reports/financial')->assertOk()
            ->assertJsonPath('data.totals.obligations_amount', '10.0000')
            ->assertJsonPath('data.totals.payments_posted_total', '5.0000');
        $this->getJson('/api/v1/erp/reports/overview')->assertOk()
            ->assertJsonPath('data.sections.financial.obligations_amount', '10.0000');
        $this->getJson('/api/v1/erp/reports/exports/'.$export['id'])->assertStatus(409)
            ->assertJsonPath('code', 'export_requires_regeneration');
        $this->getJson('/api/v1/erp/reports/exports/'.$locationExport['id'])->assertOk()
            ->assertJsonPath('data.report.totals.obligations_amount', '10.0000');
        Storage::disk('local')->assertExists($exportRow->storage_path);
        Storage::disk('local')->assertExists($locationExportRow->storage_path);
    }
}
