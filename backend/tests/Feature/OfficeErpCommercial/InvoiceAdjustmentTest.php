<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\ErpCompany;
use Tests\OfficeErpCommercialTestCase;

class InvoiceAdjustmentTest extends OfficeErpCommercialTestCase
{
    public function test_credit_settles_partial_receipt_without_changing_original_total(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'credit-balance');
        $this->recordReceipt($ctx, $invoice, '40.00');

        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'credit', 'amount' => '60.00', 'reason' => 'Crédito parcial acordado',
        ], ['Idempotency-Key' => 'credit-60'])->assertCreated();

        $balance = $this->getJson('/api/v1/erp-commercial/invoices/'.$invoice->id)->assertOk()->json('data.balance');
        self::assertSame('100.00', $balance['original_total']);
        self::assertSame('40.00', $balance['adjusted_charge']);
        self::assertSame('40.00', $balance['effective_received']);
        self::assertSame('0.00', $balance['outstanding']);
    }

    public function test_credit_that_exceeds_received_amount_is_rejected_without_history(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'credit-limit');
        $this->recordReceipt($ctx, $invoice, '40.00');

        $before = $invoice->adjustments()->count();
        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'credit', 'amount' => '61.00', 'reason' => 'Crédito acima do recebido',
        ], ['Idempotency-Key' => 'credit-61'])->assertUnprocessable();

        self::assertSame($before, $invoice->fresh()->adjustments()->count());
    }

    public function test_debit_on_settled_invoice_creates_new_outstanding_balance(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'debit-settled');
        $this->recordReceipt($ctx, $invoice, '100.00');

        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'debit', 'amount' => '20.00', 'reason' => 'Serviço adicional',
        ], ['Idempotency-Key' => 'debit-20'])->assertCreated();

        self::assertSame('20.00', $this->getJson('/api/v1/erp-commercial/invoices/'.$invoice->id)->assertOk()->json('data.balance.outstanding'));
    }

    public function test_adjustment_replay_returns_original_id_and_changed_payload_conflicts(): void
    {
        [, $contract] = $this->effectiveContract();
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'adjust-replay');
        $payload = ['direction' => 'debit', 'amount' => '10.00', 'reason' => 'Ajuste de teste'];
        $first = $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', $payload, ['Idempotency-Key' => 'adjust-replay-key'])
            ->assertCreated()->json('data.id');
        $replayed = $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', $payload, ['Idempotency-Key' => 'adjust-replay-key'])
            ->assertCreated()->json('data.id');

        self::assertSame($first, $replayed);
        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'debit', 'amount' => '11.00', 'reason' => 'Outro ajuste',
        ], ['Idempotency-Key' => 'adjust-replay-key'])->assertConflict();
        self::assertSame(1, $invoice->fresh()->adjustments()->count());

        $this->getJson('/api/v1/erp-commercial/operations/adjust-replay-key', ['Idempotency-Key' => 'adjust-replay-key'])
            ->assertOk()->assertJsonPath('data.id', $first);
    }

    public function test_opposite_adjustment_is_append_only_correction_and_cancellation_requires_receipt_reversal(): void
    {
        [$ctx, $contract] = $this->effectiveContract();
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'adjust-opposite');
        $receiptId = $this->recordReceipt($ctx, $invoice, '40.00');
        $original = $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'credit', 'amount' => '10.00', 'reason' => 'Crédito original',
        ], ['Idempotency-Key' => 'opposite-original'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'debit', 'amount' => '11.00', 'reason' => 'Correção parcial inválida', 'corrects_adjustment_id' => $original,
        ], ['Idempotency-Key' => 'opposite-correction-invalid'])->assertUnprocessable();
        self::assertSame(1, $invoice->fresh()->adjustments()->count());

        $correction = $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'debit', 'amount' => '10.00', 'reason' => 'Reversão do crédito', 'corrects_adjustment_id' => $original,
        ], ['Idempotency-Key' => 'opposite-correction'])->assertCreated()->json('data');
        self::assertSame($original, $correction['corrects_adjustment_id']);
        self::assertSame(2, $invoice->fresh()->adjustments()->count());

        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/cancel', ['reason' => 'Encerrar documento'], [
            'Idempotency-Key' => 'cancel-receipt-bearing',
        ])->assertConflict();
        self::assertSame('issued', $invoice->fresh()->document_status);
        $this->withOfficeAccount($ctx, function () use ($ctx, $receiptId): void {
            OfficeErpRecords::persist($ctx, new OfficeErpReceiptReversal([
                'account_id' => $ctx->accountId,
                'receipt_id' => $receiptId,
                'reason' => 'Estorno necessário antes do cancelamento',
                'reversed_by_user_id' => $ctx->actor?->id,
                'reversed_at' => now(),
            ]));
        });
        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/cancel', ['reason' => 'Encerrar após estorno'], [
            'Idempotency-Key' => 'cancel-after-reversal',
        ])->assertOk()->assertJsonPath('data.document_status', 'canceled');
        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/issue', [], ['Idempotency-Key' => 'adjust-opposite-issue'])
            ->assertOk()->assertJsonPath('data.document_status', 'issued');
    }

    public function test_invalid_adjustments_and_draft_adjustments_do_not_write_history(): void
    {
        [, $contract] = $this->effectiveContract();
        $draft = $this->postJson('/api/v1/erp-commercial/invoices', [
            'contract_id' => $contract->id,
            'items' => [['description' => 'Serviço', 'amount' => '100.00', 'discount' => '0.00']],
            'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
        ], ['Idempotency-Key' => 'draft-for-adjustment'])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft.'/adjustments', [
            'direction' => 'debit', 'amount' => '1.00', 'reason' => 'Rascunho não ajustável',
        ], ['Idempotency-Key' => 'draft-adjustment'])->assertConflict();

        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'invalid-adjustment');
        foreach ([
            ['direction' => 'unknown', 'amount' => '1.00', 'reason' => 'Inválido'],
            ['direction' => 'credit', 'amount' => '0.00', 'reason' => 'Zero'],
            ['direction' => 'credit', 'amount' => '1e1', 'reason' => 'Científico'],
            ['direction' => 'credit', 'amount' => '1.001', 'reason' => 'Precisão'],
            ['direction' => 'credit', 'amount' => '1.00', 'reason' => ' '],
        ] as $index => $payload) {
            $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', $payload, [
                'Idempotency-Key' => 'invalid-adjustment-'.$index,
            ])->assertUnprocessable();
        }

        self::assertSame(0, $invoice->fresh()->adjustments()->count());
    }

    /** @return array{OfficeErpExecutionContext, OfficeErpContract, ErpCompany} */
    private function effectiveContract(): array
    {
        $ctx = $this->officeContext();
        $contract = $this->withOfficeAccount($ctx, fn (): OfficeErpContract => $this->acceptedContract($ctx));
        $company = ErpCompany::factory()->create();
        $this->withOfficeAccount($ctx, function () use ($ctx, $contract, $company): void {
            $contract->forceFill([
                'erp_company_id' => $company->id,
                'agreement_status' => 'effective',
                'service_anchor_on' => '2026-10-01',
                'effective_at' => now(),
            ])->save();
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $ctx->accountId,
                'contract_id' => $contract->id,
                'erp_company_id' => $company->id,
                'state' => 'occupied',
                'expires_at' => null,
            ]);
        });
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return [$ctx, $contract->refresh(), $company];
    }

    private function issuedInvoiceOverHttp(OfficeErpContract $contract, string $amount, string $key): OfficeErpInvoice
    {
        $draft = $this->postJson('/api/v1/erp-commercial/invoices', [
            'contract_id' => $contract->id,
            'items' => [['description' => 'Serviço mensal', 'amount' => $amount, 'discount' => '0.00']],
            'period_start' => '2026-10-01', 'period_end' => '2026-11-01', 'due_on' => '2026-10-11',
        ], ['Idempotency-Key' => $key.'-draft'])->assertCreated()->json('data');
        $this->postJson('/api/v1/erp-commercial/invoices/'.$draft['id'].'/issue', [], ['Idempotency-Key' => $key.'-issue'])->assertOk();

        return OfficeErpInvoice::query()->findOrFail($draft['id']);
    }

    private function recordReceipt(OfficeErpExecutionContext $ctx, OfficeErpInvoice $invoice, string $amount): int
    {
        return $this->withOfficeAccount($ctx, function () use ($ctx, $invoice, $amount): int {
            $receipt = OfficeErpRecords::persist($ctx, new OfficeErpReceipt([
                'account_id' => $ctx->accountId,
                'amount' => $amount,
                'received_on' => '2026-10-15',
                'reference' => 'Fixture receipt',
                'method' => 'manual',
                'recorded_by_user_id' => $ctx->actor?->id,
            ]));
            OfficeErpRecords::persist($ctx, new OfficeErpReceiptAllocation([
                'account_id' => $ctx->accountId,
                'receipt_id' => $receipt->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
            ]));

            return (int) $receipt->id;
        });
    }
}
