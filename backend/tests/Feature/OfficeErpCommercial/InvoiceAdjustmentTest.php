<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\ErpCommercialCatalog;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\OfficeErpCommercialTestCase;

class InvoiceAdjustmentTest extends OfficeErpCommercialTestCase
{
    public function test_credit_settles_partial_receipt_without_changing_original_total(): void
    {
        [$ctx, $contract, $company] = $this->effectiveContract();
        $contract->forceFill(['manual_hold' => true])->save();
        $subscription = $this->linkCompanySubscription($contract, $company, 'suspended');
        OfficeErpCommercialSetting::query()->create([
            'account_id' => $ctx->accountId,
            'delinquency_policy' => 'automatic',
            'grace_days' => 0,
            'billing_timezone' => 'America/Sao_Paulo',
        ]);
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
        self::assertTrue($contract->fresh()->manual_hold);
        self::assertSame('suspended', $subscription->fresh()->status);
    }

    public function test_credit_that_exceeds_received_amount_is_rejected_without_history(): void
    {
        [$ctx, $contract, $company] = $this->effectiveContract();
        $subscription = $this->linkCompanySubscription($contract, $company);
        $allocation = OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->firstOrFail();
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'credit-limit');
        $this->recordReceipt($ctx, $invoice, '40.00');

