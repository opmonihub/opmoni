<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpBalances;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpQueries;
use App\Http\Requests\OfficeErp\ListCommercialRequest;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\ErpCompany;
use Illuminate\Validation\ValidationException;
use Tests\OfficeErpCommercialTestCase;

final class BillingReportTest extends OfficeErpCommercialTestCase
{
    public function test_production_routes_expose_summary_and_serialized_paginated_lists(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $otherOffice = $this->officeContext();
        $otherContract = $this->acceptedContract($otherOffice);
        $companyName = 'Empresa ERP distinta 83592';
        $company = ErpCompany::factory()->create(['name' => $companyName]);
        $this->withOfficeAccount($ctx, function () use ($ctx, $contract, $company): void {
            Client::query()->where('account_id', $ctx->accountId)->whereKey($contract->client_id)->update(['name' => 'Cliente de teste']);
            OfficeErpSalesPlan::query()->where('account_id', $ctx->accountId)->whereKey($contract->sales_plan_id)->update(['name' => 'Plano de teste']);
            $contract->forceFill(['erp_company_id' => $company->id])->save();
        });
        $invoice = $this->issuedInvoice($ctx, $contract, '100.00');
        $receipt = OfficeErpReceipt::factory()->create([
            'account_id' => $ctx->accountId,
            'reference' => 'Comprovante mensal',
            'amount' => '20.00',
        ]);
        OfficeErpReceiptAllocation::factory()->forInvoice($invoice)->forReceipt($receipt)->create([
            'account_id' => $ctx->accountId,
            'amount' => '20.00',
        ]);

        $summary = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/summary')
            ->assertOk()
            ->assertJsonPath('data.outstanding', '80.00');
        self::assertStringContainsString('no-store', (string) $summary->headers->get('Cache-Control'));

        foreach ([
            ['/clients?q='.urlencode('Cliente'), 'data.0.tax_id'],
            ['/plans?q='.urlencode('Plano'), 'data.0.current_revision.price'],
            ['/contracts?agreement_status=accepted', 'data.0.current_revision.price'],
            ['/invoices?document_status=issued&contract_id='.$contract->id, 'data.0.balance.outstanding'],
            ['/invoices?q='.urlencode($companyName).'&contract_id='.$contract->id, 'data.0.id'],
            ['/receipts?received_from='.now()->toDateString(), 'data.0.amount'],
        ] as [$path, $shape]) {
            $response = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial'.$path)
                ->assertOk()
                ->assertJsonPath('meta.current_page', 1)
                ->assertJsonPath('meta.per_page', 25);
            self::assertSame(1, data_get($response->json(), 'meta.total'), $path.' must be scoped and filtered.');
            self::assertNotNull(data_get($response->json(), $shape), $path.' must retain its existing consumer shape.');
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/invoices?contract_id='.$otherContract->id)
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        self::assertNotNull($invoice->id);
    }

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
        $invoice = $this->issuedInvoice($ctx, $contract);
        $invoice->forceFill(['issued_at' => '2026-10-16 01:30:00'])->save();
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

        self::assertSame(1, $queries->plans($ctx, ['status' => 'active'])->total());
        self::assertSame(0, $queries->plans($ctx, ['status' => 'archived'])->total());
        self::assertSame(1, $queries->invoices($ctx, [
            'status' => 'issued',
            'from_on' => '2026-10-15',
            'to_on' => '2026-10-15',
        ])->total());
        self::assertSame(0, $queries->invoices($ctx, ['status' => 'canceled'])->total());
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
            ListCommercialRequest::validateFilters('invoices', ['status' => 'effective']);
            self::fail('Invoice status filter must reject states that invoices do not have.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    public function test_client_search_treats_like_metacharacters_as_literals(): void
    {
        $ctx = $this->officeContext();
        $this->withOfficeAccount($ctx, function () use ($ctx): void {
            Client::factory()->company()->create([
                'account_id' => $ctx->accountId,
                'name' => 'Acme %_ Holdings',
                'trade_name' => 'Acme target',
            ]);
            Client::factory()->company()->create([
                'account_id' => $ctx->accountId,
                'name' => 'AcmeXY Holdings',
                'trade_name' => 'Unrelated',
            ]);
        });

        $page = app(OfficeErpQueries::class)->clients($ctx, ['search' => '%_']);

        self::assertSame(1, $page->total());
        self::assertSame('Acme %_ Holdings', $page->items()[0]->name);
    }

    public function test_posting_date_uses_office_timezone_at_utc_month_boundary(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 2)->startOfDay());
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $invoice = $this->issuedInvoice($ctx, $contract, '100.00');
        $invoice->forceFill(['issued_at' => '2026-11-01 01:30:00'])->save();
        OfficeErpInvoiceAdjustment::factory()->create([
            'account_id' => $ctx->accountId,
            'invoice_id' => $invoice->id,
            'direction' => 'debit',
            'amount' => '7.00',
            'posted_at' => '2026-11-01 01:30:00',
        ]);

        $summary = app(OfficeErpQueries::class)->summary($ctx, [
            'from_on' => '2026-10-01',
            'to_on' => '2026-10-31',
        ]);

        self::assertSame('107.00', $summary['invoiced_movement']);
    }

    public function test_summary_due_filters_apply_to_current_balances_only(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->startOfDay());
        $ctx = $this->officeContext();
        $firstContract = $this->acceptedContract($ctx);
        $firstInvoice = $this->issuedInvoice($ctx, $firstContract, '100.00');
        $firstInvoice->forceFill(['due_on' => '2026-10-10'])->save();
        $secondContract = $this->acceptedContract($ctx);
        $secondInvoice = $this->issuedInvoice($ctx, $secondContract, '50.00');
        $secondInvoice->forceFill(['due_on' => '2026-10-25'])->save();

        $summary = app(OfficeErpQueries::class)->summary($ctx, [
            'from_on' => '2026-10-01',
            'to_on' => '2026-10-31',
            'due_from' => '2026-10-20',
            'due_to' => '2026-10-31',
        ]);

        self::assertSame('150.00', $summary['invoiced_movement']);
        self::assertSame('50.00', $summary['outstanding']);
        self::assertSame('2026-10-20', $summary['due_from']);

        try {
            ListCommercialRequest::validateFilters('invoices', ['from_on' => '2026-11-01', 'to_on' => '2026-10-01']);
            self::fail('Date ranges must be ordered.');
        } catch (ValidationException) {
            self::assertTrue(true);
        }
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }
}
