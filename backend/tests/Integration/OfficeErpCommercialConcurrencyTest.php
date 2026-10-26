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
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\BrazilianTaxId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OfficeErpCommercialConcurrencyTest extends OfficeErpCommercialProcessTestCase
{
    public function test_cancellation_winning_account_lock_stops_the_next_recurring_period(): void
    {
        $fixture = $this->recurringRaceFixture();
        $contract = $fixture['contracts'][0];
        $cancelKey = 'race-cancel-wins-'.bin2hex(random_bytes(6));
        $cancellation = $this->worker('cancel_contract', $this->cancellationPayload($fixture, $contract, $cancelKey, true));
        $this->waitFor($cancellation, 'held');
        $this->assertWorkerHeldLock($cancellation, 'accounts');
        $generation = $this->worker('generate_recurring', $this->recurringPayload($fixture, $contract));
        $this->assertWaitingOnAccountLock($generation);
        $this->release($cancellation);
        $cancelResult = $this->workerResult($cancellation);
        $generationResult = $this->workerResult($generation);

        self::assertSame(201, $cancelResult['status']);
        self::assertSame('canceled', $cancelResult['agreement_status']);
        self::assertSame($fixture['recurring_period']['start'], $cancelResult['cancel_effective_on']);
        self::assertSame(200, $generationResult['status']);
        self::assertSame([], $generationResult['generated']);
        self::assertFalse($generationResult['pending']);
        self::assertSame(2, (int) DB::table('office_erp_contracts')->where('id', $contract->id)->value('next_period_index'));
        self::assertSame(1, (int) DB::table('office_erp_contracts')->where('id', $contract->id)->value('next_period_month_offset'));
        self::assertSame('released', DB::table('office_erp_licence_allocations')->where('contract_id', $contract->id)->value('state'));
        self::assertSame('canceled', ErpSubscription::query()->where('erp_company_id', $contract->erp_company_id)->value('status'));
        self::assertSame(1, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
        self::assertSame($fixture['initial_invoice_snapshot'], OfficeErpInvoice::query()->findOrFail($fixture['initial_invoice_id'])->getAttributes());
        self::assertSame(0, OfficeErpInvoice::query()->where('contract_id', $contract->id)->whereDate('period_start', $fixture['recurring_period']['start'])->count());
        self::assertSame(1, DB::table('office_erp_commercial_operations')->where('action', 'contract.cancel')->where('idempotency_key', $cancelKey)->count());
        self::assertNull($generationResult['tenant_account_id']);
    }

    public function test_generation_winning_account_lock_issues_period_before_cancellation(): void
    {
        $fixture = $this->recurringRaceFixture();
        $contract = $fixture['contracts'][0];
        $cancelKey = 'race-generate-wins-'.bin2hex(random_bytes(6));
        $generation = $this->worker('generate_recurring', $this->recurringPayload($fixture, $contract, true));
        $this->waitFor($generation, 'held');
        $this->assertWorkerHeldLock($generation, 'accounts');
        $cancellation = $this->worker('cancel_contract', $this->cancellationPayload($fixture, $contract, $cancelKey));
        $this->assertWaitingOnAccountLock($cancellation);
        $this->release($generation);
        $generationResult = $this->workerResult($generation);
        $cancelResult = $this->workerResult($cancellation);

        self::assertSame(200, $generationResult['status']);
        self::assertCount(1, $generationResult['generated']);
        self::assertFalse($generationResult['pending']);
        self::assertSame(3, $generationResult['next_period_index']);
        self::assertSame(2, $generationResult['next_period_month_offset']);
        self::assertSame(201, $cancelResult['status']);
        self::assertSame('canceled', $cancelResult['agreement_status']);
        self::assertSame('canceled', DB::table('office_erp_contracts')->where('id', $contract->id)->value('agreement_status'));
        self::assertSame('released', DB::table('office_erp_licence_allocations')->where('contract_id', $contract->id)->value('state'));
        self::assertSame('canceled', ErpSubscription::query()->where('erp_company_id', $contract->erp_company_id)->value('status'));
        self::assertSame($fixture['initial_invoice_snapshot'], OfficeErpInvoice::query()->findOrFail($fixture['initial_invoice_id'])->getAttributes());
        $generatedInvoice = OfficeErpInvoice::query()->where('contract_id', $contract->id)->whereDate('period_start', $fixture['recurring_period']['start'])->firstOrFail();
        self::assertSame($generationResult['generated'][0], (int) $generatedInvoice->id);
        self::assertSame('issued', $generatedInvoice->document_status);
        self::assertSame(2, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
        self::assertSame(1, DB::table('office_erp_commercial_operations')->where('action', 'contract.cancel')->where('idempotency_key', $cancelKey)->count());
        self::assertNull($generationResult['tenant_account_id']);
    }

    public function test_overlapping_recurring_generators_issue_one_period_and_advance_once(): void
    {
        $fixture = $this->recurringRaceFixture();
        $contract = $fixture['contracts'][0];
        $first = $this->worker('generate_recurring', $this->recurringPayload($fixture, $contract, true));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'accounts');
        $second = $this->worker('generate_recurring', $this->recurringPayload($fixture, $contract));
        $this->assertWaitingOnAccountLock($second);
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame(200, $results[0]['status']);
        self::assertSame(200, $results[1]['status']);
        self::assertCount(1, $results[0]['generated']);
        self::assertSame([], $results[1]['generated']);
        self::assertSame([3, 3], array_column($results, 'next_period_index'));
        self::assertSame([2, 2], array_column($results, 'next_period_month_offset'));
        self::assertSame([false, false], array_column($results, 'pending'));
        self::assertSame([null, null], array_column($results, 'tenant_account_id'));
        self::assertSame(2, OfficeErpInvoice::query()->where('contract_id', $contract->id)->count());
        $issued = OfficeErpInvoice::query()->where('contract_id', $contract->id)->whereDate('period_start', $fixture['recurring_period']['start'])->get();
        self::assertCount(1, $issued);
        self::assertSame($results[0]['generated'][0], (int) $issued->sole()->id);
        self::assertSame(sprintf('ERP-%d-%06d', $fixture['recurring_invoice_year'], $fixture['recurring_invoice_sequence']), $issued->sole()->number);
        self::assertSame($fixture['initial_invoice_snapshot'], OfficeErpInvoice::query()->findOrFail($fixture['initial_invoice_id'])->getAttributes());
        self::assertSame(1, DB::table('office_erp_commercial_operations')
            ->where('action', 'billing.generate')
            ->where('idempotency_key', 'recurring:'.$contract->id.':'.$fixture['recurring_period']['start'].':'.$fixture['recurring_period']['end'])
            ->count());
    }

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
        $subscriptions = ErpSubscription::query()->where('erp_company_id', $results[0]['company_id'])->get();
        self::assertCount(1, $subscriptions);
        self::assertSame('pending', $subscriptions->sole()->status);
        self::assertNull($subscriptions->sole()->office_erp_contract_id);
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

    public function test_owner_setup_accept_winning_company_lock_rejects_reissue_without_financial_changes(): void
    {
        $office = $this->commercialFixture(2);
        $setup = $this->ownerSetupFixture($office);
        $subscriptionBefore = ErpSubscription::query()->findOrFail($setup['subscription_id'])->getAttributes();
        $allocationBefore = DB::table('office_erp_licence_allocations')->where('id', $setup['allocation_id'])->first();
        $invoiceRowsBefore = DB::table('office_erp_invoices')->orderBy('id')->get()->toJson();
        $allocationCount = DB::table('office_erp_licence_allocations')->count();
        $invoiceCount = DB::table('office_erp_invoices')->count();

        $acceptance = $this->worker('owner_setup_accept', $this->ownerSetupAcceptPayload($setup, holdCompanyLock: true));
        $this->waitFor($acceptance, 'held');
        $this->assertWorkerHeldLock($acceptance, 'erp_companies');
        $reissue = $this->worker('owner_setup_issue', $this->ownerSetupIssuePayload($setup));
        $this->assertWaitingOnTableLock($reissue, 'accounts');

        $this->release($acceptance);
        $acceptanceResult = $this->workerResult($acceptance);
        $reissueResult = $this->workerResult($reissue);

        self::assertSame(201, $acceptanceResult['status']);
        self::assertSame(409, $reissueResult['status']);
        self::assertSame('company_owner_already_claimed', $reissueResult['code']);
        self::assertSame($setup['company_id'], $acceptanceResult['company_id']);
        self::assertSame('claimed', ErpCompany::query()->findOrFail($setup['company_id'])->owner_setup_status);
        self::assertSame(1, User::query()->where('email', $setup['owner']['email'])->count());
        $ownerMembership = ErpCompanyMembership::query()->with('user')->where('erp_company_id', $setup['company_id'])->where('role', 'owner')->firstOrFail();
        self::assertSame($setup['owner']['email'], $ownerMembership->user->email);
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $setup['company_id'])->where('role', 'owner')->count());
        self::assertSame('consumed', OfficeErpOwnerInvitation::query()->findOrFail($setup['invitation_id'])->status);
        self::assertSame($subscriptionBefore, ErpSubscription::query()->findOrFail($setup['subscription_id'])->getAttributes());
        self::assertSame($setup['subscription_id'], (int) ErpCompany::query()->findOrFail($setup['company_id'])->subscription->id);
        self::assertSame($allocationBefore, DB::table('office_erp_licence_allocations')->where('id', $setup['allocation_id'])->first());
        self::assertSame($allocationCount, DB::table('office_erp_licence_allocations')->count());
        self::assertSame($invoiceCount, DB::table('office_erp_invoices')->count());
        self::assertSame($invoiceRowsBefore, DB::table('office_erp_invoices')->orderBy('id')->get()->toJson());
    }

    public function test_owner_setup_reissue_winning_company_lock_rejects_old_accept_without_financial_changes(): void
    {
        $office = $this->commercialFixture(2);
        $setup = $this->ownerSetupFixture($office);
        $subscriptionBefore = ErpSubscription::query()->findOrFail($setup['subscription_id'])->getAttributes();
        $allocationBefore = DB::table('office_erp_licence_allocations')->where('id', $setup['allocation_id'])->first();
        $invoiceRowsBefore = DB::table('office_erp_invoices')->orderBy('id')->get()->toJson();
        $allocationCount = DB::table('office_erp_licence_allocations')->count();
        $invoiceCount = DB::table('office_erp_invoices')->count();

        $reissue = $this->worker('owner_setup_issue', $this->ownerSetupIssuePayload($setup, holdCompanyLock: true));
        $this->waitFor($reissue, 'held');
        $this->assertWorkerHeldLock($reissue, 'erp_companies');
        $acceptance = $this->worker('owner_setup_accept', $this->ownerSetupAcceptPayload($setup));
        $this->assertWaitingOnTableLock($acceptance, 'accounts');

        $this->release($reissue);
        $reissueResult = $this->workerResult($reissue);
        $acceptanceResult = $this->workerResult($acceptance);

        self::assertSame(201, $reissueResult['status']);
        self::assertSame(409, $acceptanceResult['status']);
        self::assertSame('owner_setup_not_pending', $acceptanceResult['code']);
        self::assertSame('unclaimed', ErpCompany::query()->findOrFail($setup['company_id'])->owner_setup_status);
        self::assertSame(0, User::query()->where('email', $setup['owner']['email'])->count());
        self::assertSame(0, ErpCompanyMembership::query()->where('erp_company_id', $setup['company_id'])->where('role', 'owner')->count());
        self::assertSame('revoked', OfficeErpOwnerInvitation::query()->findOrFail($setup['invitation_id'])->status);
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($reissueResult['invitation_id'])->status);
        self::assertSame($subscriptionBefore, ErpSubscription::query()->findOrFail($setup['subscription_id'])->getAttributes());
        self::assertSame($setup['subscription_id'], (int) ErpCompany::query()->findOrFail($setup['company_id'])->subscription->id);
        self::assertSame($allocationBefore, DB::table('office_erp_licence_allocations')->where('id', $setup['allocation_id'])->first());
        self::assertSame($allocationCount, DB::table('office_erp_licence_allocations')->count());
        self::assertSame($invoiceCount, DB::table('office_erp_invoices')->count());
        self::assertSame($invoiceRowsBefore, DB::table('office_erp_invoices')->orderBy('id')->get()->toJson());
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

    public function test_trial_last_licence_has_one_winner(): void
    {
        $fixture = $this->commercialFixture(1);
        $trialLink = $this->trialLinkFixture($fixture);
        $tag = bin2hex(random_bytes(6));
        $firstCnpj = $this->uniqueCnpj();
        $secondCnpj = $this->uniqueCnpj();
        $clientCount = DB::table('clients')->where('account_id', $fixture['account_id'])->count();
        $contractCount = DB::table('office_erp_contracts')->where('account_id', $fixture['account_id'])->count();
        $occupiedCount = DB::table('office_erp_licence_allocations')->where('account_id', $fixture['account_id'])->where('state', 'occupied')->count();
        self::assertNotSame($firstCnpj, $secondCnpj);

        $first = $this->worker('trial_register', $this->trialRegistrationPayload($fixture, $trialLink, $firstCnpj, $tag.'-last-slot-a', true));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'accounts');
        $second = $this->worker('trial_register', $this->trialRegistrationPayload($fixture, $trialLink, $secondCnpj, $tag.'-last-slot-b'));
        $this->assertWaitingOnTableLock($second, 'accounts');

        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];
        $statuses = array_column($results, 'status');
        sort($statuses);
        $loserCnpj = $results[0]['status'] === 201 ? $secondCnpj : $firstCnpj;

        self::assertSame([201, 409], $statuses);
        self::assertSame(1, DB::table('erp_subscription_trials')->whereIn('cnpj', [$firstCnpj, $secondCnpj])->count());
        self::assertSame($occupiedCount + 1, DB::table('office_erp_licence_allocations')->where('account_id', $fixture['account_id'])->where('state', 'occupied')->count());
        self::assertSame($clientCount + 1, DB::table('clients')->where('account_id', $fixture['account_id'])->count());
        self::assertSame($contractCount + 1, DB::table('office_erp_contracts')->where('account_id', $fixture['account_id'])->count());
        self::assertSame(1, DB::table('erp_companies')->whereIn('cnpj', [$firstCnpj, $secondCnpj])->count());
        self::assertSame(1, DB::table('erp_subscriptions')->whereIn('erp_company_id', DB::table('erp_companies')->select('id')->whereIn('cnpj', [$firstCnpj, $secondCnpj]))->count());
        self::assertSame(0, DB::table('erp_companies')->where('cnpj', $loserCnpj)->count());
        self::assertSame(0, DB::table('erp_subscription_trials')->where('cnpj', $loserCnpj)->count());
    }

    public function test_trial_catalog_lock_serializes_legacy_basic_registration_without_deadlock(): void
    {
        $office = $this->commercialFixture(1);
        $basicPlan = ErpPlan::query()->firstOrCreate(
            ['slug' => 'basic'],
            ['name' => 'Basic race plan', 'quotas' => null],
        );
        if (! $basicPlan->modules()->where('module_key', 'purchases')->exists()) {
            $basicPlan->modules()->create(['module_key' => 'purchases', 'quota' => null]);
        }
        $basicModules = $basicPlan->modules()->orderBy('module_key')->pluck('module_key')->all();
        self::assertContains('purchases', $basicModules);
        $officeSubscription = Subscription::query()->where('account_id', $office['account_id'])->firstOrFail();
        $officePlan = Plan::query()->findOrFail($officeSubscription->plan_id);
        $allowance = $officePlan->erp_commercial;
        $allowance['module_keys'] = $basicModules;
        $officePlan->forceFill(['erp_commercial' => $allowance])->save();
        $trialLink = $this->trialLinkFixture($office, $basicPlan);
        $trialCnpj = $this->uniqueCnpj();
        $legacyCnpj = $this->uniqueCnpj();

        $trial = $this->worker('trial_register', $this->trialRegistrationPayload(
            $office,
            $trialLink,
            $trialCnpj,
            'catalog-trial-'.bin2hex(random_bytes(4)),
            holdAdvisoryLock: 'erp-commercial-catalog',
        ));
        $this->waitFor($trial, 'held');
        $this->assertWorkerHeldAdvisoryLock($trial);

        $legacy = $this->worker('erp_register', [
            'registration' => [
                'name' => 'Legacy race owner',
                'email' => 'legacy-race-'.bin2hex(random_bytes(5)).'@example.test',
                'password' => 'Legacy-Race-Password-123',
                'company_name' => 'Legacy race company',
                'cnpj' => $legacyCnpj,
            ],
        ]);
        $this->assertWaitingOnAdvisoryLock($legacy);

        $this->release($trial);
        $trialResult = $this->workerResult($trial);
        $legacyResult = $this->workerResult($legacy);

        self::assertSame(201, $trialResult['status']);
        self::assertSame(201, $legacyResult['status']);
        self::assertSame((int) $basicPlan->id, $legacyResult['plan_id']);
        self::assertSame(1, DB::table('erp_subscription_trials')->where('cnpj', $trialCnpj)->count());
        self::assertSame(1, DB::table('erp_companies')->where('cnpj', $trialCnpj)->count());
        self::assertSame(1, DB::table('erp_companies')->where('cnpj', $legacyCnpj)->count());
        self::assertSame((int) $basicPlan->id, (int) DB::table('erp_subscriptions')
            ->where('erp_company_id', DB::table('erp_companies')->where('cnpj', $trialCnpj)->value('id'))
            ->value('erp_plan_id'));
        self::assertSame((int) $basicPlan->id, (int) DB::table('erp_subscriptions')
            ->where('erp_company_id', DB::table('erp_companies')->where('cnpj', $legacyCnpj)->value('id'))
            ->value('erp_plan_id'));
    }

    public function test_same_cnpj_across_offices_has_one_trial(): void
    {
        $firstOffice = $this->commercialFixture(1);
        $secondOffice = $this->commercialFixture(1);
        $firstLink = $this->trialLinkFixture($firstOffice);
        $secondLink = $this->trialLinkFixture($secondOffice);
        $cnpj = $this->uniqueCnpj();
        $firstOfficeClientCount = DB::table('clients')->where('account_id', $firstOffice['account_id'])->count();
        $secondOfficeClientCount = DB::table('clients')->where('account_id', $secondOffice['account_id'])->count();
        $firstOfficeOccupiedCount = DB::table('office_erp_licence_allocations')->where('account_id', $firstOffice['account_id'])->where('state', 'occupied')->count();

        $first = $this->worker('trial_register', $this->trialRegistrationPayload(
            $firstOffice,
            $firstLink,
            $cnpj,
            'same-cnpj-a-'.bin2hex(random_bytes(4)),
            holdAdvisoryLock: 'erp-company:'.$cnpj,
        ));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldAdvisoryLock($first);
        $second = $this->worker('trial_register', $this->trialRegistrationPayload(
            $secondOffice,
            $secondLink,
            $cnpj,
            'same-cnpj-b-'.bin2hex(random_bytes(4)),
        ));
        $this->assertWaitingOnAdvisoryLock($second);

        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];
        self::assertSame([201, 409], array_column($results, 'status'));
        self::assertSame('trial_already_used', $results[1]['code']);
        self::assertSame(1, DB::table('erp_subscription_trials')->where('cnpj', $cnpj)->count());
        self::assertSame(1, DB::table('erp_companies')->where('cnpj', $cnpj)->count());
        self::assertSame(1, DB::table('erp_subscriptions')->whereIn('erp_company_id', DB::table('erp_companies')->select('id')->where('cnpj', $cnpj))->count());
        self::assertSame($firstOfficeClientCount + 1, DB::table('clients')->where('account_id', $firstOffice['account_id'])->count());
        self::assertSame($secondOfficeClientCount, DB::table('clients')->where('account_id', $secondOffice['account_id'])->count());
        self::assertSame($firstOfficeOccupiedCount + 1, DB::table('office_erp_licence_allocations')->where('account_id', $firstOffice['account_id'])->where('state', 'occupied')->count());
        self::assertSame(0, DB::table('office_erp_licence_allocations')->where('account_id', $secondOffice['account_id'])->count());
    }

    public function test_rotation_wins_before_registration_commit(): void
    {
        $office = $this->commercialFixture(1);
        $trialLink = $this->trialLinkFixture($office);
        $originalCode = $trialLink['code'];
        $cnpj = $this->uniqueCnpj();
        $clientCount = DB::table('clients')->where('account_id', $office['account_id'])->count();
        $contractCount = DB::table('office_erp_contracts')->where('account_id', $office['account_id'])->count();
        $allocationCount = DB::table('office_erp_licence_allocations')->where('account_id', $office['account_id'])->count();
        $invoiceCount = DB::table('office_erp_invoices')->where('account_id', $office['account_id'])->count();
        $registration = $this->worker('trial_register', $this->trialRegistrationPayload(
            $office,
            $trialLink,
            $cnpj,
            'rotation-'.bin2hex(random_bytes(4)),
            holdProvider: true,
        ));
        $this->waitFor($registration, 'provider-held');

        $rotation = $this->worker('trial_rotate', $this->trialRotationPayload(
            $office,
            $trialLink,
            bin2hex(random_bytes(4)),
            holdTrialLinkLock: true,
        ));
        $this->waitFor($rotation, 'held');
        $this->assertWorkerHeldLock($rotation, 'office_erp_trial_links');
        $this->release($rotation);
        $rotationResult = $this->workerResult($rotation);
        $this->release($registration, 'provider-release');
        $registrationResult = $this->workerResult($registration);

        self::assertSame(201, $rotationResult['status']);
        self::assertSame(409, $registrationResult['status']);
        self::assertSame('trial_link_changed', $registrationResult['code']);
        self::assertNotSame($originalCode, $trialLink['link']->fresh()->public_code);
        self::assertSame(2, (int) $trialLink['link']->fresh()->revision);
        self::assertSame($clientCount, DB::table('clients')->where('account_id', $office['account_id'])->count());
        self::assertSame($contractCount, DB::table('office_erp_contracts')->where('account_id', $office['account_id'])->count());
        self::assertSame($allocationCount, DB::table('office_erp_licence_allocations')->where('account_id', $office['account_id'])->count());
        self::assertSame(0, DB::table('erp_companies')->where('cnpj', $cnpj)->count());
        self::assertSame(0, DB::table('erp_subscription_trials')->where('cnpj', $cnpj)->count());
        self::assertSame($invoiceCount, DB::table('office_erp_invoices')->where('account_id', $office['account_id'])->count());
    }

    public function test_expiry_and_paid_conversion_serialize(): void
    {
        $office = $this->commercialFixture(1);
        $trialLink = $this->trialLinkFixture($office);
        $cnpj = $this->uniqueCnpj();
        $startedAt = CarbonImmutable::now('UTC')->startOfMinute();
        $registration = $this->worker('trial_register', $this->trialRegistrationPayload(
            $office,
            $trialLink,
            $cnpj,
            'expiry-conversion-'.bin2hex(random_bytes(4)),
            frozenNow: $startedAt->toISOString(),
        ));
        self::assertSame(201, $this->workerResult($registration)['status']);
        $trial = DB::table('erp_subscription_trials')->where('cnpj', $cnpj)->first();
        self::assertNotNull($trial);
        $contractId = (int) DB::table('erp_subscriptions')->where('erp_company_id', $trial->erp_company_id)->value('office_erp_contract_id');
        self::assertSame(0, DB::table('office_erp_invoices')->where('contract_id', $contractId)->count());
        $expiryNow = CarbonImmutable::parse($trial->ends_at)->addSecond();
        $terms = [
            'erp_plan_id' => $trialLink['plan_id'],
            'price' => '100.00',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'start_on' => $expiryNow->setTimezone('America/Sao_Paulo')->toDateString(),
            'billing_timezone' => 'America/Sao_Paulo',
        ];

        $expiry = $this->worker('trial_expire', [
            'trial_id' => (int) $trial->id,
            'now' => $expiryNow->toISOString(),
            'hold_row_lock' => 'accounts',
        ]);
        $this->waitFor($expiry, 'held');
        $this->assertWorkerHeldLock($expiry, 'accounts');
        $conversion = $this->worker('trial_convert', $this->trialConversionPayload(
            $office,
            $contractId,
            $terms,
            'after-expiry-'.bin2hex(random_bytes(4)),
            frozenNow: $expiryNow->toISOString(),
        ));
        $this->assertWaitingOnTableLock($conversion, 'accounts');

        $this->release($expiry);
        self::assertSame(200, $this->workerResult($expiry)['status']);
        $conversionResult = $this->workerResult($conversion);

        self::assertSame(201, $conversionResult['status']);
        self::assertSame((int) $trial->erp_subscription_id, $conversionResult['subscription_id']);
        $trial = DB::table('erp_subscription_trials')->where('id', $trial->id)->firstOrFail();
        self::assertNotNull($trial->expired_at);
        self::assertNotNull($trial->converted_at);
        self::assertSame('active', DB::table('erp_subscriptions')->where('id', $trial->erp_subscription_id)->value('status'));
        self::assertSame('effective', DB::table('office_erp_contracts')->where('id', $contractId)->value('agreement_status'));
        self::assertSame('occupied', DB::table('office_erp_licence_allocations')->where('contract_id', $contractId)->value('state'));
        self::assertSame(1, DB::table('office_erp_invoices')->where('contract_id', $contractId)->count());
    }

    public function test_duplicate_conversion_has_one_first_invoice(): void
    {
        $office = $this->commercialFixture(1);
        $trialLink = $this->trialLinkFixture($office);
        $tag = bin2hex(random_bytes(4));
        $cnpj = $this->uniqueCnpj();
        $registration = $this->worker('trial_register', $this->trialRegistrationPayload(
            $office,
            $trialLink,
            $cnpj,
            'duplicate-conversion-'.$tag,
        ));
        self::assertSame(201, $this->workerResult($registration)['status']);
        $trial = DB::table('erp_subscription_trials')->where('cnpj', $cnpj)->firstOrFail();
        $contractId = (int) DB::table('erp_subscriptions')->where('erp_company_id', $trial->erp_company_id)->value('office_erp_contract_id');
        $conversionNow = CarbonImmutable::now('UTC')->startOfSecond();
        $terms = [
            'erp_plan_id' => $trialLink['plan_id'],
            'price' => '100.00',
            'currency' => 'BRL',
            'interval_months' => 1,
            'due_in_days' => 10,
            'start_on' => $conversionNow->setTimezone('America/Sao_Paulo')->toDateString(),
            'billing_timezone' => 'America/Sao_Paulo',
        ];

        $first = $this->worker('trial_convert', $this->trialConversionPayload(
            $office,
            $contractId,
            $terms,
            'duplicate-'.$tag,
            holdAccountLock: true,
            frozenNow: $conversionNow->toISOString(),
        ));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'accounts');
        $second = $this->worker('trial_convert', $this->trialConversionPayload(
            $office,
            $contractId,
            $terms,
            'duplicate-'.$tag,
            frozenNow: $conversionNow->toISOString(),
        ));
        $this->assertWaitingOnTableLock($second, 'accounts');

        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame([201, 201], array_column($results, 'status'));
        self::assertSame($results[0]['invoice_id'], $results[1]['invoice_id']);
        self::assertSame($trial->erp_subscription_id, $results[0]['subscription_id']);
        self::assertSame(1, DB::table('office_erp_invoices')->where('contract_id', $contractId)->count());
        self::assertNotNull(DB::table('erp_subscription_trials')->where('id', $trial->id)->value('converted_at'));
        self::assertSame('active', DB::table('erp_subscriptions')->where('id', $trial->erp_subscription_id)->value('status'));
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
