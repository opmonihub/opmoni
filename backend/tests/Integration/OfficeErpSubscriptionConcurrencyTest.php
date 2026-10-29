<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpCompanyRegistrations;
use App\Erp\Commercial\OfficeErpContracts;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\Fiscal\ErpFiscalAttemptAuthorization;
use App\Erp\Fiscal\ErpFiscalAttemptService;
use App\Jobs\ProcessErpFiscalAttempt;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OfficeErpSubscriptionConcurrencyTest extends OfficeErpCommercialProcessTestCase
{
    /** @var array<string, array{ids:list<int|string>,hash:string}> */
    private array $retainedBillingRows = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['office_erp_company_registrations', 'erp_company_member_invitations', 'office_erp_access_deliveries'] as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)->orderBy('id')->get();
            $this->retainedBillingRows[$table] = [
                'ids' => $rows->pluck('id')->map(static fn ($id): int|string => is_int($id) ? $id : (string) $id)->all(),
                'hash' => hash('sha256', $rows->toJson()),
            ];
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        try {
            foreach ($this->retainedBillingRows as $table => $baseline) {
                $rows = $baseline['ids'] === []
                    ? collect()
                    : DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get();
                self::assertSame($baseline['hash'], hash('sha256', $rows->toJson()), 'Pre-existing billing rows changed: '.$table);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_last_capacity_assignment_race_across_two_registered_companies_allocates_once(): void
    {
        $office = $this->commercialFixture(1);
        [$firstCompanyId, $secondCompanyId] = $this->registerCompanies($office, 2);
        $planId = $this->technicalPlan();
        $terms = $this->assignmentTerms($planId);

        $first = $this->worker('subscription_assign', $this->assignmentPayload($office, $firstCompanyId, $terms, 'subscription-last-capacity-first', true));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'accounts');
        $second = $this->worker('subscription_assign', $this->assignmentPayload($office, $secondCompanyId, $terms, 'subscription-last-capacity-second'));
        $this->assertWaitingOnRowLock($second, 'accounts');
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame([201, 409], array_column($results, 'status'));
        self::assertSame('licence_capacity_reached', $results[1]['code']);
        self::assertSame(1, DB::table('office_erp_licence_allocations')->where('account_id', $office['account_id'])->where('state', 'occupied')->count());
        self::assertSame(1, DB::table('erp_subscriptions')->whereIn('erp_company_id', [$firstCompanyId, $secondCompanyId])->whereNotNull('office_erp_contract_id')->count());
        self::assertSame(0, DB::table('office_erp_invoices')->whereIn('contract_id', DB::table('office_erp_contracts')->whereIn('erp_company_id', [$firstCompanyId, $secondCompanyId])->select('id'))->count());
    }

    public function test_duplicate_assignment_for_one_company_cannot_create_two_allocations(): void
    {
        $office = $this->commercialFixture(2);
        [$companyId] = $this->registerCompanies($office, 1);
        $terms = $this->assignmentTerms($this->technicalPlan());

        $first = $this->worker('subscription_assign', $this->assignmentPayload($office, $companyId, $terms, 'subscription-duplicate-first', true));
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'accounts');
        $second = $this->worker('subscription_assign', $this->assignmentPayload($office, $companyId, $terms, 'subscription-duplicate-second'));
        $this->assertWaitingOnRowLock($second, 'accounts');
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame([201, 409], array_column($results, 'status'));
        self::assertSame('company_already_linked', $results[1]['code']);
        self::assertSame(1, DB::table('office_erp_licence_allocations')->where('erp_company_id', $companyId)->count());
        self::assertSame(1, DB::table('office_erp_contracts')->where('erp_company_id', $companyId)->count());
        self::assertSame(0, DB::table('office_erp_invoices')->whereIn('contract_id', DB::table('office_erp_contracts')->where('erp_company_id', $companyId)->select('id'))->count());
    }

    public function test_duplicate_activation_replays_one_invoice_at_the_planned_anchor(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00 America/Sao_Paulo');
        $office = $this->commercialFixture(1);
        [$companyId] = $this->registerCompanies($office, 1);
        $terms = $this->assignmentTerms($this->technicalPlan(), '2026-11-01');
        $assigned = $this->worker('subscription_assign', $this->assignmentPayload($office, $companyId, $terms, 'subscription-activation-assignment'));
        $assignment = $this->workerResult($assigned);
        self::assertSame(201, $assignment['status']);
        $contractId = (int) $assignment['contract_id'];
        $activationPayload = [...$this->basePayload($office, $contractId), 'idempotency_key' => 'subscription-activation-duplicate', 'frozen_now' => '2026-10-28 12:00:00 America/Sao_Paulo'];
        $first = $this->worker('contract_activate', [...$activationPayload, 'hold_account_lock' => true]);
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'accounts');
        $second = $this->worker('contract_activate', $activationPayload);
        $this->assertWaitingOnRowLock($second, 'accounts');
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame([200, 200], array_column($results, 'status'));
        self::assertSame($results[0]['invoice_id'], $results[1]['invoice_id']);
        self::assertSame(1, DB::table('office_erp_invoices')->where('contract_id', $contractId)->count());
        self::assertSame('2026-11-01', DB::table('office_erp_contracts')->where('id', $contractId)->value('service_anchor_on'));
        self::assertSame(1, DB::table('office_erp_invoices')->where('contract_id', $contractId)->whereDate('period_start', '2026-11-01')->count());
        self::assertSame(1, DB::table('office_erp_commercial_operations')->where('action', 'contract.activate')->where('idempotency_key', 'subscription-activation-duplicate')->count());
    }

    public function test_only_one_replacement_can_use_a_stale_expected_pending_revision_id(): void
    {
        $office = $this->commercialFixture(1);
        [$companyId] = $this->registerCompanies($office, 1);
        $terms = $this->assignmentTerms($this->technicalPlan());
        $assigned = $this->worker('subscription_assign', $this->assignmentPayload($office, $companyId, $terms, 'subscription-replacement-assignment'));
        $assignment = $this->workerResult($assigned);
        $contractId = (int) $assignment['contract_id'];
        $activated = $this->worker('contract_activate', [...$this->basePayload($office, $contractId), 'idempotency_key' => 'subscription-replacement-activate']);
        self::assertSame(200, $this->workerResult($activated)['status']);
        $contract = OfficeErpRecords::query($office['context'], OfficeErpContract::class)->whereKey($contractId)->firstOrFail();
        $firstPending = $this->officeAdmin($office, fn () => $office['context']->run(fn () => app(OfficeErpContracts::class)->amend(
            $office['context'], $contract, ['price' => '135.00'], 'subscription-replacement-seed',
        )));
        $expected = (int) $firstPending->id;
        $termsA = ['expected_pending_revision_id' => $expected, 'price' => '140.00'];
        $termsB = ['expected_pending_revision_id' => $expected, 'price' => '150.00'];
        $first = $this->worker('contract_amend', [...$this->basePayload($office, $contractId), 'terms' => $termsA, 'idempotency_key' => 'subscription-replacement-race-first', 'hold_account_lock' => true]);
        $this->waitFor($first, 'held');
        $this->assertWorkerHeldLock($first, 'accounts');
        $second = $this->worker('contract_amend', [...$this->basePayload($office, $contractId), 'terms' => $termsB, 'idempotency_key' => 'subscription-replacement-race-second']);
        $this->assertWaitingOnRowLock($second, 'accounts');
        $this->release($first);
        $results = [$this->workerResult($first), $this->workerResult($second)];

        self::assertSame([201, 409], array_column($results, 'status'));
        self::assertSame('pending_revision_changed', $results[1]['code']);
        self::assertSame(1, DB::table('office_erp_contract_revisions')->where('contract_id', $contractId)->whereNotNull('superseded_at')->count());
        self::assertSame(1, DB::table('office_erp_commercial_operations')->whereIn('idempotency_key', ['subscription-replacement-race-first', 'subscription-replacement-race-second'])->count());
    }

    public function test_queued_fiscal_attempt_is_blocked_when_module_reduction_reaches_service_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00 America/Sao_Paulo');
        Carbon::setTestNow('2026-10-28 12:00:00 America/Sao_Paulo');
        config(['erp.operating_writes_enabled' => true, 'erp.release_enabled' => true, 'erp.release_mode' => 'production']);
        $office = $this->commercialFixture(1);
        $officePlan = $office['account']->subscription->plan;
        $allowance = $officePlan->erp_commercial;
        $allowance['module_keys'] = ['purchases', 'cte'];
        $officePlan->forceFill(['erp_commercial' => $allowance])->save();
        [$companyId] = $this->registerCompanies($office, 1);
        $technicalPlanId = $this->technicalPlan(['purchases', 'cte']);
        $assigned = $this->worker('subscription_assign', $this->assignmentPayload($office, $companyId, $this->assignmentTerms($technicalPlanId), 'subscription-queued-module-assignment'));
        $assignment = $this->workerResult($assigned);
        $contractId = (int) $assignment['contract_id'];
        $activated = $this->worker('contract_activate', [...$this->basePayload($office, $contractId), 'idempotency_key' => 'subscription-queued-module-activate', 'frozen_now' => '2026-10-28 12:00:00 America/Sao_Paulo']);
        self::assertSame(200, $this->workerResult($activated)['status']);
        $company = ErpCompany::query()->findOrFail($companyId);
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $companyId, 'user_id' => $owner->id, 'role' => 'owner']);
        $establishment = ErpEstablishment::query()->where('erp_company_id', $companyId)->firstOrFail();
        $attempt = app(ErpFiscalAttemptService::class)->createIntent([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => (int) $establishment->id,
            'action' => 'document:transmit',
            'model' => '57',
            'environment' => 'homologation',
            'series' => 1,
            'number' => 1,
            'actor_user_id' => (int) $owner->id,
            'idempotency_key' => 'queued-module-boundary-'.bin2hex(random_bytes(6)),
            'request' => ['authorization' => ['access_kind' => 'direct'], 'source' => ['issuer' => ['cnpj' => $company->cnpj]]],
        ]);
        self::assertTrue(app(ErpFiscalAttemptAuthorization::class)->allowsDispatch($attempt));

        $contract = OfficeErpRecords::query($office['context'], OfficeErpContract::class)->whereKey($contractId)->firstOrFail();
        $pending = $this->officeAdmin($office, fn () => $office['context']->run(fn () => app(OfficeErpContracts::class)->amend(
            $office['context'], $contract, ['module_keys' => ['purchases']], 'queued-module-boundary-reduction',
        )));
        $effectiveAt = $pending->service_period_start->toDateString().' 00:00:00 America/Sao_Paulo';
        CarbonImmutable::setTestNow($effectiveAt);
        Carbon::setTestNow($effectiveAt);
        self::assertFalse(app(ErpFiscalAttemptAuthorization::class)->allowsDispatch($attempt->fresh()));

        app()->call([new ProcessErpFiscalAttempt((int) $attempt->id), 'handle']);
        self::assertSame('blocked_authority', $attempt->fresh()->status);
        self::assertSame('attention', $attempt->fresh()->outbox_state);
    }

    /** @return list<int> */
    private function registerCompanies(array $office, int $count): array
    {
        $clients = collect(range(1, $count))->map(fn (): Client => Client::factory()->company()->create(['account_id' => $office['account_id']]));
        $this->officeAdmin($office, fn () => $office['context']->run(fn () => app(OfficeErpCompanyRegistrations::class)->register(
            $office['context'], ['client_ids' => $clients->map(fn (Client $client): int => (int) $client->id)->all()], 'subscription-register-'.bin2hex(random_bytes(6)),
        )));

        return DB::table('office_erp_company_registrations')->whereIn('client_id', $clients->pluck('id'))->orderBy('id')->pluck('erp_company_id')->map(static fn ($id): int => (int) $id)->all();
    }

    private function technicalPlan(array $modules = ['purchases']): int
    {
        $plan = ErpPlan::factory()->create(['slug' => 'subscription-race-'.bin2hex(random_bytes(5))]);
        foreach ($modules as $module) {
            ErpPlanModule::factory()->create(['erp_plan_id' => $plan->id, 'module_key' => $module]);
        }

        return (int) $plan->id;
    }

    /** @return array<string, mixed> */
    private function assignmentTerms(int $planId, string $start = '2026-10-28'): array
    {
        return [
            'erp_plan_id' => $planId, 'price' => '125.50', 'currency' => 'BRL', 'interval_months' => 1,
            'due_in_days' => 10, 'start_on' => $start, 'billing_timezone' => 'America/Sao_Paulo',
        ];
    }

    /** @return array<string, mixed> */
    private function assignmentPayload(array $office, int $companyId, array $terms, string $key, bool $hold = false): array
    {
        return [...$this->basePayload($office, 0), 'company_id' => $companyId, 'terms' => $terms, 'idempotency_key' => $key, 'hold_account_lock' => $hold];
    }

    /** @return array<string, mixed> */
    private function basePayload(array $office, int $contractId): array
    {
        return [
            'account_id' => $office['account_id'], 'actor_id' => $office['actor_id'], 'membership_id' => $office['membership_id'],
            'contract_id' => $contractId,
            'billing_source_hashes' => $this->billingSourceHashes(),
        ];
    }

    /** @return array<string, string> */
    private function billingSourceHashes(): array
    {
        $paths = [
            'app/Erp/Commercial/OfficeErpAdmission.php',
            'app/Erp/Commercial/OfficeErpAssignments.php',
            'app/Erp/Commercial/OfficeErpCompanyRegistrations.php',
            'app/Erp/Commercial/OfficeErpContracts.php',
            'app/Erp/Commercial/OfficeErpOperations.php',
            'app/Erp/Commercial/OfficeErpSubscriptionCompanies.php',
            'app/Erp/ErpCommercialGate.php',
            'app/Models/Erp/Commercial/OfficeErpContract.php',
            'app/Models/Erp/Commercial/OfficeErpContractRevision.php',
            'app/Erp/Fiscal/ErpFiscalAttemptAuthorization.php',
            'app/Erp/Fiscal/ErpFiscalAttemptService.php',
            'app/Jobs/ProcessErpFiscalAttempt.php',
            'routes/office-erp-subscriptions.php',
            'tests/Integration/OfficeErpCommercialConcurrencyWorker.php',
            'tests/Integration/OfficeErpSubscriptionConcurrencyTest.php',
        ];
        $hashes = [];
        foreach ($paths as $path) {
            $hash = hash_file('sha256', base_path($path));
            if (! is_string($hash)) {
                throw new \RuntimeException('Billing race source identity unavailable.');
            }
            $hashes[$path] = $hash;
        }

        return $hashes;
    }
}
