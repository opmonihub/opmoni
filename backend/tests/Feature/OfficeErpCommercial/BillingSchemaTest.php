<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpBalances;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\OfficeErpCommercialTestCase;

class BillingSchemaTest extends OfficeErpCommercialTestCase
{
    public function test_billing_tables_and_exact_amount_columns_exist(): void
    {
        foreach ([
            'office_erp_invoices',
            'office_erp_invoice_adjustments',
            'office_erp_invoice_counters',
            'office_erp_receipts',
            'office_erp_receipt_allocations',
            'office_erp_receipt_reversals',
            'office_erp_commercial_settings',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), $table);
        }

        foreach ([
            ['office_erp_invoices', 'original_total'],
            ['office_erp_invoice_adjustments', 'amount'],
            ['office_erp_receipts', 'amount'],
            ['office_erp_receipt_allocations', 'amount'],
        ] as [$table, $column]) {
            self::assertSame('numeric', Schema::getColumnType($table, $column), $table.'.'.$column);
        }
    }

    public function test_settings_default_to_manual_policy_zero_grace_and_sao_paulo(): void
    {
        $ctx = $this->officeContext();
        $setting = OfficeErpCommercialSetting::factory()->create(['account_id' => $ctx->accountId]);

        self::assertSame('manual', $setting->delinquency_policy);
        self::assertSame(0, $setting->grace_days);
        self::assertSame('America/Sao_Paulo', $setting->billing_timezone);
    }

    public function test_invoice_counter_reserves_account_year_numbers_sequentially(): void
    {
        $ctx = $this->officeContext();
        $year = 2027;

        self::assertSame('ERP-2027-000001', OfficeErpInvoiceCounter::reserveNumber($ctx->accountId, $year));
        self::assertSame('ERP-2027-000002', OfficeErpInvoiceCounter::reserveNumber($ctx->accountId, $year));
        self::assertSame('ERP-2028-000001', OfficeErpInvoiceCounter::reserveNumber($ctx->accountId, $year + 1));
    }

    public function test_invoice_factory_creates_a_same_account_contract_revision_pair(): void
    {
        $ctx = $this->officeContext();
        $invoice = $this->withOfficeAccount($ctx, fn (): OfficeErpInvoice => OfficeErpInvoice::factory()->create());

        self::assertSame($invoice->account_id, $invoice->contract->account_id);
        self::assertSame($invoice->contract_revision_id, $invoice->contractRevision->id);
        self::assertSame($invoice->account_id, $invoice->contractRevision->account_id);
    }

    public function test_two_manual_documents_can_share_a_period(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $first = $this->issuedInvoice($ctx, $contract);
        $second = OfficeErpInvoice::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'contract_revision_id' => $contract->current_revision_id,
            'kind' => 'manual',
            'period_start' => $first->period_start->toDateString(),
            'period_end' => $first->period_end->toDateString(),
        ]);

        self::assertSame(2, OfficeErpInvoice::query()
            ->where('account_id', $ctx->accountId)
            ->where('contract_id', $contract->id)
            ->whereDate('period_start', $first->period_start)
            ->whereDate('period_end', $first->period_end)
            ->count());
    }

    public function test_recurring_identity_remains_unique_even_after_cancel(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $invoice = $this->issuedInvoice($ctx, $contract);
        $invoice->forceFill(['kind' => 'recurring', 'document_status' => 'canceled'])->save();
        self::assertSame('recurring', $invoice->fresh()->kind);

        $this->expectException(QueryException::class);
        OfficeErpInvoice::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'contract_revision_id' => $contract->current_revision_id,
            'kind' => 'recurring',
            'period_start' => $invoice->period_start->toDateString(),
            'period_end' => $invoice->period_end->toDateString(),
        ]);
    }

    public function test_balance_returns_zero_settled_for_zero_total_and_no_receipt(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx, ['price' => '0.00']);
        $invoice = $this->issuedInvoice($ctx, $contract, '0.00');

        self::assertSame([
            'original_total' => '0.00',
            'adjusted_charge' => '0.00',
            'effective_received' => '0.00',
            'outstanding' => '0.00',
            'settlement_status' => 'settled',
            'overdue' => false,
        ], OfficeErpBalances::forInvoice($invoice));
    }

    public function test_adjustments_and_reversed_receipts_drive_exact_issued_balance(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $invoice = $this->issuedInvoice($ctx, $contract);
        OfficeErpInvoiceAdjustment::factory()->create([
            'account_id' => $ctx->accountId,
            'invoice_id' => $invoice->id,
            'direction' => 'debit',
            'amount' => '20.00',
        ]);
        $receipt = OfficeErpReceipt::factory()->create(['account_id' => $ctx->accountId, 'amount' => '40.00']);
        OfficeErpReceiptAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'receipt_id' => $receipt->id,
            'invoice_id' => $invoice->id,
            'amount' => '40.00',
        ]);

        self::assertSame('120.00', OfficeErpBalances::forInvoice($invoice)['adjusted_charge']);
        self::assertSame('40.00', OfficeErpBalances::forInvoice($invoice)['effective_received']);
        self::assertSame('80.00', OfficeErpBalances::forInvoice($invoice)['outstanding']);

        OfficeErpReceiptReversal::factory()->create(['account_id' => $ctx->accountId, 'receipt_id' => $receipt->id]);
        self::assertSame('0.00', OfficeErpBalances::forInvoice($invoice)['effective_received']);
        self::assertSame('120.00', OfficeErpBalances::forInvoice($invoice)['outstanding']);
    }

    public function test_credit_adjustment_can_settle_a_partially_allocated_invoice(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $invoice = $this->issuedInvoice($ctx, $contract);
        OfficeErpInvoiceAdjustment::factory()->create([
            'account_id' => $ctx->accountId,
            'invoice_id' => $invoice->id,
            'direction' => 'credit',
            'amount' => '60.00',
        ]);
        $receipt = OfficeErpReceipt::factory()->create(['account_id' => $ctx->accountId, 'amount' => '40.00']);
        OfficeErpReceiptAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'receipt_id' => $receipt->id,
            'invoice_id' => $invoice->id,
            'amount' => '40.00',
        ]);

        self::assertSame('40.00', OfficeErpBalances::forInvoice($invoice)['adjusted_charge']);
        self::assertSame('0.00', OfficeErpBalances::forInvoice($invoice)['outstanding']);
        self::assertSame('settled', OfficeErpBalances::forInvoice($invoice)['settlement_status']);
    }

    public function test_overdue_requires_issued_invoice_with_positive_outstanding_after_due_date(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $invoice = $this->issuedInvoice($ctx, $contract);
        $invoice->forceFill(['due_on' => now($contract->billing_timezone)->subDay()->toDateString()])->save();

        self::assertTrue(OfficeErpBalances::forInvoice($invoice)['overdue']);
        $invoice->forceFill(['document_status' => 'canceled'])->save();
        self::assertFalse(OfficeErpBalances::forInvoice($invoice)['overdue']);
        self::assertSame('0.00', OfficeErpBalances::forInvoice($invoice)['outstanding']);
    }

    public function test_invoice_allocation_cannot_cross_account_boundaries(): void
    {
        $firstContext = $this->officeContext();
        $this->issuedInvoice($firstContext, $this->acceptedContract($firstContext));
        $secondContext = $this->officeContext();
        $receipt = OfficeErpReceipt::factory()->create(['account_id' => $firstContext->accountId]);
        $secondInvoice = $this->issuedInvoice($secondContext, $this->acceptedContract($secondContext));

        $this->expectException(QueryException::class);
        OfficeErpReceiptAllocation::factory()->create([
            'account_id' => $firstContext->accountId,
            'receipt_id' => $receipt->id,
            'invoice_id' => $secondInvoice->id,
        ]);
    }

    public function test_exact_large_decimal_survives_refresh_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite numeric affinity cannot verify exact NUMERIC(20,2) storage.');
        }

        $column = DB::selectOne(
            "SELECT numeric_precision, numeric_scale FROM information_schema.columns WHERE table_name = 'office_erp_invoices' AND column_name = 'original_total'",
        );
        self::assertSame(20, (int) $column->numeric_precision);
        self::assertSame(2, (int) $column->numeric_scale);

        $ctx = $this->officeContext();
        $invoice = $this->issuedInvoice($ctx, $this->acceptedContract($ctx), '9007199254740993.01');

        self::assertSame('9007199254740993.01', $invoice->fresh()->original_total);
    }
}
