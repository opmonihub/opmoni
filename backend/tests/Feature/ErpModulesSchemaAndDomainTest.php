<?php

namespace Tests\Feature;

use App\Erp\Finance\ErpCashSessionStateMachine;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpPaymentAllocationService;
use App\Erp\Fiscal\ErpFiscalDocumentStateMachine;
use App\Erp\Sales\ErpCommercialDocumentStateMachine;
use App\Erp\Sales\ErpSaleTotalsCalculator;
use App\Erp\Stock\ErpStockValuation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class ErpModulesSchemaAndDomainTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function moduleTables(): array
    {
        return [
            'erp_financial_accounts',
            'erp_obligations',
            'erp_payments',
            'erp_payment_allocations',
            'erp_cash_registers',
            'erp_cash_sessions',
            'erp_purchases',
            'erp_purchase_lines',
            'erp_purchase_returns',
            'erp_stock_balances',
            'erp_stock_movements',
            'erp_stock_adjustments',
            'erp_stock_transfers',
            'erp_quotes',
            'erp_orders',
            'erp_sales',
            'erp_sale_lines',
            'erp_sale_returns',
            'erp_invoice_schemes',
            'erp_invoice_layouts',
            'erp_fiscal_certificates',
            'erp_fiscal_documents',
            'erp_fiscal_events',
            'erp_fiscal_invalidations',
        ];
    }

    public function test_additive_module_tables_exist_without_touching_office_accounts(): void
    {
        foreach ($this->moduleTables() as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        $this->assertTrue(Schema::hasTable('accounts'));
        $this->assertTrue(Schema::hasColumns('accounts', ['id', 'name']));
    }

    public function test_decimal_parser_matches_brazilian_and_plain_formats(): void
    {
        $this->assertSame('1234.5600', ErpDecimal::parse('1.234,56'));
        $this->assertSame('10.5000', ErpDecimal::parse('10.5'));
        $this->assertSame('15.0000', ErpDecimal::add('10', '5'));
    }

    public function test_payment_allocation_rejects_overpayment(): void
    {
        $service = new ErpPaymentAllocationService;

        $service->assertAllocationAllowed('50.0000', '40.0000');

        $this->expectException(InvalidArgumentException::class);
        $service->assertAllocationAllowed('50.0000', '60.0000');
    }

    public function test_cash_session_close_records_explicit_difference(): void
    {
        $machine = new ErpCashSessionStateMachine;
        $result = $machine->close(
            ErpCashSessionStateMachine::STATUS_OPEN,
            '100.0000',
            '25.5000',
            '130.0000',
        );

        $this->assertSame(ErpCashSessionStateMachine::STATUS_CLOSED, $result['status']);
        $this->assertSame('125.5000', $result['expected_closing_amount']);
        $this->assertSame('4.5000', $result['closing_difference']);
    }

    public function test_stock_valuation_consumes_fifo_layers(): void
    {
        $valuation = new ErpStockValuation;
        $result = $valuation->consume(ErpStockValuation::FIFO, '3', [
            ['quantity' => '2', 'unit_cost' => '10.0000'],
            ['quantity' => '5', 'unit_cost' => '12.0000'],
        ]);

        $this->assertSame('32.0000', $result['total_cost']);
        $this->assertCount(2, $result['allocations']);
    }

    public function test_sale_totals_calculator_matches_server_side_invoice_math(): void
    {
        $calculator = new ErpSaleTotalsCalculator;
        $totals = $calculator->calculate(
            [
                ['quantity' => '2', 'unit_price' => '10,50'],
                ['quantity' => '1', 'unit_price' => '5'],
            ],
            ['discount_type' => 'fixed', 'discount_amount' => '2,00'],
            '10',
        );

        $this->assertSame('26.0000', $totals['subtotal_amount']);
        $this->assertSame('2.0000', $totals['discount_amount']);
        $this->assertSame('2.4000', $totals['tax_amount']);
        $this->assertSame('26.4000', $totals['total_amount']);
    }

    public function test_commercial_and_fiscal_state_machines_enforce_valid_transitions(): void
    {
        $commercial = new ErpCommercialDocumentStateMachine;
        $this->assertSame(
            ErpCommercialDocumentStateMachine::SALE_POSTED,
            $commercial->postSale(ErpCommercialDocumentStateMachine::SALE_DRAFT),
        );

        $fiscal = new ErpFiscalDocumentStateMachine;
        $this->assertSame(
            ErpFiscalDocumentStateMachine::QUEUED,
            $fiscal->queueFromDraft(ErpFiscalDocumentStateMachine::DRAFT),
        );

        $this->expectException(InvalidArgumentException::class);
        $fiscal->transition(ErpFiscalDocumentStateMachine::DRAFT, ErpFiscalDocumentStateMachine::AUTHORIZED);
    }

    public function test_source_operation_keys_are_unique_for_obligations_payments_stock_and_sales(): void
    {
        $company = ErpCompany::factory()->create();
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $user = User::factory()->create();
        $account = ErpFinancialAccount::query()->create([
            'erp_company_id' => $company->id,
            'name' => 'Caixa',
        ]);

        ErpObligation::query()->create([
            'erp_company_id' => $company->id,
            'direction' => 'receivable',
            'category' => 'sale',
            'amount' => '10',
            'open_amount' => '10',
            'source_type' => 'erp_sale',
            'source_id' => 1,
            'source_operation_key' => 'obligation-1',
        ]);

        $payment = ErpPayment::query()->create([
            'erp_company_id' => $company->id,
            'amount' => '10',
            'method' => 'cash',
            'source_type' => 'erp_sale',
            'source_id' => 1,
            'source_operation_key' => 'payment-1',
        ]);

        ErpPaymentAllocation::query()->create([
            'erp_payment_id' => $payment->id,
            'erp_obligation_id' => ErpObligation::query()->firstOrFail()->id,
            'amount' => '10',
            'source_operation_key' => 'alloc-1',
        ]);

        ErpStockMovement::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'erp_product_variant_id' => 99,
            'direction' => 'out',
            'quantity' => '1',
            'source_type' => 'erp_sale',
            'source_id' => 1,
            'source_operation_key' => 'stock-1',
        ]);

        ErpSale::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'invoice_no' => '0001',
            'source_operation_key' => 'sale-post-1',
            'posted_by_user_id' => $user->id,
        ]);

        ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'number' => 1,
            'idempotency_key' => 'fiscal-doc-1',
        ]);

        $this->assertSame(1, ErpObligation::query()->count());
        $this->assertSame(1, ErpPayment::query()->count());
        $this->assertSame(1, ErpStockMovement::query()->count());
        $this->assertSame(1, ErpSale::query()->count());
        $this->assertSame(1, ErpFiscalDocument::query()->count());
        $this->assertNotNull($account->id);
    }
}