        $invoiceBefore = $invoice->fresh()->getAttributes();
        $adjustmentsBefore = OfficeErpInvoiceAdjustment::query()->where('invoice_id', $invoice->id)
            ->orderBy('id')->get()->map(fn (OfficeErpInvoiceAdjustment $adjustment): array => $adjustment->getAttributes())->all();
        $receiptsBefore = OfficeErpReceipt::query()->where('account_id', $ctx->accountId)
            ->orderBy('id')->get()->map(fn (OfficeErpReceipt $receipt): array => $receipt->getAttributes())->all();
        $receiptAllocationsBefore = OfficeErpReceiptAllocation::query()->where('account_id', $ctx->accountId)
            ->orderBy('id')->get()->map(fn (OfficeErpReceiptAllocation $receiptAllocation): array => $receiptAllocation->getAttributes())->all();
        $licenceBefore = $allocation->fresh()->getAttributes();
        $subscriptionBefore = $subscription->fresh()->getAttributes();
        $operationsBefore = OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)
            ->orderBy('id')->get()->map(fn (OfficeErpCommercialOperation $operation): array => $operation->getAttributes())->all();
        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'credit', 'amount' => '61.00', 'reason' => 'Crédito acima do recebido',
        ], ['Idempotency-Key' => 'credit-61'])->assertUnprocessable();

        self::assertSame($invoiceBefore, $invoice->fresh()->getAttributes());
        self::assertSame($adjustmentsBefore, OfficeErpInvoiceAdjustment::query()->where('invoice_id', $invoice->id)
            ->orderBy('id')->get()->map(fn (OfficeErpInvoiceAdjustment $adjustment): array => $adjustment->getAttributes())->all());
        self::assertSame($receiptsBefore, OfficeErpReceipt::query()->where('account_id', $ctx->accountId)
            ->orderBy('id')->get()->map(fn (OfficeErpReceipt $receipt): array => $receipt->getAttributes())->all());
        self::assertSame($receiptAllocationsBefore, OfficeErpReceiptAllocation::query()->where('account_id', $ctx->accountId)
            ->orderBy('id')->get()->map(fn (OfficeErpReceiptAllocation $receiptAllocation): array => $receiptAllocation->getAttributes())->all());
        self::assertSame($licenceBefore, $allocation->fresh()->getAttributes());
        self::assertSame($subscriptionBefore, $subscription->fresh()->getAttributes());
        self::assertSame($operationsBefore, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)
            ->orderBy('id')->get()->map(fn (OfficeErpCommercialOperation $operation): array => $operation->getAttributes())->all());
    }

    public function test_debit_on_settled_invoice_creates_new_outstanding_balance(): void
    {
        CarbonImmutable::setTestNow('2026-10-27T13:00:00-03:00');
        $this->beforeApplicationDestroyed(static function (): void {
            CarbonImmutable::setTestNow();
        });
        [$ctx, $contract, $company] = $this->effectiveContract();
        $subscription = $this->linkCompanySubscription($contract, $company);
        OfficeErpCommercialSetting::query()->create([
            'account_id' => $ctx->accountId,
            'delinquency_policy' => 'automatic',
            'grace_days' => 0,
            'billing_timezone' => 'America/Sao_Paulo',
        ]);
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'debit-settled');
        $this->recordReceipt($ctx, $invoice, '100.00');

        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'debit', 'amount' => '20.00', 'reason' => 'Serviço adicional',
        ], ['Idempotency-Key' => 'debit-20'])->assertCreated()
            ->assertJsonPath('data.direction', 'debit')
            ->assertJsonPath('data.amount', '20.00');

        $detail = $this->getJson('/api/v1/erp-commercial/invoices/'.$invoice->id)->assertOk();
        self::assertSame('100.00', $detail->json('data.balance.original_total'));
        self::assertSame('120.00', $detail->json('data.balance.adjusted_charge'));
        self::assertSame('100.00', $detail->json('data.balance.effective_received'));
        self::assertSame('20.00', $detail->json('data.balance.outstanding'));
        self::assertSame('2026-10-11', $detail->json('data.due_on'));
        self::assertSame([['direction' => 'debit', 'amount' => '20.00']], array_map(
            static fn (array $adjustment): array => ['direction' => $adjustment['direction'], 'amount' => $adjustment['amount']],
            $detail->json('data.adjustments'),
        ));
        self::assertSame('past_due', $subscription->fresh()->status);
    }

    public function test_office_subscription_cancellation_preserves_company_terms_invoice_and_direct_access(): void
    {
        [$ctx, $contract, $company] = $this->effectiveContract();
        $owner = User::factory()->create();
        $companyMembership = ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $subscription = $this->linkCompanySubscription($contract, $company);
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'office-cancel-company-view');

        $accountBefore = $ctx->account->fresh()->getAttributes();
        $officeMembershipBefore = AccountMembership::query()
            ->where('account_id', $ctx->accountId)
            ->where('user_id', $ctx->actor->id)
            ->firstOrFail()->getAttributes();
        $contractBefore = $contract->fresh()->getAttributes();
        $revisionBefore = $contract->currentRevision()->firstOrFail()->getAttributes();
        $invoiceBefore = $invoice->fresh()->getAttributes();
        $subscriptionBefore = $subscription->fresh()->getAttributes();
        $companyMembershipBefore = $companyMembership->fresh()->getAttributes();

        $ctx->account->subscription()->firstOrFail()->forceFill(['status' => 'canceled'])->save();

        self::assertSame($accountBefore, $ctx->account->fresh()->getAttributes());
        self::assertSame($officeMembershipBefore, AccountMembership::query()
            ->where('account_id', $ctx->accountId)
            ->where('user_id', $ctx->actor->id)
            ->firstOrFail()->getAttributes());
        self::assertSame($contractBefore, $contract->fresh()->getAttributes());
        self::assertSame($revisionBefore, $contract->currentRevision()->firstOrFail()->getAttributes());
        self::assertSame($invoiceBefore, $invoice->fresh()->getAttributes());
        self::assertSame('canceled', $ctx->account->subscription()->firstOrFail()->status);
        self::assertSame($subscriptionBefore, $subscription->fresh()->getAttributes());
        self::assertSame($companyMembershipBefore, $companyMembership->fresh()->getAttributes());

        $this->loginAndSelectCompany($owner, $company);
        self::assertSame(0, AccountMembership::query()->where('user_id', $owner->id)->count());
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.retail_contract.terms.price', '100.00');
        $this->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/commercial-invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.balance.original_total', '100.00')
            ->assertJsonPath('data.balance.outstanding', '100.00');
    }

    public function test_adjustment_replay_returns_original_id_and_changed_payload_conflicts(): void
    {
        [, $contract] = $this->effectiveContract();
        $invoice = $this->issuedInvoiceOverHttp($contract, '100.00', 'adjust-replay');
        $payload = ['direction' => 'debit', 'amount' => '10.00', 'reason' => 'Ajuste de teste'];
        $firstOutcome = $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', $payload, ['Idempotency-Key' => 'adjust-replay-key'])
            ->assertCreated()->json('data');
        $first = $firstOutcome['id'];
        $replayed = $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', $payload, ['Idempotency-Key' => 'adjust-replay-key'])
            ->assertCreated()->json('data.id');

        self::assertSame($first, $replayed);
        $this->postJson('/api/v1/erp-commercial/invoices/'.$invoice->id.'/adjustments', [
            'direction' => 'debit', 'amount' => '11.00', 'reason' => 'Outro ajuste',
        ], ['Idempotency-Key' => 'adjust-replay-key'])->assertConflict();
        self::assertSame(1, $invoice->fresh()->adjustments()->count());

        $lookup = $this->getJson('/api/v1/erp-commercial/operations/adjust-replay-key', ['Idempotency-Key' => 'adjust-replay-key'])
            ->assertOk()->json('data');
        self::assertSame($firstOutcome, $lookup);
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

    private function linkCompanySubscription(
        OfficeErpContract $contract,
        ErpCompany $company,
        string $status = 'active',
    ): ErpSubscription {
        return ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => $status,
            'office_erp_contract_id' => $contract->id,
        ]);
    }

    private function loginAndSelectCompany(User $user, ErpCompany $company): void
    {
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'))
            ->postJson('/api/v1/erp/company/switch', ['company_id' => $company->id])
            ->assertOk();
    }
}
