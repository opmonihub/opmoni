<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpReceipts;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpInvoiceAdjustment;
use App\Models\Erp\Commercial\OfficeErpInvoiceCounter;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\Commercial\OfficeErpReceipt;
use App\Models\Erp\Commercial\OfficeErpReceiptAllocation;
use App\Models\Erp\Commercial\OfficeErpReceiptProof;
use App\Models\Erp\Commercial\OfficeErpReceiptReversal;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use App\Services\BrazilianTaxId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OfficeErpCommercialConcurrencyTest extends OfficeErpCommercialProcessTestCase
{
    public function test_two_authenticated_acceptances_replay_one_invitation_result(): void
    {
        $office = $this->commercialFixture(2);
        $owner = User::factory()->create(['email' => 'race-owner-'.bin2hex(random_bytes(6)).'@example.test']);
        $invitation = $this->ownerInvitationFixture($office, $owner);
        $first = $this->worker('owner_accept', $this->ownerAcceptancePayload($invitation, true));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'accounts');
        $second = $this->worker('owner_accept', $this->ownerAcceptancePayload($invitation));
        $this->assertWaitingOnRowLock($second, 'accounts');
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame([201, 201], array_column($results, 'status'));
        self::assertSame($results[0]['contract_id'], $results[1]['contract_id']);
        self::assertSame($results[0]['company_id'], $results[1]['company_id']);
        self::assertSame('accepted', $results[0]['agreement_status']);
        self::assertSame('accepted', $results[1]['agreement_status']);
        self::assertSame(1, ErpCompany::query()->where('cnpj', $invitation['cnpj'])->count());
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $results[0]['company_id'])->where('user_id', $owner->id)->where('role', 'owner')->count());
        self::assertSame('consumed', OfficeErpOwnerInvitation::query()->findOrFail($invitation['invitation_id'])->status);
        self::assertSame('accepted', DB::table('office_erp_contracts')->where('id', $invitation['contract_id'])->value('agreement_status'));
        self::assertSame('occupied', DB::table('office_erp_licence_allocations')->where('contract_id', $invitation['contract_id'])->value('state'));
        self::assertSame('pending', ErpSubscription::query()->where('erp_company_id', $results[0]['company_id'])->where('office_erp_contract_id', $invitation['contract_id'])->value('status'));
        self::assertSame(1, DB::table('office_erp_commercial_operations')->where('action', 'owner.accept')->where('account_id', $invitation['account_id'])->count());
        self::assertSame(1, DB::table('office_erp_audit_events as audit')->join('office_erp_commercial_operations as operation', 'operation.id', '=', 'audit.operation_id')->where('operation.action', 'owner.accept')->where('operation.account_id', $invitation['account_id'])->count());
    }

    public function test_same_cnpj_acceptance_contenders_leave_one_unaccepted_loser(): void
    {
        $owner = User::factory()->create(['email' => 'cnpj-race-owner-'.bin2hex(random_bytes(6)).'@example.test']);
        $cnpj = $this->uniqueCnpj();
        self::assertTrue(app(BrazilianTaxId::class)->isValidCnpj($cnpj));
        $firstInvitation = $this->ownerInvitationFixture($this->commercialFixture(2), $owner, $cnpj);
        $secondInvitation = $this->ownerInvitationFixture($this->commercialFixture(2), $owner, $cnpj);
        $first = $this->worker('owner_accept', $this->ownerAcceptancePayload($firstInvitation, false, true));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'users');
        $second = $this->worker('owner_accept', $this->ownerAcceptancePayload($secondInvitation));
        $this->assertWaitingOnRowLock($second, 'users');
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame(201, $results[0]['status']);
        self::assertSame(409, $results[1]['status']);
        self::assertSame('cnpj_company_conflict', $results[1]['code']);
        self::assertSame(1, ErpCompany::query()->where('cnpj', $cnpj)->count());
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $results[0]['company_id'])->where('user_id', $owner->id)->where('role', 'owner')->count());
        self::assertSame(1, ErpSubscription::query()->where('erp_company_id', $results[0]['company_id'])->count());
        self::assertSame('consumed', OfficeErpOwnerInvitation::query()->findOrFail($firstInvitation['invitation_id'])->status);
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($secondInvitation['invitation_id'])->status);
        self::assertSame('accepted', DB::table('office_erp_contracts')->where('id', $firstInvitation['contract_id'])->value('agreement_status'));
        self::assertSame('awaiting_owner', DB::table('office_erp_contracts')->where('id', $secondInvitation['contract_id'])->value('agreement_status'));
        self::assertSame('occupied', DB::table('office_erp_licence_allocations')->where('contract_id', $firstInvitation['contract_id'])->value('state'));
        self::assertSame('reserved', DB::table('office_erp_licence_allocations')->where('contract_id', $secondInvitation['contract_id'])->value('state'));
        self::assertSame(0, DB::table('office_erp_commercial_operations')->where('action', 'owner.accept')->where('account_id', $secondInvitation['account_id'])->count());
    }

    public function test_allowance_removal_wins_plan_lock_before_owner_acceptance(): void
    {
        $office = $this->commercialFixture(2);
        $owner = User::factory()->create(['email' => 'plan-race-owner-'.bin2hex(random_bytes(6)).'@example.test']);
        $invitation = $this->ownerInvitationFixture($office, $owner);
        $plan = $office['account']->subscription()->firstOrFail()->plan()->firstOrFail();
        $otherContractsBefore = DB::table('office_erp_contracts')->where('account_id', $office['account_id'])->where('id', '!=', $invitation['contract_id'])->orderBy('id')->get()->toJson();
        $allocationsBefore = DB::table('office_erp_licence_allocations')->where('account_id', $office['account_id'])->where('contract_id', '!=', $invitation['contract_id'])->orderBy('id')->get()->toJson();
        $admin = User::factory()->create(['is_super_admin' => true]);
        $removal = $this->worker('remove_allowance', ['plan_id' => (int) $plan->id, 'admin_id' => (int) $admin->id, 'hold_plan_lock' => true]);
        $this->waitFor($removal, 'held');
        $this->assertWorkerHeldLock($removal, 'plans');
        $acceptance = $this->worker('owner_accept', $this->ownerAcceptancePayload($invitation));
        $this->assertWaitingOnRowLock($acceptance, 'plans');
        $this->release($removal);
        $removalResult = $this->workerResult($removal);
        $acceptanceResult = $this->workerResult($acceptance);

        self::assertSame(200, $removalResult['status']);
        self::assertSame(403, $acceptanceResult['status']);
        self::assertSame('resale_disabled', $acceptanceResult['code']);
        self::assertSame(0, ErpCompany::query()->where('cnpj', $invitation['cnpj'])->count());
        self::assertSame(0, ErpCompanyMembership::query()->where('user_id', $owner->id)->count());
        self::assertNull(DB::table('office_erp_contracts')->where('id', $invitation['contract_id'])->value('erp_company_id'));
        self::assertSame('awaiting_owner', DB::table('office_erp_contracts')->where('id', $invitation['contract_id'])->value('agreement_status'));
        self::assertSame('reserved', DB::table('office_erp_licence_allocations')->where('contract_id', $invitation['contract_id'])->value('state'));
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($invitation['invitation_id'])->status);
        self::assertSame($otherContractsBefore, DB::table('office_erp_contracts')->where('account_id', $office['account_id'])->where('id', '!=', $invitation['contract_id'])->orderBy('id')->get()->toJson());
        self::assertSame($allocationsBefore, DB::table('office_erp_licence_allocations')->where('account_id', $office['account_id'])->where('contract_id', '!=', $invitation['contract_id'])->orderBy('id')->get()->toJson());
    }

    public function test_last_slot_reservation_is_won_by_exactly_one_independent_worker(): void
    {
        $fixture = $this->commercialFixture(1);
        [$firstContractId, $secondContractId] = $fixture['contract_ids'];

        $first = $this->worker('reserve', $this->reservationPayload($fixture, $firstContractId, true));
        $this->waitFor($first, 'held');
        $held = $this->signalData($first, 'held');
        self::assertSame($this->signalData($first, 'ready')['database_pid'], $held['database_pid']);
        self::assertGreaterThan(0, $held['transaction_level']);
        self::assertStringContainsString('for update', strtolower($held['lock_query']));
        $second = $this->worker('reserve', $this->reservationPayload($fixture, $secondContractId));
        $this->assertWaitingOnAccountLock($second);

        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(409, $secondResult['status']);
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);
        $allocations = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpLicenceAllocation::class)
                ->whereIn('contract_id', [$firstContractId, $secondContractId])
                ->orderBy('id')
                ->get(),
        ));
        self::assertCount(1, $allocations);
        $allocation = $allocations->sole();
        self::assertSame($firstContractId, (int) $allocation->contract_id);
        self::assertSame((int) $allocation->id, $firstResult['allocation_id']);
        self::assertSame('reserved', $allocation->state);
        self::assertTrue($allocation->expires_at->isFuture());
        self::assertSame(0, OfficeErpLicenceAllocation::query()
            ->whereIn('contract_id', [$firstContractId, $secondContractId])
            ->where('account_id', '!=', $fixture['account_id'])
            ->count());

        $usage = $this->officeAdmin($fixture, fn (): array => $fixture['context']->run(
            fn (): array => app(OfficeErpAdmission::class)->usage($fixture['context']),
        ));
        self::assertSame(['reserved' => 1, 'occupied' => 0], $usage);
        $secondContract = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpContract::class)
                ->whereKey($secondContractId)
                ->firstOrFail()
                ->getAttributes(),
        ));
        self::assertSame($fixture['second_contract_snapshot'], $secondContract);
    }

    public function test_same_period_workers_return_the_same_invoice_and_reserve_one_number(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $contract = $fixture['contracts'][0];
        $period = $fixture['invoice_period'];
        self::assertSame(0, OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)->count());
        self::assertNull(OfficeErpRecords::query($fixture['context'], OfficeErpInvoiceCounter::class)
            ->where('year', $fixture['invoice_year'])->value('next_number'));
        $first = $this->worker('issue_period', $this->invoicePayload($fixture, $contract, 'race-period-first', true));
        $this->waitFor($first, 'held');
        $held = $this->signalData($first, 'held');
        self::assertSame($this->signalData($first, 'ready')['database_pid'], $held['database_pid']);
        self::assertGreaterThan(0, $held['transaction_level']);
        self::assertStringContainsString('from "accounts"', strtolower($held['lock_query']));
        $second = $this->worker('issue_period', $this->invoicePayload($fixture, $contract, 'race-period-second'));
        $this->assertWaitingOnAccountLock($second);

        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(201, $secondResult['status']);
        self::assertSame($firstResult['invoice_id'], $secondResult['invoice_id']);
        self::assertSame($firstResult['number'], $secondResult['number']);
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);

        $invoices = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)
                ->where('contract_id', $contract->id)
                ->where('kind', 'recurring')
                ->whereDate('period_start', $period['start'])
                ->whereDate('period_end', $period['end'])
                ->get(),
        ));
        self::assertCount(1, $invoices);
        self::assertSame($firstResult['invoice_id'], (int) $invoices->sole()->id);

        $operations = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpCommercialOperation::class)
                ->whereIn('idempotency_key', ['race-period-first', 'race-period-second'])
                ->orderBy('id')
                ->get(),
        ));
        self::assertCount(2, $operations);
        self::assertNotSame((int) $operations[0]->id, (int) $operations[1]->id);
        self::assertEqualsCanonicalizing(
            [$firstResult['operation_id'], $secondResult['operation_id']],
            $operations->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
        foreach ($operations as $operation) {
            self::assertSame('invoice.issue', $operation->action);
            self::assertSame($firstResult['invoice_id'], (int) data_get($operation->outcome, 'data.id'));
        }
        self::assertSame($firstResult['invoice_id'], (int) data_get($firstResult['operation_outcome'], 'data.id'));
        self::assertSame($firstResult['invoice_id'], (int) data_get($secondResult['operation_outcome'], 'data.id'));

        $audits = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpAuditEvent::class)
                ->whereIn('operation_id', $operations->pluck('id'))
                ->get(),
        ));
        self::assertCount(2, $audits);
        self::assertEqualsCanonicalizing(
            $operations->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            $audits->pluck('operation_id')->map(static fn ($id): int => (int) $id)->all(),
        );
        foreach ($audits as $audit) {
            self::assertSame($firstResult['invoice_id'], (int) data_get($audit->metadata, 'outcome.data.id'));
        }
        $counter = OfficeErpRecords::query($fixture['context'], OfficeErpInvoiceCounter::class)
            ->where('year', $fixture['invoice_year']);
        self::assertSame(1, (clone $counter)->count());
        self::assertSame(2, $counter->value('next_number'));
    }

    public function test_different_contracts_reserve_distinct_sequential_numbers_under_contention(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        [$firstContract, $secondContract] = $fixture['contracts'];
        $period = $fixture['invoice_period'];
        self::assertSame('effective', $firstContract->agreement_status);
        self::assertSame('effective', $secondContract->agreement_status);
        self::assertSame($period['start'], $firstContract->service_anchor_on->toDateString());
        self::assertSame($period['start'], $secondContract->service_anchor_on->toDateString());
        self::assertSame(0, OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)->count());
        self::assertNull(OfficeErpRecords::query($fixture['context'], OfficeErpInvoiceCounter::class)
            ->where('year', $fixture['invoice_year'])->value('next_number'));
        $first = $this->worker('issue_period', $this->invoicePayload($fixture, $firstContract, 'race-number-first', true));
        $this->waitFor($first, 'held');
        $held = $this->signalData($first, 'held');
        self::assertStringContainsString('from "accounts"', strtolower($held['lock_query']));
        $second = $this->worker('issue_period', $this->invoicePayload($fixture, $secondContract, 'race-number-second'));
        $this->assertWaitingOnAccountLock($second);

        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(201, $secondResult['status']);
        self::assertNotSame($firstResult['invoice_id'], $secondResult['invoice_id']);
        self::assertNotSame($firstResult['number'], $secondResult['number']);
        self::assertSame('ERP-'.$fixture['invoice_year'].'-000001', $firstResult['number']);
        self::assertSame('ERP-'.$fixture['invoice_year'].'-000002', $secondResult['number']);
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);

        $invoices = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)
                ->whereIn('contract_id', [$firstContract->id, $secondContract->id])
                ->where('kind', 'recurring')
                ->whereDate('period_start', $period['start'])
                ->whereDate('period_end', $period['end'])
                ->orderBy('number')
                ->get(),
        ));
        self::assertCount(2, $invoices);
        self::assertSame([$firstContract->id, $secondContract->id], $invoices->pluck('contract_id')->map(static fn ($id): int => (int) $id)->all());
        self::assertSame([$firstResult['number'], $secondResult['number']], $invoices->pluck('number')->all());
        $counter = OfficeErpRecords::query($fixture['context'], OfficeErpInvoiceCounter::class)
            ->where('year', $fixture['invoice_year']);
        self::assertSame(1, (clone $counter)->count());
        self::assertSame(3, $counter->value('next_number'));
    }

    public function test_credit_and_receipt_contention_keeps_invoice_balance_nonnegative(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $invoice = $this->issueRaceInvoice($fixture, $fixture['contracts'][0]);
        $first = $this->worker('adjust', $this->financialPayload($fixture, [
            'invoice_id' => (int) $invoice->id,
            'adjustment' => ['direction' => 'credit', 'amount' => '70.00', 'reason' => 'Race credit'],
            'idempotency_key' => 'race-credit-first',
            'hold_invoice_lock' => true,
        ]));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'office_erp_invoices');
        $second = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => $this->receiptInput($invoice, '40.00'),
            'idempotency_key' => 'race-credit-receipt-second',
        ]));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(409, $secondResult['status']);
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);
        $this->assertInvoiceLedger($fixture, (int) $invoice->id, '30.00', '0.00', '30.00');
        self::assertSame(1, OfficeErpInvoiceAdjustment::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(0, OfficeErpReceipt::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('account_id', $fixture['account_id'])->whereIn('idempotency_key', ['race-credit-first', 'race-credit-receipt-second'])->count());
        $this->assertAuditedOperations($fixture, ['race-credit-first'], 1);
    }

    public function test_concurrent_receipts_do_not_overallocate_one_invoice(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $invoice = $this->issueRaceInvoice($fixture, $fixture['contracts'][0]);
        $first = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => $this->receiptInput($invoice, '70.00'),
            'idempotency_key' => 'race-allocation-first',
            'hold_invoice_lock' => true,
        ]));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'office_erp_invoices');
        $second = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => $this->receiptInput($invoice, '70.00'),
            'idempotency_key' => 'race-allocation-second',
        ]));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(409, $secondResult['status']);
        $this->assertInvoiceLedger($fixture, (int) $invoice->id, '100.00', '70.00', '30.00');
        self::assertSame(1, OfficeErpReceipt::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, OfficeErpReceiptAllocation::query()->where('invoice_id', $invoice->id)->count());
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('account_id', $fixture['account_id'])->where('idempotency_key', 'race-allocation-first')->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $fixture['account_id'])->where('idempotency_key', 'race-allocation-second')->count());
        $this->assertAuditedOperations($fixture, ['race-allocation-first'], 1);
    }

    public function test_receipt_reversal_and_new_allocation_preserve_one_effective_payment(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $invoice = $this->issueRaceInvoice($fixture, $fixture['contracts'][0]);
        $original = $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => app(OfficeErpReceipts::class)->post($fixture['context'], $this->receiptInput($invoice, '100.00'), 'race-reversal-original'),
        ));
        $first = $this->worker('reverse', $this->financialPayload($fixture, [
            'receipt_id' => (int) $original->id,
            'reason' => 'Correct original receipt',
            'idempotency_key' => 'race-reversal-first',
            'hold_invoice_lock' => true,
        ]));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'office_erp_invoices');
        $second = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => $this->receiptInput($invoice, '100.00'),
            'idempotency_key' => 'race-reversal-receipt-second',
        ]));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $firstResult = $this->workerResult($first);
        $secondResult = $this->workerResult($second);

        self::assertSame(201, $firstResult['status']);
        self::assertSame(201, $secondResult['status']);
        self::assertSame(1, OfficeErpReceiptReversal::query()->where('receipt_id', $original->id)->count());
        self::assertSame(2, OfficeErpReceipt::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(2, OfficeErpReceiptAllocation::query()->where('invoice_id', $invoice->id)->count());
        $this->assertInvoiceLedger($fixture, (int) $invoice->id, '100.00', '100.00', '0.00');
        self::assertSame(0, $firstResult['transaction_level']);
        self::assertSame(0, $secondResult['transaction_level']);
        $this->assertAuditedOperations($fixture, ['race-reversal-original', 'race-reversal-first', 'race-reversal-receipt-second'], 3);
    }

    public function test_staged_proof_can_be_claimed_by_only_one_receipt_operation(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        [$firstInvoice, $secondInvoice] = [
            $this->issueRaceInvoice($fixture, $fixture['contracts'][0]),
            $this->issueRaceInvoice($fixture, $fixture['contracts'][1]),
        ];
        $proof = $this->stageRaceProof($fixture);
        $first = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => [...$this->receiptInput($firstInvoice, '25.00'), 'proof_id' => $proof['id']],
            'idempotency_key' => 'race-proof-first',
            'hold_proof_lock' => true,
        ]));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'office_erp_receipt_proofs');
        $second = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => [...$this->receiptInput($secondInvoice, '25.00'), 'proof_id' => $proof['id']],
            'idempotency_key' => 'race-proof-second',
        ]));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame(201, $results[0]['status']);
        self::assertSame(422, $results[1]['status']);
        $stored = OfficeErpReceiptProof::query()->findOrFail($proof['id']);
        self::assertNotNull($stored->receipt_id);
        self::assertNotNull($stored->claimed_at);
        self::assertSame(1, OfficeErpReceipt::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, OfficeErpReceiptAllocation::query()->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('account_id', $fixture['account_id'])->whereIn('idempotency_key', ['race-proof-first', 'race-proof-second'])->count());
        Storage::disk('local')->assertExists($stored->path);
        self::assertSame(0, $results[0]['transaction_level']);
        self::assertSame(0, $results[1]['transaction_level']);
        $this->assertAuditedOperations($fixture, ['race-proof-first'], 1);
    }

    public function test_claimed_proof_survives_expired_cleanup_waiting_on_its_row_lock(): void
    {
        $fixture = $this->effectiveInvoiceFixture();
        $invoice = $this->issueRaceInvoice($fixture, $fixture['contracts'][0]);
        $proof = $this->stageRaceProof($fixture);
        $otherProofMinimumExpiry = DB::table('office_erp_receipt_proofs')
            ->whereNull('receipt_id')
            ->whereNull('claimed_at')
            ->where('id', '!=', $proof['id'])
            ->min('expires_at');
        if ($otherProofMinimumExpiry === null) {
            $expiresAt = CarbonImmutable::now()->addSecond();
            $cleanupCutoff = $expiresAt->addSecond();
        } else {
            $cleanupCutoff = CarbonImmutable::parse($otherProofMinimumExpiry)->subSecond();
            $latestSafeCutoff = CarbonImmutable::now()->addSeconds(2);
            if ($cleanupCutoff->greaterThan($latestSafeCutoff)) {
                $cleanupCutoff = $latestSafeCutoff;
            }
            $expiresAt = $cleanupCutoff->subSecond();
        }
        $frozenNow = $expiresAt->subSecond();
        DB::table('office_erp_receipt_proofs')->where('id', $proof['id'])->update(['expires_at' => $expiresAt]);
        self::assertSame(false, DB::table('office_erp_receipt_proofs')
            ->whereNull('receipt_id')
            ->whereNull('claimed_at')
            ->where('id', '!=', $proof['id'])
            ->where('expires_at', '<=', $cleanupCutoff)
            ->exists(), 'Cleanup cutoff must exclude every unrelated unclaimed proof before cleanup starts.');
        $first = $this->worker('receipt', $this->financialPayload($fixture, [
            'receipt' => [
                ...$this->receiptInput($invoice, '25.00'),
                'proof_id' => $proof['id'],
                'received_on' => $frozenNow->setTimezone('America/Sao_Paulo')->toDateString(),
            ],
            'idempotency_key' => 'race-proof-cleanup-claim',
            'frozen_now' => $frozenNow->toIso8601String(),
            'hold_after_proof_claim' => true,
        ]));
        $this->waitFor($first, 'held');
        $held = $this->signalData($first, 'held');
        self::assertSame($this->signalData($first, 'ready')['database_pid'], $held['database_pid']);
        self::assertGreaterThan(0, $held['transaction_level']);
        self::assertStringContainsString('insert into "office_erp_receipt_allocations"', strtolower($held['lock_query']));
        self::assertSame(false, DB::table('office_erp_receipt_proofs')
            ->whereNull('receipt_id')
            ->whereNull('claimed_at')
            ->where('id', '!=', $proof['id'])
            ->where('expires_at', '<=', $cleanupCutoff)
            ->exists(), 'Cleanup cutoff must still exclude every unrelated unclaimed proof immediately before cleanup.');
        $second = $this->worker('proof_cleanup', $this->financialPayload($fixture, [
            'now' => $cleanupCutoff->toIso8601String(),
            'contract_id' => (int) $fixture['contracts'][0]->id,
        ]));
        $this->assertWaitingOnProofLock($second);
        $this->release($first);
        $claim = $this->workerResult($first);
        $cleanup = $this->workerResult($second);

        self::assertSame(201, $claim['status']);
        self::assertSame(0, $cleanup['deleted']);
        $stored = OfficeErpReceiptProof::query()->findOrFail($proof['id']);
        self::assertNotNull($stored->receipt_id);
        Storage::disk('local')->assertExists($stored->path);
        self::assertSame(0, $claim['transaction_level']);
        self::assertSame(0, $cleanup['transaction_level']);
        $this->assertAuditedOperations($fixture, ['race-proof-cleanup-claim'], 1);
    }

    /** @param list<string> $keys */
    private function assertAuditedOperations(array $fixture, array $keys, int $expected): void
    {
        $operationIds = OfficeErpCommercialOperation::query()
            ->where('account_id', $fixture['account_id'])
            ->whereIn('idempotency_key', $keys)
            ->pluck('id');
        self::assertCount($expected, $operationIds);
        self::assertSame($expected, OfficeErpAuditEvent::query()->whereIn('operation_id', $operationIds)->count());
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function reservationPayload(array $fixture, int $contractId, bool $holdAccountLock = false): array
    {
        return [
            'account_id' => $fixture['account_id'],
            'actor_id' => $fixture['actor_id'],
            'membership_id' => $fixture['membership_id'],
            'contract_id' => $contractId,
            'expires_at' => now()->addDay()->toIso8601String(),
            'hold_account_lock' => $holdAccountLock,
        ];
    }

    /** @param array<string, mixed> $fixture
     * @return array<string, mixed>
     */
    private function invoicePayload(array $fixture, OfficeErpContract $contract, string $key, bool $holdAccountLock = false): array
    {
        return [
            'account_id' => $fixture['account_id'],
            'actor_id' => $fixture['actor_id'],
            'membership_id' => $fixture['membership_id'],
            'contract_id' => (int) $contract->id,
            'revision_id' => (int) $contract->current_revision_id,
            'period' => $fixture['invoice_period'],
            'idempotency_key' => $key,
            'hold_account_lock' => $holdAccountLock,
        ];
    }
}
