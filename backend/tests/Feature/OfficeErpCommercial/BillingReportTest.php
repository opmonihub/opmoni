<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpBalances;
use App\Erp\Commercial\OfficeErpQueries;
use App\Http\Requests\OfficeErp\ListCommercialRequest;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use Illuminate\Validation\ValidationException;
use Tests\OfficeErpCommercialTestCase;

final class BillingReportTest extends OfficeErpCommercialTestCase
{
    public function test_later_credit_is_reported_in_posting_month_and_current_balance(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $invoice = $this->issuedInvoice($ctx, $contract, '100.00');
        $invoice->forceFill(['issued_at' => '2026-09-15 15:00:00', 'due_on' => '2026-10-30'])->save();
        OfficeErpInvoiceAdjustment::factory()->create([
            'account_id' => $ctx->accountId,
            'invoice_id' => $invoice->id,
            'direction' => 'credit',
            'amount' => '20.00',
            'posted_at' => '2026-10-03 12:00:00',
        ]);

        $summary = app(OfficeErpQueries::class)->summary($ctx, [
            'from_on' => '2026-10-01',
            'to_on' => '2026-10-31',
        ]);

        self::assertSame('-20.00', $summary['invoiced_movement']);
        self::assertSame('80.00', $summary['outstanding']);
    }

    public function test_receipt_reversal_report_consistency(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $invoice = $this->issuedInvoice($ctx, $contract, '100.00');
        $receipt = OfficeErpReceipt::factory()->create([
            'account_id' => $ctx->accountId,
            'amount' => '30.00',
            'received_on' => now()->toDateString(),
        ]);
        OfficeErpReceiptAllocation::factory()->forInvoice($invoice)->forReceipt($receipt)->create([
            'account_id' => $ctx->accountId,
            'amount' => '30.00',
        ]);
        OfficeErpReceiptReversal::factory()->create([
            'account_id' => $ctx->accountId,
            'receipt_id' => $receipt->id,
        ]);

        $summary = app(OfficeErpQueries::class)->summary($ctx, [
            'from_on' => now()->startOfMonth()->toDateString(),
            'to_on' => now()->endOfMonth()->toDateString(),
        ]);

        self::assertSame(OfficeErpBalances::forInvoice($invoice->refresh())['outstanding'], $summary['outstanding']);
        self::assertSame('0.00', $summary['effective_received']);
    }

    public function test_all_commercial_lists_are_server_paginated_and_scoped(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $this->issuedInvoice($ctx, $contract);
        OfficeErpReceipt::factory()->create(['account_id' => $ctx->accountId]);
        $otherOffice = $this->officeContext();
        $this->acceptedContract($otherOffice);
        $queries = app(OfficeErpQueries::class);

        foreach (['clients', 'plans', 'contracts', 'invoices', 'receipts'] as $resource) {
            $page = $queries->{$resource}($ctx, ['page' => 1, 'per_page' => 25]);
            self::assertSame(25, $page->perPage());
            self::assertSame(1, $page->currentPage());
            self::assertSame(1, $page->total(), $resource.' list must not expose another Account.');
        }
    }

    public function test_resource_filters_reject_meaningless_and_invalid_values(): void
    {
        self::assertSame(['search' => 'Acme'], ListCommercialRequest::validateFilters('clients', ['search' => 'Acme']));

        try {
            ListCommercialRequest::validateFilters('clients', ['due_from' => '2026-10-01']);
            self::fail('Client list must reject invoice due-date filters.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }

        try {
            ListCommercialRequest::validateFilters('invoices', ['from_on' => '2026-11-01', 'to_on' => '2026-10-01']);
            self::fail('Date ranges must be ordered.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }
}
