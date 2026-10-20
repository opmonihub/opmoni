<?php

namespace Tests\Feature;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\ErpFiscalProviderResult;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpPlanModule;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Fiscal\ErpFiscalEvent;
use App\Models\Erp\Fiscal\ErpFiscalInvalidation;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ErpReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private ?ErpContact $purchaseSupplier = null;

    private function manualPurchaseSupplier(): ErpContact
    {
        return $this->purchaseSupplier ??= ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'is_supplier' => true]);
    }

    private ErpCompany $company;

    private ErpEstablishment $establishment;

    private ErpProductVariant $variant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['access.ingress_key' => 'test-key', 'erp.operating_writes_enabled' => true, 'erp.release_mode' => 'production', 'erp.release_enabled' => true, 'cache.default' => 'array', 'session.driver' => 'array', 'session.store' => null]);
        $this->withHeaders(['X-Opmoni-Ingress-Key' => 'test-key', 'X-Opmoni-Client-IP' => '127.0.0.1', 'X-Opmoni-Client-Proto' => 'http', 'Origin' => 'http://localhost:3000']);
        $this->user = User::factory()->create();
        $this->company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => 'owner']);
        ErpSubscription::factory()->create(['erp_company_id' => $this->company->id, 'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id, 'status' => 'active']);
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $this->company->id, 'erp_unit_id' => $unit->id]);
        $this->variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', ['email' => $this->user->email, 'password' => 'password'])->assertOk();
        $this->withHeaders(['X-Company-Id' => (string) $this->company->id, 'X-CSRF-Token' => $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token')]);
    }

    private function purchase(array $line = []): int
    {
        return $this->commercialPost('/api/v1/erp/purchases', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $this->manualPurchaseSupplier()->id, 'lines' => [[...['erp_product_variant_id' => $this->variant->id, 'quantity' => '10', 'unit_cost' => '5'], ...$line]]])->assertCreated()->json('data.id');
    }

    private function postedSale(): int
    {
        $purchase = $this->purchase();
        $this->commercialPost("/api/v1/erp/purchases/{$purchase}/post")->assertOk();
        $sale = $this->commercialPost('/api/v1/erp/sales', ['erp_establishment_id' => $this->establishment->id, 'invoice_no' => 'S-1', 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '2', 'unit_price' => '10']]])->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/sales/{$sale}/post")->assertOk();

        return $sale;
    }

    private function obligation(): ErpObligation
    {
        return ErpObligation::query()->create(['erp_company_id' => $this->company->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '100', 'open_amount' => '100', 'status' => 'open']);
    }

    private function payment(string $amount): int
    {
        return $this->commercialPost('/api/v1/erp/payments', ['amount' => $amount, 'method' => 'manual', 'direction' => 'in', 'source_operation_key' => 'payment-'.bin2hex(random_bytes(6))])->assertCreated()->json('data.id');
    }

    public function test_read_only_action_permissions_block_operator_write(): void
    {
        ErpCompanyMembership::query()->where('user_id', $this->user->id)->update(['role' => 'operator', 'action_permissions' => ['erp.read']]);
        $this->commercialPost('/api/v1/erp/payments', ['amount' => '1', 'method' => 'cash'])->assertForbidden();
        $this->assertDatabaseCount('erp_payments', 0);
    }

    public function test_foreign_variant_cannot_enter_purchase_or_adjustment(): void
    {
        $foreign = ErpProductVariant::factory()->create();
        $this->commercialPost('/api/v1/erp/purchases', ['erp_establishment_id' => $this->establishment->id, 'erp_contact_id' => $this->manualPurchaseSupplier()->id, 'lines' => [['erp_product_variant_id' => $foreign->id, 'quantity' => '1', 'unit_cost' => '5']]])->assertUnprocessable();
        $this->commercialPost('/api/v1/erp/stock/adjustments', ['erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $foreign->id, 'quantity_delta' => '1', 'reason' => 'Count'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_stock_balances', 0);
    }

    public function test_allocation_cannot_exceed_payment_balance(): void
    {
        $obligation = $this->obligation();
        $payment = $this->payment('40');
        $this->commercialPost("/api/v1/erp/payments/{$payment}/allocate", ['erp_obligation_id' => $obligation->id, 'amount' => '80'])->assertUnprocessable();
        $this->assertSame('100.0000', $obligation->fresh()->open_amount);
    }

    public function test_partial_payments_settle_remaining_amount_once_and_replay_is_idempotent(): void
    {
        $obligation = $this->obligation();
        $first = $this->payment('40');
        $payload = ['erp_obligation_id' => $obligation->id, 'amount' => '40', 'source_operation_key' => 'first'];
        $a = $this->commercialPost("/api/v1/erp/payments/{$first}/allocate", $payload)->assertOk()->json('data.id');
        $this->commercialPost("/api/v1/erp/payments/{$first}/allocate", $payload)->assertOk()->assertJsonPath('data.id', $a);
        $second = $this->payment('60');
        $this->commercialPost("/api/v1/erp/payments/{$second}/allocate", ['erp_obligation_id' => $obligation->id, 'amount' => '60', 'source_operation_key' => 'second'])->assertOk()->assertJsonPath('data.obligation_open_amount', '0.0000')->assertJsonPath('data.obligation_status', 'settled');
        $this->assertDatabaseCount('erp_payment_allocations', 2);
    }

    public function test_negative_sale_quantity_is_rejected_before_stock_effect(): void
    {
        $this->commercialPost('/api/v1/erp/sales', ['erp_establishment_id' => $this->establishment->id, 'invoice_no' => 'NEG', 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => '-2', 'unit_price' => '10']]])->assertUnprocessable();
        $this->assertDatabaseCount('erp_sales', 0);
    }

    public function test_invalid_decimal_is_rejected_instead_of_creating_zero_payment(): void
    {
        $this->commercialPost('/api/v1/erp/payments', ['amount' => 'invalid', 'method' => 'cash'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_payments', 0);
    }

    public function test_purchase_post_creates_payable_in_same_transaction(): void
    {
        $purchase = $this->purchase();
        $this->commercialPost("/api/v1/erp/purchases/{$purchase}/post")->assertOk();
        $this->assertDatabaseHas('erp_obligations', ['source_type' => 'erp_purchase', 'source_id' => $purchase, 'direction' => 'payable', 'amount' => '50']);
    }

    public function test_purchase_return_compensates_stock_and_payable(): void
    {
        $purchase = $this->purchase();
        $this->commercialPost("/api/v1/erp/purchases/{$purchase}/post")->assertOk();
        $return = $this->commercialPost('/api/v1/erp/purchase-returns', $this->fullReturnBody('purchase', $purchase, '50'))->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/purchase-returns/{$return}/post")->assertOk();
        $this->assertSame('0.0000', ErpStockBalance::query()->firstOrFail()->quantity_on_hand);
        $this->assertDatabaseHas('erp_obligations', ['source_type' => 'erp_purchase', 'source_id' => $purchase, 'open_amount' => '50']);
    }

    public function test_sale_return_cannot_restock_same_sale_twice_and_compensates_receivable(): void
    {
        $sale = $this->postedSale();
        $return = $this->commercialPost('/api/v1/erp/sale-returns', $this->fullReturnBody('sale', $sale, '20'))->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/sale-returns/{$return}/post")->assertOk();
        $this->assertDatabaseHas('erp_obligations', ['source_type' => 'erp_sale', 'source_id' => $sale, 'open_amount' => '20']);
        $second = $this->commercialPost('/api/v1/erp/sale-returns', $this->fullReturnBody('sale', $sale, '20'));
        if ($second->status() === 201) {
            $this->commercialPost('/api/v1/erp/sale-returns/'.$second->json('data.id').'/post')->assertUnprocessable();
        } else {
            $second->assertUnprocessable();
        }
        $this->assertSame('10.0000', ErpStockBalance::query()->firstOrFail()->quantity_on_hand);
    }

    public function test_total_only_return_is_rejected_without_source_lines(): void
    {
        $sale = $this->postedSale();
        $this->commercialPost('/api/v1/erp/sale-returns', ['erp_sale_id' => $sale, 'total_amount' => '1'])->assertUnprocessable();
        $this->assertSame('8.0000', ErpStockBalance::query()->firstOrFail()->quantity_on_hand);
    }

    public function test_cash_close_uses_recorded_movements_instead_of_client_aggregate(): void
    {
        $register = ErpCashRegister::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'name' => 'Cash']);
        $session = $this->commercialPost('/api/v1/erp/cash-sessions', ['erp_cash_register_id' => $register->id, 'opening_amount' => '100', 'currency' => 'BRL', 'source_operation_key' => 'open'])->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/cash-sessions/{$session}/close", ['declared_closing_amount' => '0', 'movements_net' => '-100', 'source_operation_key' => 'close'])->assertUnprocessable();
        $this->commercialPost("/api/v1/erp/cash-sessions/{$session}/close", ['declared_closing_amount' => '0', 'source_operation_key' => 'close'])->assertOk()->assertJsonPath('data.expected_closing_amount', '100.0000')->assertJsonPath('data.closing_difference', '-100.0000');
    }

    public function test_disabled_purchasing_module_blocks_purchase(): void
    {
        ErpPlanModule::query()->where('module_key', 'purchases')->delete();
        $this->commercialPost('/api/v1/erp/purchases', ['erp_establishment_id' => $this->establishment->id, 'lines' => [['quantity' => '1', 'unit_cost' => '5']]])->assertForbidden();
    }

    public function test_preview_requires_known_company_and_user(): void
    {
        config(['erp.release_mode' => 'preview', 'erp.preview_allowlist' => [['company_id' => 999, 'user_id' => 999]]]);
        $this->getJson('/api/v1/erp/reports/overview')->assertForbidden();
    }

    public function test_fiscal_document_copies_posted_sale_snapshot(): void
    {
        $sale = $this->postedSale();
        $id = $this->commercialPost('/api/v1/erp/fiscal/documents', ['erp_establishment_id' => $this->establishment->id, 'erp_sale_id' => $sale, 'model' => '55', 'number' => 1, 'idempotency_key' => 'snapshot'])->assertCreated()->json('data.id');
        $this->assertSame('20.0000', ErpFiscalDocument::query()->findOrFail($id)->commercial_snapshot['total_amount'] ?? null);
    }

    public function test_production_invalidation_cannot_dispatch_when_gate_disabled(): void
    {
        $provider = $this->mock(ErpFiscalProvider::class);
        $provider->shouldReceive('submitInvalidation')->never()->andReturn(ErpFiscalProviderResult::eventRejected('Blocked'));
        config(['erp.fiscal_production_enabled' => false, 'erp.fiscal_canary_scope' => []]);
        $id = $this->commercialPost('/api/v1/erp/fiscal/invalidations', ['erp_establishment_id' => $this->establishment->id, 'model' => '55', 'environment' => 'production', 'series' => 1, 'number_from' => 2, 'number_to' => 2, 'justification' => 'Unused document number for correction', 'idempotency_key' => 'production-invalidation'])->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/fiscal/invalidations/{$id}/submit")->assertForbidden();
    }

    public function test_purchase_unit_conversion_changes_stock_and_unit_cost(): void
    {
        $purchase = $this->purchase(['quantity' => '2', 'unit_cost' => '120', 'unit_conversion_factor' => '12']);
        $this->commercialPost("/api/v1/erp/purchases/{$purchase}/post")->assertOk();
        $balance = ErpStockBalance::query()->firstOrFail();
        $this->assertSame('24.0000', $balance->quantity_on_hand);
        $this->assertSame('10.0000', $balance->average_cost);
    }

    public function test_lifo_company_posts_real_receipt_layers(): void
    {
        $this->company->update(['operating_settings' => ['accounting_method' => 'lifo']]);
        $purchase = $this->purchase();
        $this->commercialPost("/api/v1/erp/purchases/{$purchase}/post")->assertOk();
        $this->assertDatabaseCount('erp_stock_lots', 1);
    }

    public function test_fiscal_report_counts_drafts_created_in_period(): void
    {
        ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '55', 'number' => 3, 'idempotency_key' => 'draft-report', 'status' => 'draft']);
        $this->getJson('/api/v1/erp/reports/fiscal')->assertOk()->assertJsonPath('data.totals.draft_count', 1);
    }

    public function test_manual_operation_keys_are_scoped_to_company(): void
    {
        $this->commercialPost('/api/v1/erp/payments', ['amount' => '5', 'method' => 'manual', 'direction' => 'in', 'source_operation_key' => 'shared-key'])->assertCreated();
        $other = ErpCompany::factory()->create();
        $payment = ErpPayment::query()->create(['erp_company_id' => $other->id, 'amount' => '10', 'currency' => 'BRL', 'method' => 'cash', 'source_type' => 'manual', 'source_id' => 0, 'source_operation_key' => 'shared-key']);
        $this->assertSame($other->id, $payment->erp_company_id);
    }

    public function test_pdf_is_unavailable_until_a_real_renderer_exists(): void
    {
        $document = ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '55', 'number' => 9, 'idempotency_key' => 'pdf', 'status' => 'authorized']);
        $this->getJson('/api/v1/erp/fiscal/documents/'.$document->id.'/pdf')->assertStatus(503);
    }

    public function test_default_provider_cannot_fabricate_authorization(): void
    {
        $document = new ErpFiscalDocument(['model' => '55', 'environment' => 'homologation', 'idempotency_key' => 'runtime']);
        try {
            app(ErpFiscalProvider::class)->transmit($document, []);
            $this->fail('Missing fiscal adapter must block dispatch.');
        } catch (HttpException $exception) {
            $this->assertSame(503, $exception->getStatusCode());
        }
    }

    public function test_stock_lists_include_adjustments_and_transfers(): void
    {
        $this->getJson('/api/v1/erp/stock/adjustments')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/erp/stock/transfers')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_mixed_receipt_costs_preserve_separate_layers(): void
    {
        $first = $this->purchase();
        $this->commercialPost("/api/v1/erp/purchases/{$first}/post")->assertOk();
        $second = $this->purchase(['unit_cost' => '10']);
        $this->commercialPost("/api/v1/erp/purchases/{$second}/post")->assertOk();
        $this->assertSame('20.0000', ErpStockBalance::query()->firstOrFail()->quantity_on_hand);
        $this->assertSame('7.5000', ErpStockBalance::query()->firstOrFail()->average_cost);
        $this->assertDatabaseCount('erp_stock_lots', 2);
        $this->assertDatabaseCount('erp_obligations', 2);
    }

    public function test_failed_purchase_finance_rolls_back_stock_and_posted_state(): void
    {
        $purchase = $this->purchase();
        ErpObligation::creating(function ($obligation): void {
            if ($obligation->source_type === 'erp_purchase') {
                throw new \RuntimeException('Injected finance failure');
            }
        });
        $this->commercialPost("/api/v1/erp/purchases/{$purchase}/post")->assertStatus(503);
        $this->assertDatabaseHas('erp_purchases', ['id' => $purchase, 'status' => 'draft']);
        $this->assertDatabaseCount('erp_stock_balances', 0);
        $this->assertDatabaseCount('erp_stock_movements', 0);
        ErpObligation::flushEventListeners();
    }

    public function test_paid_sale_return_creates_credit_without_reversing_original_payment(): void
    {
        $sale = $this->postedSale();
        $obligation = ErpObligation::query()->where('source_type', 'erp_sale')->firstOrFail();
        $payment = $this->payment('20');
        $this->commercialPost("/api/v1/erp/payments/{$payment}/allocate", ['erp_obligation_id' => $obligation->id, 'amount' => '20', 'source_operation_key' => 'paid'])->assertOk();
        $return = $this->commercialPost('/api/v1/erp/sale-returns', $this->fullReturnBody('sale', $sale, '20'))->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/sale-returns/{$return}/post")->assertOk();
        $this->assertSame('10.0000', ErpStockBalance::query()->firstOrFail()->quantity_on_hand);
        $this->assertDatabaseHas('erp_sale_returns', ['id' => $return, 'status' => 'posted']);
        $this->assertDatabaseHas('erp_obligations', ['return_of_id' => $obligation->id, 'direction' => 'payable', 'amount' => '20']);
        $this->assertSame('settled', $obligation->fresh()->status);
    }

    public function test_allocation_key_conflicts_when_amount_changes(): void
    {
        $obligation = $this->obligation();
        $payment = $this->payment('100');
        $payload = ['erp_obligation_id' => $obligation->id, 'amount' => '10', 'source_operation_key' => 'allocation'];
        $this->commercialPost("/api/v1/erp/payments/{$payment}/allocate", $payload)->assertOk();
        $this->commercialPost("/api/v1/erp/payments/{$payment}/allocate", [...$payload, 'amount' => '20'])->assertConflict();
        $this->assertSame('90.0000', $obligation->fresh()->open_amount);
    }

    public function test_unequal_payment_key_reuse_conflicts(): void
    {
        $payload = ['amount' => '10', 'method' => 'manual', 'direction' => 'in', 'source_operation_key' => 'payment'];
        $this->commercialPost('/api/v1/erp/payments', $payload)->assertCreated();
        $this->commercialPost('/api/v1/erp/payments', [...$payload, 'amount' => '20'])->assertConflict();
        $this->assertDatabaseCount('erp_payments', 1);
    }

    public function test_non_scalar_payment_amount_returns_field_error(): void
    {
        $this->commercialPost('/api/v1/erp/payments', ['amount' => ['20'], 'method' => 'cash'])->assertUnprocessable();
        $this->assertDatabaseCount('erp_payments', 0);
    }

    public function test_production_event_cannot_dispatch_with_disabled_gate(): void
    {
        $provider = $this->mock(ErpFiscalProvider::class);
        $provider->shouldReceive('submitEvent')->never()->andReturn(ErpFiscalProviderResult::eventRejected('Blocked'));
        config(['erp.fiscal_production_enabled' => false]);
        $document = ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '55', 'environment' => 'production', 'number' => 100, 'idempotency_key' => 'event-production', 'status' => 'authorized']);
        $event = $this->commercialPost('/api/v1/erp/fiscal/events', ['erp_fiscal_document_id' => $document->id, 'event_type' => 'cancel', 'idempotency_key' => 'event-cancel', 'justification' => 'Cancel production document'])->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/fiscal/events/{$event}/submit")->assertForbidden();
        $this->assertSame('authorized', $document->fresh()->status);
    }

    public function test_adjustment_replay_reuses_operation_without_second_stock_effect(): void
    {
        $purchase = $this->purchase();
        $this->commercialPost("/api/v1/erp/purchases/{$purchase}/post")->assertOk();
        $payload = ['erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_delta' => '-1', 'reason' => 'Count', 'source_operation_key' => 'count-operation'];
        $id = $this->commercialPost('/api/v1/erp/stock/adjustments', $payload)->assertCreated()->json('data.id');
        $this->commercialPost('/api/v1/erp/stock/adjustments', $payload)->assertStatus(201)->assertJsonPath('data.id', $id);
        $this->assertSame('9.0000', ErpStockBalance::query()->firstOrFail()->quantity_on_hand);
    }

    public function test_disabled_transport_fiscal_modules_block_create_and_existing_actions(): void
    {
        foreach (['57' => 'cte', '58' => 'mdfe'] as $model => $module) {
            ErpPlanModule::query()->where('module_key', $module)->delete();
            $document = ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => (string) $model, 'number' => (int) $model, 'idempotency_key' => 'transport-'.$model, 'status' => 'authorized']);
            $this->commercialPost('/api/v1/erp/fiscal/documents', ['erp_establishment_id' => $this->establishment->id, 'model' => (string) $model, 'number' => 500, 'idempotency_key' => 'disabled-'.$model])->assertForbidden();
            $this->getJson('/api/v1/erp/fiscal/documents/'.$document->id.'/xml')->assertForbidden();
            $this->commercialPost('/api/v1/erp/fiscal/events', ['erp_fiscal_document_id' => $document->id, 'event_type' => 'cancel', 'idempotency_key' => 'event-'.$model])->assertForbidden();
            $event = ErpFiscalEvent::query()->create(['erp_fiscal_document_id' => $document->id, 'event_type' => 'cancel', 'idempotency_key' => 'existing-event-'.$model, 'status' => 'queued']);
            $this->commercialPost('/api/v1/erp/fiscal/events/'.$event->id.'/submit')->assertForbidden();
            $this->commercialPost('/api/v1/erp/fiscal/invalidations', ['erp_establishment_id' => $this->establishment->id, 'model' => (string) $model, 'series' => 1, 'number_from' => 501, 'number_to' => 501, 'justification' => 'Unused transport fiscal numbering', 'idempotency_key' => 'invalidation-'.$model])->assertForbidden();
        }
    }

    public function test_numeric_string_identifiers_replay_as_the_same_payment_adjustment_and_transfer(): void
    {
        $payment = ['amount' => '10', 'method' => 'manual', 'direction' => 'in', 'erp_establishment_id' => (string) $this->establishment->id, 'source_operation_key' => 'string-payment'];
        $id = $this->commercialPost('/api/v1/erp/payments', $payment)->assertCreated()->json('data.id');
        $this->commercialPost('/api/v1/erp/payments', $payment)->assertCreated()->assertJsonPath('data.id', $id);
        $purchase = $this->purchase();
        $this->commercialPost("/api/v1/erp/purchases/{$purchase}/post")->assertOk();
        $adjustment = ['erp_establishment_id' => (string) $this->establishment->id, 'erp_product_variant_id' => (string) $this->variant->id, 'quantity_delta' => '-1', 'reason' => 'Count', 'source_operation_key' => 'string-adjustment'];
        $id = $this->commercialPost('/api/v1/erp/stock/adjustments', $adjustment)->assertCreated()->json('data.id');
        $this->commercialPost('/api/v1/erp/stock/adjustments', $adjustment)->assertStatus(201)->assertJsonPath('data.id', $id);
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $transfer = ['from_establishment_id' => (string) $this->establishment->id, 'to_establishment_id' => (string) $destination->id, 'erp_product_variant_id' => (string) $this->variant->id, 'quantity' => '1', 'source_operation_key' => 'string-transfer'];
        $id = $this->commercialPost('/api/v1/erp/stock/transfers', $transfer)->assertCreated()->json('data.id');
        $this->commercialPost('/api/v1/erp/stock/transfers', $transfer)->assertStatus(201)->assertJsonPath('data.id', $id);
    }

    public function test_purchase_return_cannot_remove_new_stock_with_a_different_cost(): void
    {
        $sale = $this->postedSale();
        $original = ErpPurchase::query()->firstOrFail();
        $this->commercialPost('/api/v1/erp/stock/adjustments', ['erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_delta' => '-8', 'reason' => 'Consume remaining'])->assertCreated();
        $replacement = $this->purchase(['unit_cost' => '10']);
        $this->commercialPost("/api/v1/erp/purchases/{$replacement}/post")->assertOk();
        $return = $this->commercialPost('/api/v1/erp/purchase-returns', $this->fullReturnBody('purchase', $original->id, '50'))->assertCreated()->json('data.id');
        $this->commercialPost("/api/v1/erp/purchase-returns/{$return}/post")->assertUnprocessable();
        $this->assertSame('10.0000', ErpStockBalance::query()->firstOrFail()->quantity_on_hand);
        $this->assertSame('10.0000', ErpStockBalance::query()->firstOrFail()->average_cost);
        $this->assertDatabaseHas('erp_obligations', ['source_type' => 'erp_purchase', 'source_id' => $original->id, 'open_amount' => '50']);
    }

    public function test_unrelated_nfe_body_cannot_bypass_disabled_transport_module(): void
    {
        ErpPlanModule::query()->where('module_key', 'cte')->delete();
        $nfe = ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '55', 'number' => 901, 'idempotency_key' => 'unrelated-nfe', 'status' => 'authorized']);
        $extra = ['erp_fiscal_document_id' => $nfe->id];
        $this->commercialPost('/api/v1/erp/fiscal/documents', [...$extra, 'erp_establishment_id' => $this->establishment->id, 'model' => '57', 'number' => 902, 'idempotency_key' => 'disabled-cte-with-nfe-body'])->assertForbidden();
        $this->commercialPost('/api/v1/erp/fiscal/invalidations', [...$extra, 'erp_establishment_id' => $this->establishment->id, 'model' => '57', 'series' => 1, 'number_from' => 903, 'number_to' => 903, 'justification' => 'Unused transport fiscal numbering', 'idempotency_key' => 'disabled-invalidation-with-nfe-body'])->assertForbidden();
    }

    public function test_event_route_model_has_priority_over_unrelated_nfe_body(): void
    {
        ErpPlanModule::query()->where('module_key', 'cte')->delete();
        $nfe = ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '55', 'number' => 911, 'idempotency_key' => 'event-body-nfe', 'status' => 'authorized']);
        $cte = ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '57', 'number' => 912, 'idempotency_key' => 'event-route-cte', 'status' => 'authorized']);
        $event = ErpFiscalEvent::query()->create(['erp_fiscal_document_id' => $cte->id, 'event_type' => 'cancel', 'idempotency_key' => 'cte-event-bypass', 'status' => 'queued']);
        $this->commercialPost('/api/v1/erp/fiscal/events/'.$event->id.'/submit', ['erp_fiscal_document_id' => $nfe->id, 'model' => '55'])->assertForbidden();
        $this->assertSame('queued', $event->fresh()->status);
    }

    public function test_invalidation_route_model_has_priority_over_unrelated_nfe_body(): void
    {
        ErpPlanModule::query()->where('module_key', 'cte')->delete();
        $nfe = ErpFiscalDocument::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '55', 'number' => 921, 'idempotency_key' => 'invalidation-body-nfe', 'status' => 'authorized']);
        $invalidation = ErpFiscalInvalidation::query()->create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'model' => '57', 'series' => 1, 'number_from' => 922, 'number_to' => 922, 'justification' => 'Unused transport fiscal numbering', 'idempotency_key' => 'cte-invalidation-bypass', 'status' => 'queued']);
        $this->commercialPost('/api/v1/erp/fiscal/invalidations/'.$invalidation->id.'/submit', ['erp_fiscal_document_id' => $nfe->id, 'model' => '55'])->assertForbidden();
        $this->assertSame('queued', $invalidation->fresh()->status);
    }

    private int $commercialSequence = 0;

    private function commercialPost($uri, array $data = [], array $headers = [], $options = 0): TestResponse
    {
        if (! isset($headers['Idempotency-Key']) && ! isset($data['source_operation_key'])) {
            $headers['Idempotency-Key'] = 'task9-regression-'.++$this->commercialSequence;
        }
        if (preg_match('#/erp/sales/([0-9]+)/post$#', $uri, $matches) && ! isset($data['draft_revision'])) {
            $data['draft_revision'] = ErpSale::findOrFail($matches[1])->draft_revision;
        }

        return $this->postJson($uri, $data, $headers, $options);
    }

    private function fullReturnBody(string $kind, int $sourceId, string $expected): array
    {
        $class = $kind === 'sale' ? ErpSale::class : ErpPurchase::class;
        $source = $class::findOrFail($sourceId);

        return ['erp_'.$kind.'_id' => $sourceId, 'reason' => 'Regressão: devolver todas as linhas da origem', 'expected_total' => $expected, 'lines' => $source->lines()->orderBy('id')->get()->map(fn ($line): array => ['source_line_id' => $line->id, 'quantity' => $line->quantity])->all()];
    }
}
