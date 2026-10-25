<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use Tests\OfficeErpCommercialTestCase;

class ManualReceiptTest extends OfficeErpCommercialTestCase
{
    public function test_partial_receipt_and_single_reversal_are_idempotent_and_return_authoritative_balances(): void
    {
        $ctx = $this->officeContext();
        $invoice = $this->makeIssuedInvoice($ctx, '100.00');
        $input = $this->receiptInput($invoice, '40.00');

        $posted = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $input, [
            'Idempotency-Key' => 'receipt-partial',
        ])->assertCreated()->json('data');
        self::assertSame('60.00', $posted['current_balances'][0]['balance']['outstanding']);
        self::assertSame('manual', $posted['method']);
        self::assertArrayNotHasKey('bank_confirmed', $posted);

        $replayed = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $input, [
            'Idempotency-Key' => 'receipt-partial',
        ])->assertCreated()->json('data');
        self::assertSame($posted['id'], $replayed['id']);
        self::assertSame($posted['current_balances'], $replayed['current_balances']);
        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/operations/receipt-partial', [
            'Idempotency-Key' => 'receipt-partial',
        ])->assertOk()->assertExactJson(['data' => $posted]);

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $this->receiptInput($invoice, '41.00'), [
            'Idempotency-Key' => 'receipt-partial',
        ])->assertConflict();

        $reverseInput = ['reason' => 'Registro lançado em duplicidade'];
        $reversed = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts/'.$posted['id'].'/reverse', $reverseInput, [
            'Idempotency-Key' => 'receipt-partial-reversal',
        ])->assertCreated()->json('data');
        self::assertSame('100.00', $reversed['current_balances'][0]['balance']['outstanding']);

        $replayedReversal = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts/'.$posted['id'].'/reverse', $reverseInput, [
            'Idempotency-Key' => 'receipt-partial-reversal',
        ])->assertCreated()->json('data');
        self::assertSame($reversed['id'], $replayedReversal['id']);
        self::assertSame(1, OfficeErpReceiptReversal::query()->where('receipt_id', $posted['id'])->count());

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts/'.$posted['id'].'/reverse', $reverseInput, [
            'Idempotency-Key' => 'receipt-second-reversal',
        ])->assertConflict();
        self::assertSame(1, OfficeErpReceipt::query()->whereKey($posted['id'])->count());
    }

    public function test_allocation_sum_must_match_and_locked_balance_rejects_overallocation_with_current_balances(): void
    {
        $ctx = $this->officeContext();
        $invoice = $this->makeIssuedInvoice($ctx, '100.00');
        $mismatched = $this->receiptInput($invoice, '40.00');
        $mismatched['allocations'][0]['amount'] = '39.00';

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $mismatched, [
            'Idempotency-Key' => 'receipt-sum-mismatch',
        ])->assertUnprocessable()->assertJsonValidationErrors('allocations');

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $this->receiptInput($invoice, '100.00'), [
            'Idempotency-Key' => 'receipt-settle-invoice',
        ])->assertCreated();

        $conflict = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $this->receiptInput($invoice, '1.00'), [
            'Idempotency-Key' => 'receipt-stale-overallocation',
        ])->assertConflict()->assertJsonStructure(['data' => ['current_balances' => [['invoice_id', 'balance']]]]);
        self::assertSame('0.00', $conflict->json('data.current_balances.0.balance.outstanding'));
        self::assertSame(1, OfficeErpReceipt::query()->count());
    }

    public function test_one_receipt_reversal_restores_each_invoice_allocation_once(): void
    {
        $ctx = $this->officeContext();
        $firstInvoice = $this->makeIssuedInvoice($ctx, '100.00');
        $secondInvoice = $this->makeIssuedInvoice($ctx, '50.00');
        $input = [
            'amount' => '70.00',
            'received_on' => now('America/Sao_Paulo')->toDateString(),
            'allocations' => [
                ['invoice_id' => (int) $firstInvoice->id, 'amount' => '40.00'],
                ['invoice_id' => (int) $secondInvoice->id, 'amount' => '30.00'],
            ],
        ];

        $receipt = $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $input, [
            'Idempotency-Key' => 'receipt-two-invoices',
        ])->assertCreated()->json('data');
        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts/'.$receipt['id'].'/reverse', [
            'reason' => 'Correção de alocação',
        ], ['Idempotency-Key' => 'receipt-two-invoices-reversal'])->assertCreated()
            ->assertJsonPath('data.current_balances.0.balance.outstanding', '100.00')
            ->assertJsonPath('data.current_balances.1.balance.outstanding', '50.00');

        $detail = $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/receipts/'.$receipt['id'])->assertOk();
        self::assertSame(['100.00', '50.00'], array_column(array_column($detail->json('data.current_balances'), 'balance'), 'outstanding'));
    }

    public function test_foreign_invoice_cannot_be_allocated_and_creates_no_receipt(): void
    {
        $ctx = $this->officeContext();
        $foreign = $this->officeContext();
        $foreignInvoice = $this->makeIssuedInvoice($foreign, '100.00');

        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/receipts', $this->receiptInput($foreignInvoice, '10.00'), [
            'Idempotency-Key' => 'receipt-foreign-invoice',
        ])->assertNotFound();
        self::assertSame(0, OfficeErpReceipt::query()->where('account_id', $ctx->accountId)->count());
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }

    private function makeIssuedInvoice(OfficeErpExecutionContext $ctx, string $amount): OfficeErpInvoice
    {
        $contract = $this->withOfficeAccount($ctx, fn (): OfficeErpContract => $this->acceptedContract($ctx));

        return $this->issuedInvoice($ctx, $contract, $amount);
    }

    /** @return array{amount:string,received_on:string,allocations:list<array{invoice_id:int,amount:string}>} */
    private function receiptInput(OfficeErpInvoice $invoice, string $amount): array
    {
        return [
            'amount' => $amount,
            'received_on' => now('America/Sao_Paulo')->toDateString(),
            'allocations' => [['invoice_id' => (int) $invoice->id, 'amount' => $amount]],
        ];
    }
}
