<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpBalances;
use App\Erp\Commercial\OfficeErpCalendar;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpInvoices;
use App\Erp\Commercial\OfficeErpMoney;
use App\Erp\Commercial\OfficeErpOwnerInvitations;
use App\Erp\Commercial\OfficeErpOwnerSetup;
use App\Erp\Commercial\OfficeErpReceiptProofs;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\Erp\Commercial\OfficeErpTrialLink;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

abstract class OfficeErpCommercialProcessTestCase extends TestCase
{
    private const PRESERVED_TABLES = [
        'accounts', 'account_memberships', 'users', 'subscriptions', 'plans', 'departments', 'clients',
        'erp_companies', 'erp_company_memberships', 'erp_establishments', 'erp_company_setup_states',
        'erp_units', 'erp_contacts', 'erp_invoice_schemes', 'erp_invoice_layouts', 'erp_plans', 'erp_subscriptions',
        'office_erp_sales_plans', 'office_erp_sales_plan_revisions', 'office_erp_contracts',
        'office_erp_contract_revisions', 'office_erp_owner_invitations', 'office_erp_licence_allocations', 'office_erp_invoices',
        'office_erp_invoice_counters', 'office_erp_commercial_operations', 'office_erp_audit_events',
        'office_erp_receipts', 'office_erp_receipt_allocations', 'office_erp_receipt_reversals', 'office_erp_receipt_proofs',
        'office_erp_trial_links', 'erp_subscription_trials',
    ];

    private const SOURCES = [
        'composer.lock',
        'app/Erp/Commercial/OfficeErpAdmission.php',
        'app/Erp/Commercial/OfficeErpBalances.php',
        'app/Erp/Commercial/OfficeErpDelinquency.php',
        'app/Erp/Commercial/OfficeErpExecutionContext.php',
        'app/Erp/Commercial/OfficeErpLicenceAllocator.php',
        'app/Erp/Commercial/OfficeErpMoney.php',
        'app/Erp/Commercial/OfficeErpInvoices.php',
        'app/Erp/Commercial/OfficeErpReceipts.php',
        'app/Erp/Commercial/OfficeErpReceiptProofs.php',
        'app/Erp/Commercial/OfficeErpOperations.php',
        'app/Erp/Commercial/OfficeErpOwnerAcceptance.php',
        'app/Erp/Commercial/OfficeErpCustomerEnrollment.php',
        'app/Erp/Commercial/OfficeErpTrialLinks.php',
        'app/Erp/Commercial/OfficeErpTrialRegistration.php',
        'app/Erp/Commercial/ErpTrialLifecycle.php',
        'app/Erp/Commercial/OfficeErpContracts.php',
        'app/Erp/Commercial/OfficeErpRecurringBilling.php',
        'app/Erp/Commercial/OfficeErpCalendar.php',
        'app/Erp/Commercial/OfficeErpOwnerInvitations.php',
        'app/Erp/Commercial/OfficeErpOwnerSetup.php',
        'app/Erp/Commercial/OfficeErpInvitationContinuation.php',
        'app/Erp/Commercial/OfficeErpRecords.php',
        'app/Models/Erp/Commercial/OfficeErpContract.php',
        'app/Models/Erp/Commercial/OfficeErpLicenceAllocation.php',
        'app/Models/Erp/Commercial/OfficeErpInvoice.php',
        'app/Models/Erp/Commercial/OfficeErpInvoiceCounter.php',
        'app/Models/Erp/Commercial/OfficeErpCommercialOperation.php',
        'app/Models/Erp/Commercial/OfficeErpCommercialSetting.php',
        'app/Models/Erp/Commercial/OfficeErpAuditEvent.php',
        'app/Models/Erp/Commercial/OfficeErpOwnerInvitation.php',
        'app/Models/Erp/Commercial/OfficeErpInvoiceAdjustment.php',
        'app/Models/Erp/Commercial/OfficeErpReceipt.php',
        'app/Models/Erp/Commercial/OfficeErpReceiptAllocation.php',
        'app/Models/Erp/Commercial/OfficeErpReceiptProof.php',
        'app/Models/Erp/Commercial/OfficeErpReceiptReversal.php',
        'app/Jobs/CleanExpiredOfficeErpProofs.php',
        'app/Jobs/GenerateOfficeErpInvoices.php',
        'app/Models/Erp/ErpCompany.php',
        'app/Models/Erp/ErpEstablishment.php',
        'app/Models/Erp/ErpCompanyMembership.php',
        'app/Models/Erp/ErpPlan.php',
        'app/Models/Erp/ErpSubscription.php',
        'app/Models/Erp/ErpSubscriptionTrial.php',
        'app/Models/Erp/Commercial/OfficeErpTrialLink.php',
        'database/migrations/2026_10_25_090000_add_erp_customer_enrollment.php',
        'database/migrations/2026_10_25_091000_create_erp_office_trials.php',
        'app/Models/Account.php',
        'app/Models/AccountMembership.php',
        'app/Models/Client.php',
        'app/Actions/AccessAccounts.php',
        'app/Services/ClientManager.php',
        'app/Services/CnpjWsLookup.php',
        'app/Models/User.php',
        'app/Erp/ErpCompanyProvisioning.php',
        'app/Erp/ErpCompanyInitializer.php',
        'app/Erp/ErpCommercialCatalog.php',
        'app/Erp/ErpCommercialGate.php',
        'app/Http/Controllers/Api/V1/Erp/ErpTrialController.php',
        'app/Http/Controllers/Api/V1/OfficeErp/OfficeErpEnrollmentController.php',
        'app/Http/Controllers/Api/V1/OfficeErp/OfficeErpOwnerSetupController.php',
        'app/Http/Controllers/Api/V1/OfficeErp/OfficeErpInvitationController.php',
        'routes/office-erp-trials.php',
        'routes/office-erp-commercial.php',
        'routes/office-erp-invitations.php',
        'routes/api.php',
        'config/erp.php',
        'app/Services/BrazilianTaxId.php',
        'app/Support/AccessSession.php',
        'app/Support/ErpSession.php',
        'app/Http/Middleware/SerializedAccessSession.php',
        'app/Http/Controllers/Api/V1/Admin/PlanController.php',
        'app/Http/Requests/Admin/UpdatePlanRequest.php',
        'app/Tenant/CurrentTenant.php',
        'database/factories/Erp/ErpCompanyFactory.php',
        'database/factories/Erp/ErpCompanyMembershipFactory.php',
        'database/factories/Erp/ErpPlanFactory.php',
        'database/factories/Erp/ErpSubscriptionFactory.php',
        'tests/Integration/OfficeErpCommercialConcurrencyTest.php',
        'tests/Integration/OfficeErpCommercialProcessTestCase.php',
        'tests/Integration/OfficeErpCommercialConcurrencyWorker.php',
    ];

    /** @var array<int, array{process: Process, base: string, identity: array<string, mixed>}> */
    private array $workers = [];

    /** @var array<string, array{ids: list<int|string>, hash: string}> */
    private array $retainedRows = [];

    /** @var array{pid: int, database_pid: int, checkout: string, source_hashes: array<string, string>} */
    private array $identity;

    private string $directory;

    private int $workerSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identity = $this->assertIsolatedStores();
        self::assertSame(0, DB::transactionLevel(), 'Commercial race fixtures must be committed and visible to subprocesses.');
        Http::preventStrayRequests();
        foreach (self::PRESERVED_TABLES as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->retainedRows[$table] = [
                'ids' => $rows->pluck('id')->map(static fn ($id): int|string => is_int($id) ? $id : (string) $id)->all(),
                'hash' => hash('sha256', $rows->toJson()),
            ];
        }

        $this->directory = sys_get_temp_dir().'/opmoni-office-erp-races-'.bin2hex(random_bytes(10));
        if (! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Unable to create the private commercial race signal directory.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            if ($worker['process']->isRunning()) {
                $worker['process']->stop(1);
            }
        }

        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }

        foreach ($this->retainedRows as $table => $baseline) {
            $rows = $baseline['ids'] === []
                ? collect()
                : DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get();
            self::assertSame($baseline['hash'], hash('sha256', $rows->toJson()), 'Pre-existing rows changed: '.$table);
        }

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    protected function commercialFixture(int $licenceLimit): array
    {
        self::assertSame(0, DB::transactionLevel());
        $tag = bin2hex(random_bytes(8));
        $account = Account::factory()->create(['name' => 'Office ERP race '.$tag, 'status' => 'active']);
        $plan = Plan::factory()->create([
            'erp_commercial' => ['enabled' => true, 'licence_limit' => $licenceLimit, 'module_keys' => ['purchases']],
        ]);
        $subscription = Subscription::query()->where('account_id', $account->id)->firstOrFail();
        $subscription->forceFill(['plan_id' => $plan->id, 'status' => 'active'])->save();
        $actor = User::factory()->create(['email' => 'office-erp-race-'.$tag.'@example.test']);
        $membership = AccountMembership::factory()->create([
            'account_id' => $account->id,
            'user_id' => $actor->id,
            'role' => 'admin',
        ]);
        [$context, $contracts] = $this->selectOffice($account, $membership, function () use ($account, $actor, $tag): array {
            $context = OfficeErpExecutionContext::officeAdmin($account, $actor, 'commercial-race:'.$tag);
            $contracts = [
                $this->acceptedContract($context, $tag.'-first'),
                $this->acceptedContract($context, $tag.'-second'),
            ];

            return [$context, $contracts];
        });

        return [
            'account_id' => (int) $account->id,
            'actor_id' => (int) $actor->id,
            'membership_id' => (int) $membership->id,
            'context' => $context,
            'account' => $account,
            'actor' => $actor,
            'membership' => $membership,
            'contracts' => $contracts,
            'contract_ids' => array_map(static fn (OfficeErpContract $contract): int => (int) $contract->id, $contracts),
            'second_contract_snapshot' => $contracts[1]->getAttributes(),
        ];
    }

    /** @return array{link: OfficeErpTrialLink, code: string, plan_id: int} */
    protected function trialLinkFixture(array $office): array
    {
        $plan = ErpPlan::factory()->create();
        $plan->modules()->create(['module_key' => 'purchases', 'quota' => null]);
        $code = (string) Str::uuid();
        $link = OfficeErpTrialLink::query()->create([
            'account_id' => $office['account_id'],
            'public_code' => $code,
            'erp_plan_id' => $plan->id,
            'enabled' => true,
            'revision' => 1,
            'created_by_user_id' => $office['actor_id'],
            'updated_by_user_id' => $office['actor_id'],
        ]);

        return ['link' => $link, 'code' => $code, 'plan_id' => (int) $plan->id];
    }

    /** @return array<string, mixed> */
    protected function ownerSetupFixture(array $office): array
    {
        $context = $office['context'];
        $contract = $office['contracts'][0];
        $tag = bin2hex(random_bytes(6));
        $company = $this->officeAdmin($office, fn (): ErpCompany => $context->run(function () use ($context, $contract, $tag): ErpCompany {
            $contract = OfficeErpRecords::query($context, OfficeErpContract::class)
                ->with('client')
                ->whereKey($contract->id)
                ->firstOrFail();
            $company = ErpCompany::factory()->create([
                'name' => 'Owner setup race company '.$tag,
                'cnpj' => $contract->client->tax_id,
                'owner_setup_status' => 'unclaimed',
            ]);
            $plan = ErpPlan::factory()->create();
            $plan->modules()->create(['module_key' => 'purchases', 'quota' => null]);
            $contract->forceFill([
                'erp_company_id' => $company->id,
                'agreement_status' => 'effective',
                'provisioning_source' => 'administrative',
            ])->save();
            $subscription = ErpSubscription::factory()->active()->create([
                'erp_company_id' => $company->id,
                'erp_plan_id' => $plan->id,
                'office_erp_contract_id' => $contract->id,
            ]);
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $context->accountId,
                'contract_id' => $contract->id,
                'erp_company_id' => $company->id,
                'state' => 'occupied',
                'expires_at' => null,
            ]);

            return $company->setRelation('subscription', $subscription);
        }));
        $owner = ['full_name' => 'Trial Race Owner '.$tag, 'email' => 'owner-setup-race-'.$tag.'@example.test'];
        $issued = $this->officeAdmin($office, fn (): array => $context->run(
            fn (): array => app(OfficeErpOwnerSetup::class)->issue($context, $contract, $owner, 'owner-setup-seed-'.$tag),
        ));
        $url = (string) ($issued['url'] ?? '');
        $fragment = strpos($url, '#token=');
        if ($fragment === false) {
            throw new RuntimeException('Owner setup race fixture did not receive a one-time invitation URL.');
        }

        return [
            'account_id' => $office['account_id'],
            'actor_id' => $office['actor_id'],
            'membership_id' => $office['membership_id'],
            'contract_id' => (int) $contract->id,
            'company_id' => (int) $company->id,
            'subscription_id' => (int) $company->subscription->id,
            'allocation_id' => (int) DB::table('office_erp_licence_allocations')->where('contract_id', $contract->id)->value('id'),
            'owner' => $owner,
            'invitation_id' => (int) $issued['invitation_id'],
            'token' => substr($url, $fragment + strlen('#token=')),
        ];
    }

    /** @param array{code: string, link: OfficeErpTrialLink} $trialLink
     * @return array<string, mixed>
     */
    protected function trialRegistrationPayload(array $office, array $trialLink, string $cnpj, string $suffix, bool $holdAccountLock = false, ?string $holdAdvisoryLock = null, bool $holdProvider = false, ?string $frozenNow = null): array
    {
        return [
            'account_id' => $office['account_id'],
            'actor_id' => $office['actor_id'],
            'membership_id' => $office['membership_id'],
            'trial_link_id' => (int) $trialLink['link']->id,
            'code' => $trialLink['code'],
            'registration' => [
                'name' => 'Trial race owner '.$suffix,
                'email' => 'trial-race-'.$suffix.'@example.test',
                'password' => 'Trial-Race-Secret-'.$suffix,
                'company_name' => 'Trial race company '.$suffix,
                'cnpj' => $cnpj,
                'tax_regime' => 'simple_national',
                'establishment_name' => 'Matriz',
            ],
            'idempotency_key' => 'trial-race-'.$suffix,
            'hold_row_lock' => $holdAccountLock ? 'accounts' : null,
            'hold_advisory_lock' => $holdAdvisoryLock,
            'hold_provider' => $holdProvider,
            'frozen_now' => $frozenNow,
        ];
    }

    /** @param array{link: OfficeErpTrialLink} $trialLink
     * @return array<string, mixed>
     */
    protected function trialRotationPayload(array $office, array $trialLink, string $suffix, bool $holdTrialLinkLock = false): array
    {
        return [
            'account_id' => $office['account_id'],
            'actor_id' => $office['actor_id'],
            'membership_id' => $office['membership_id'],
            'trial_link_id' => (int) $trialLink['link']->id,
            'idempotency_key' => 'trial-rotate-'.$suffix,
            'hold_row_lock' => $holdTrialLinkLock ? 'office_erp_trial_links' : null,
        ];
    }

    /** @param array<string, mixed> $terms
     * @return array<string, mixed>
     */
    protected function trialConversionPayload(array $office, int $contractId, array $terms, string $suffix, bool $holdAccountLock = false, ?string $frozenNow = null): array
    {
        return [
            'account_id' => $office['account_id'],
            'actor_id' => $office['actor_id'],
            'membership_id' => $office['membership_id'],
            'contract_id' => $contractId,
            'terms' => $terms,
            'idempotency_key' => 'trial-convert-'.$suffix,
            'hold_row_lock' => $holdAccountLock ? 'accounts' : null,
            'frozen_now' => $frozenNow,
        ];
    }

    /** @param array<string, mixed> $setup */
    protected function ownerSetupIssuePayload(array $setup, bool $holdCompanyLock = false): array
    {
        return [
            'account_id' => $setup['account_id'],
            'actor_id' => $setup['actor_id'],
            'membership_id' => $setup['membership_id'],
            'contract_id' => $setup['contract_id'],
            'owner' => $setup['owner'],
            'idempotency_key' => 'owner-setup-race-'.bin2hex(random_bytes(12)),
            'hold_row_lock' => $holdCompanyLock ? 'erp_companies' : null,
        ];
    }

    /** @param array<string, mixed> $setup */
    protected function ownerSetupAcceptPayload(array $setup, bool $holdCompanyLock = false): array
    {
        return [
            'token' => $setup['token'],
            'password' => 'Race-Owner-Password-123',
            'hold_row_lock' => $holdCompanyLock ? 'erp_companies' : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function effectiveInvoiceFixture(): array
    {
        $fixture = $this->commercialFixture(2);
        $anchor = CarbonImmutable::now('America/Sao_Paulo')->startOfMonth();

        return $this->officeAdmin($fixture, function () use ($fixture, $anchor): array {
            return $fixture['context']->run(function () use ($fixture, $anchor): array {
                foreach ($fixture['contracts'] as $index => $contract) {
                    $company = ErpCompany::factory()->create([
                        'name' => 'Commercial invoice race '.$index,
                        'cnpj' => $this->uniqueCnpj(),
                    ]);
                    $owner = User::factory()->create([
                        'email' => 'office-erp-invoice-owner-'.$fixture['account_id'].'-'.$index.'@example.test',
                    ]);
                    ErpCompanyMembership::factory()->create([
                        'erp_company_id' => $company->id,
                        'user_id' => $owner->id,
                        'role' => 'owner',
                    ]);
                    $contract->forceFill([
                        'erp_company_id' => $company->id,
                        'agreement_status' => 'effective',
                        'accepted_at' => now(),
                        'effective_at' => now(),
                        'service_anchor_on' => $anchor->toDateString(),
                    ])->save();
                    ErpSubscription::factory()->active()->create([
                        'erp_company_id' => $company->id,
                        'erp_plan_id' => ErpPlan::factory()->create()->id,
                        'office_erp_contract_id' => $contract->id,
                    ]);
                    OfficeErpLicenceAllocation::factory()->create([
                        'account_id' => $fixture['account_id'],
                        'contract_id' => $contract->id,
                        'erp_company_id' => $company->id,
                        'state' => 'occupied',
                        'expires_at' => null,
                    ]);
                    $fixture['contracts'][$index] = $contract->refresh();
                }

                return $fixture + [
                    'invoice_period' => [
                        'start' => $anchor->toDateString(),
                        'end' => $anchor->addMonthNoOverflow()->toDateString(),
                        'due_on' => $anchor->addDays(10)->toDateString(),
                    ],
                    'invoice_year' => (int) $anchor->year,
                ];
            });
        });
    }

    /** @return array<string, mixed> */
    protected function recurringRaceFixture(): array
    {
        $fixture = $this->effectiveInvoiceFixture();
        $contract = $fixture['contracts'][0];
        $initialInvoice = $this->issueRaceInvoice($fixture, $contract);
        $revision = $contract->currentRevision()->firstOrFail();
        $period = app(OfficeErpCalendar::class)->periodAtMonthOffset(
            $contract->service_anchor_on->toDateString(),
            (int) $revision->interval_months,
            (int) $revision->interval_months,
            $contract->billing_timezone,
            (int) $revision->due_in_days,
        );
        $contract->forceFill([
            'next_period_index' => 2,
            'next_period_month_offset' => (int) $revision->interval_months,
        ])->save();
        $fixture['contracts'][0] = $contract->fresh();
        $fixture['recurring_period'] = $period;
        $fixture['recurring_now'] = CarbonImmutable::parse($period['start'].' 12:00:00', $contract->billing_timezone)->toIso8601String();
        $fixture['recurring_invoice_year'] = (int) CarbonImmutable::parse($fixture['recurring_now'])->format('Y');
        $fixture['recurring_invoice_sequence'] = (int) $initialInvoice->issued_at->format('Y') === $fixture['recurring_invoice_year'] ? 2 : 1;
        $fixture['initial_invoice_id'] = (int) $initialInvoice->id;
        $fixture['initial_invoice_snapshot'] = $initialInvoice->fresh()->getAttributes();

        return $fixture;
    }

    /** @return array<string, mixed> */
    protected function ownerInvitationFixture(array $office, User $owner, ?string $cnpj = null): array
    {
        $context = $office['context'];
        $tag = bin2hex(random_bytes(8));
        $client = Client::factory()->company()->create([
            'account_id' => $office['account_id'],
            'tax_id' => $cnpj ?? $this->uniqueCnpj(),
            'name' => 'Owner acceptance race '.$tag,
        ]);
        $salesPlan = OfficeErpSalesPlan::factory()->create([
            'account_id' => $office['account_id'],
            'name' => 'Owner race plan '.$tag,
        ]);
        $revision = OfficeErpSalesPlanRevision::factory()->create([
            'account_id' => $office['account_id'],
            'sales_plan_id' => $salesPlan->id,
            'name' => 'Owner race plan '.$tag,
            'module_keys' => ['purchases'],
            'published_at' => now(),
        ]);
        $salesPlan->forceFill(['current_revision_id' => $revision->id])->save();
        $invitation = $this->officeAdmin($office, fn (): array => $context->run(
            fn (): array => app(OfficeErpOwnerInvitations::class)->issue(
                $context,
                (int) $client->id,
                (int) $revision->id,
                ['full_name' => $owner->name, 'email' => $owner->email],
                'owner-invitation-'.bin2hex(random_bytes(12)),
            ),
        ));
        $token = substr((string) $invitation['url'], strpos((string) $invitation['url'], '#token=') + 7);

        return [
            'account_id' => $office['account_id'],
            'owner_id' => (int) $owner->id,
            'client_id' => (int) $client->id,
            'cnpj' => (string) $client->tax_id,
            'invitation_id' => (int) $invitation['invitation_id'],
            'contract_id' => (int) OfficeErpOwnerInvitation::query()->findOrFail($invitation['invitation_id'])->contract_id,
            'token' => $token,
            'plan_id' => (int) $office['account']->subscription()->firstOrFail()->plan_id,
            'office' => $office,
        ];
    }

    /** @param array<string, mixed> $fixture */
    protected function officeAdmin(array $fixture, callable $callback): mixed
    {
        $account = Account::query()->findOrFail($fixture['account_id']);
        $membership = AccountMembership::query()
            ->whereKey($fixture['membership_id'])
            ->where('account_id', $fixture['account_id'])
            ->where('user_id', $fixture['actor_id'])
            ->where('role', 'admin')
            ->firstOrFail();

        return $this->selectOffice($account, $membership, $callback);
    }

    /** @param array<string, mixed> $fixture */
    protected function issueRaceInvoice(array $fixture, OfficeErpContract $contract): OfficeErpInvoice
    {
        $revision = OfficeErpRecords::query($fixture['context'], OfficeErpContractRevision::class)
            ->whereKey($contract->current_revision_id)
            ->firstOrFail();

        return $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => app(OfficeErpInvoices::class)->issuePeriod(
                $fixture['context'],
                $contract,
                $revision,
                $fixture['invoice_period'],
                'race-financial-issue-'.bin2hex(random_bytes(8)),
            ),
        ));
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    protected function financialPayload(array $fixture, array $payload): array
    {
        return [
            'account_id' => $fixture['account_id'],
            'actor_id' => $fixture['actor_id'],
            'membership_id' => $fixture['membership_id'],
            'contract_id' => (int) $fixture['contracts'][0]->id,
            ...$payload,
        ];
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    protected function cancellationPayload(array $fixture, OfficeErpContract $contract, string $key, bool $holdAccountLock = false): array
    {
        return [
            'account_id' => $fixture['account_id'],
            'actor_id' => $fixture['actor_id'],
            'membership_id' => $fixture['membership_id'],
            'contract_id' => (int) $contract->id,
            'mode' => 'immediate',
            'reason' => 'Concurrent recurring cancellation',
            'idempotency_key' => $key,
            'frozen_now' => $fixture['recurring_now'],
            'hold_account_lock' => $holdAccountLock,
        ];
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    protected function recurringPayload(array $fixture, OfficeErpContract $contract, bool $holdAccountLock = false): array
    {
        return [
            'contract_id' => (int) $contract->id,
            'now' => $fixture['recurring_now'],
            'frozen_now' => $fixture['recurring_now'],
            'batch_size' => 1,
            'hold_account_lock' => $holdAccountLock,
        ];
    }

    /** @param array<string, mixed> $fixture @return array{id:string,expires_at:string} */
    protected function stageRaceProof(array $fixture): array
    {
        return $this->officeAdmin($fixture, fn () => $fixture['context']->run(
            fn () => app(OfficeErpReceiptProofs::class)->stage(
                $fixture['context'],
                UploadedFile::fake()->createWithContent('race-proof.pdf', "%PDF-1.4\nPrivate race proof\n%%EOF\n"),
            ),
        ));
    }

    /** @return array{amount:string,received_on:string,allocations:list<array{invoice_id:int,amount:string}>} */
    protected function receiptInput(OfficeErpInvoice $invoice, string $amount): array
    {
        return [
            'amount' => $amount,
            'received_on' => now('America/Sao_Paulo')->toDateString(),
            'allocations' => [['invoice_id' => (int) $invoice->id, 'amount' => $amount]],
        ];
    }

    /** @param array<string, mixed> $payload */
    protected function worker(string $action, array $payload): Process
    {
        $base = $this->directory.'/worker-'.(++$this->workerSequence);
        $process = new Process(
            [PHP_BINARY, __DIR__.'/OfficeErpCommercialConcurrencyWorker.php'],
            base_path(),
            ['APP_ENV' => 'testing', 'FISCAL_ENVIRONMENT' => 'producao', 'FRONTEND_ORIGIN' => 'http://localhost:3000'],
        );
        $process->setInput(json_encode([...$payload,
            'action' => $action,
            'base' => $base,
            'source_hashes' => $this->identity['source_hashes'],
        ], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $this->workers[spl_object_id($process)] = ['process' => $process, 'base' => $base, 'identity' => []];
        $process->start();
        $this->waitFor($process, 'ready');
        $identity = $this->signalData($process, 'ready');
        self::assertSame($process->getPid(), $identity['pid']);
        self::assertNotSame($this->identity['pid'], $identity['pid']);
        self::assertNotSame($this->identity['database_pid'], $identity['database_pid']);
        foreach ($this->workers as $other) {
            if ($other['process'] !== $process && $other['identity'] !== []) {
                self::assertNotSame($other['identity']['pid'], $identity['pid']);
                self::assertNotSame($other['identity']['database_pid'], $identity['database_pid']);
            }
        }
        $this->workers[spl_object_id($process)]['identity'] = $identity;

        return $process;
    }

    protected function waitFor(Process $process, string $signal): void
    {
        $deadline = microtime(true) + 15;
        while (! file_exists($this->signal($process, $signal))) {
            if (! $process->isRunning()) {
                self::fail('Worker exited before '.$signal.'; exit='.$process->getExitCode().'; stdout bytes='.strlen($process->getOutput()).'; stderr bytes='.strlen($process->getErrorOutput()));
            }
            if (microtime(true) >= $deadline) {
                self::fail('Commercial worker barrier timed out: '.$signal);
            }
            usleep(10000);
        }
    }

    protected function release(Process $process, string $signal = 'release'): void
    {
        if (! touch($this->signal($process, $signal))) {
            throw new RuntimeException('Unable to release the private commercial worker barrier.');
        }
    }

    /** @return array<string, mixed> */
    protected function signalData(Process $process, string $signal): array
    {
        return json_decode(file_get_contents($this->signal($process, $signal)), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function assertWaitingOnAccountLock(Process $process): void
    {
        $identity = $this->workers[spl_object_id($process)]['identity'];
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne(
                'select wait_event_type, query from pg_stat_activity where pid = ?',
                [$identity['database_pid']],
            );
            if ($activity?->wait_event_type === 'Lock') {
                self::assertStringContainsString('"accounts"', strtolower($activity->query));
                self::assertStringContainsString('for update', strtolower($activity->query));

                return;
            }
            self::assertTrue($process->isRunning(), 'Contender exited before the Account row lock wait.');
            self::assertLessThan($deadline, microtime(true), 'No real PostgreSQL Account row lock wait observed.');
            usleep(10000);
        } while (true);
    }

    protected function assertWaitingOnRowLock(Process $process, string $table): void
    {
        $identity = $this->workers[spl_object_id($process)]['identity'];
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne('select wait_event_type, query from pg_stat_activity where pid = ?', [$identity['database_pid']]);
            if ($activity?->wait_event_type === 'Lock') {
                self::assertStringContainsString('from "'.$table.'"', strtolower($activity->query));
                self::assertStringContainsString('for update', strtolower($activity->query));

                return;
            }
            self::assertTrue($process->isRunning(), 'Worker exited before waiting for the '.$table.' row lock.');
            self::assertLessThan($deadline, microtime(true), 'No real PostgreSQL '.$table.' row lock wait observed.');
            usleep(10000);
        } while (true);
    }

    protected function assertWaitingOnTableLock(Process $process, string $table): void
    {
        self::assertMatchesRegularExpression('/\A[a-z_]+\z/', $table);
        $identity = $this->workers[spl_object_id($process)]['identity'];
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne('select wait_event_type, query from pg_stat_activity where pid = ?', [$identity['database_pid']]);
            if ($activity?->wait_event_type === 'Lock') {
                self::assertStringContainsString('from "'.$table.'"', strtolower($activity->query));
                self::assertStringContainsString('for update', strtolower($activity->query));

                return;
            }
            self::assertTrue($process->isRunning(), 'Worker exited before waiting for the '.$table.' row lock.');
            self::assertLessThan($deadline, microtime(true), 'No real PostgreSQL '.$table.' row lock wait observed.');
            usleep(10000);
        } while (true);
    }

    protected function assertWaitingOnAdvisoryLock(Process $process): void
    {
        $identity = $this->workers[spl_object_id($process)]['identity'];
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne('select wait_event_type, query from pg_stat_activity where pid = ?', [$identity['database_pid']]);
            if ($activity?->wait_event_type === 'Lock') {
                self::assertStringContainsString('pg_advisory_xact_lock', strtolower($activity->query));

                return;
            }
            self::assertTrue($process->isRunning(), 'Worker exited before waiting for the PostgreSQL advisory lock.');
            self::assertLessThan($deadline, microtime(true), 'No real PostgreSQL advisory lock wait observed.');
            usleep(10000);
        } while (true);
    }

    protected function assertWorkerHeldAdvisoryLock(Process $process): void
    {
        $ready = $this->signalData($process, 'ready');
        $held = $this->signalData($process, 'held');
        self::assertSame($ready['database_pid'], $held['database_pid']);
        self::assertGreaterThan(0, $held['transaction_level']);
        self::assertStringContainsString('pg_advisory_xact_lock', strtolower($held['lock_query']));
    }

    /** @param array<string, mixed> $invitation @return array<string, mixed> */
    protected function ownerAcceptancePayload(array $invitation, bool $holdAccountLock = false, bool $holdOwnerLock = false): array
    {
        return [
            'owner_id' => $invitation['owner_id'],
            'token' => $invitation['token'],
            'contract_id' => $invitation['contract_id'],
            'hold_account_lock' => $holdAccountLock,
            'hold_owner_lock' => $holdOwnerLock,
        ];
    }

    protected function assertWorkerHeldLock(Process $process, string $table): void
    {
        $ready = $this->signalData($process, 'ready');
        $held = $this->signalData($process, 'held');
        self::assertSame($ready['database_pid'], $held['database_pid']);
        self::assertGreaterThan(0, $held['transaction_level']);
        self::assertStringContainsString('from "'.$table.'"', strtolower($held['lock_query']));
        self::assertStringContainsString('for update', strtolower($held['lock_query']));
    }

    protected function assertWaitingOnProofLock(Process $process): void
    {
        $identity = $this->workers[spl_object_id($process)]['identity'];
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne(
                'select wait_event_type, query from pg_stat_activity where pid = ?',
                [$identity['database_pid']],
            );
            if ($activity?->wait_event_type === 'Lock') {
                self::assertStringContainsString('office_erp_receipt_proofs', strtolower($activity->query));
                self::assertStringContainsString('for update', strtolower($activity->query));

                return;
            }
            self::assertTrue($process->isRunning(), 'Proof cleanup exited before waiting for the proof row lock.');
            self::assertLessThan($deadline, microtime(true), 'No real PostgreSQL proof row lock wait observed.');
            usleep(10000);
        } while (true);
    }

    protected function assertInvoiceLedger(array $fixture, int $invoiceId, string $charge, string $received, string $outstanding): void
    {
        $balance = $this->officeAdmin($fixture, fn () => $fixture['context']->run(function () use ($fixture, $invoiceId): array {
            $invoice = OfficeErpRecords::query($fixture['context'], OfficeErpInvoice::class)
                ->whereKey($invoiceId)
                ->firstOrFail();

            return OfficeErpBalances::forInvoice($invoice);
        }));
        self::assertSame($charge, $balance['adjusted_charge']);
        self::assertSame($received, $balance['effective_received']);
        self::assertSame($outstanding, $balance['outstanding']);
        self::assertGreaterThanOrEqual(0, OfficeErpMoney::compare($balance['outstanding'], '0.00'));
    }

    /** @return array<string, mixed> */
    protected function workerResult(Process $process): array
    {
        $process->wait();
        self::assertTrue($process->isSuccessful(), 'Worker exit='.$process->getExitCode().'; stderr bytes='.strlen($process->getErrorOutput()));
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $result['transaction_level']);

        return $result;
    }

    /** @return array{pid: int, database_pid: int, checkout: string, source_hashes: array<string, string>} */
    public static function assertIsolatedStores(): array
    {
        $checkout = realpath(base_path('..'));
        if (! app()->environment('testing')
            || app()->configurationIsCached()
            || $checkout !== '/workspace'
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.host') !== 'postgres'
            || config('database.connections.pgsql.database') !== 'opmoni_test'
            || config('cache.default') !== 'redis'
            || config('session.driver') !== 'redis'
            || config('session.connection') !== 'sessions'
            || config('session.store') !== 'sessions'
            || config('database.redis.options.prefix') !== 'opmoni-access-test-') {
            throw new RuntimeException('Commercial races require the guarded private checkout and isolated stores before fixture writes.');
        }

        foreach (self::PRESERVED_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException('Commercial schema is absent; apply the additive private test migrations before this race.');
            }
        }

        $database = DB::selectOne("select current_database() as name, pg_backend_pid() as pid, current_setting('server_version_num')::integer as version");
        if ($database->name !== 'opmoni_test' || $database->version < 180000 || $database->version >= 190000) {
            throw new RuntimeException('Commercial races require the private PostgreSQL 18 opmoni_test database.');
        }
        foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $number) {
            if (config('database.redis.'.$connection.'.host') !== 'redis'
                || (int) config('database.redis.'.$connection.'.database') !== $number) {
                throw new RuntimeException('Unsafe configured commercial race Redis store.');
            }
            $client = Redis::connection($connection)->client();
            if ((int) $client->getDBNum() !== $number
                || $client->getOption(\Redis::OPT_PREFIX) !== 'opmoni-access-test-') {
                throw new RuntimeException('Unsafe connected commercial race Redis store.');
            }
            $redisVersion = $client->info('server')['redis_version'] ?? null;
            if (! is_string($redisVersion) || ! str_starts_with($redisVersion, '8.')) {
                throw new RuntimeException('Commercial races require private Redis 8.');
            }
        }

        $hashes = [];
        foreach (self::SOURCES as $path) {
            $hash = hash_file('sha256', base_path($path));
            if (! is_string($hash)) {
                throw new RuntimeException('Commercial race source identity unavailable.');
            }
            $hashes[$path] = $hash;
        }

        return [
            'pid' => getmypid(),
            'database_pid' => (int) $database->pid,
            'checkout' => $checkout,
            'source_hashes' => $hashes,
        ];
    }

    private function acceptedContract(OfficeErpExecutionContext $context, string $label): OfficeErpContract
    {
        return $context->run(function () use ($context, $label): OfficeErpContract {
            $salesPlan = OfficeErpSalesPlan::factory()->create([
                'account_id' => $context->accountId,
                'name' => 'Race plan '.$label,
            ]);
            $salesRevision = OfficeErpSalesPlanRevision::factory()->create([
                'account_id' => $context->accountId,
                'sales_plan_id' => $salesPlan->id,
                'module_keys' => ['purchases'],
            ]);
            $salesPlan->forceFill(['current_revision_id' => $salesRevision->id])->save();
            $client = Client::factory()->company()->create(['account_id' => $context->accountId]);
            $contract = OfficeErpContract::factory()->create([
                'account_id' => $context->accountId,
                'client_id' => $client->id,
                'sales_plan_id' => $salesPlan->id,
                'agreement_status' => 'accepted',
                'billing_timezone' => 'America/Sao_Paulo',
            ]);
            $revision = OfficeErpContractRevision::factory()->create([
                'account_id' => $context->accountId,
                'contract_id' => $contract->id,
                'price' => '100.00',
                'currency' => 'BRL',
                'interval_months' => 1,
                'due_in_days' => 10,
                'module_keys' => ['purchases'],
            ]);
            $contract->forceFill(['current_revision_id' => $revision->id])->save();

            return $contract->refresh();
        });
    }

    private function selectOffice(Account $account, AccountMembership $membership, ?callable $callback = null): mixed
    {
        $request = request();
        $previous = $request->attributes->get(CurrentAccount::class);
        $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));

        try {
            return $callback === null ? null : $callback();
        } finally {
            if ($previous === null) {
                $request->attributes->remove(CurrentAccount::class);
            } else {
                $request->attributes->set(CurrentAccount::class, $previous);
            }
        }
    }

    protected function uniqueCnpj(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $digits = array_map('intval', str_split((string) random_int(100000000000, 999999999999)));
            foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
                $sum = 0;
                foreach ($weights as $index => $weight) {
                    $sum += $digits[$index] * $weight;
                }
                $remainder = $sum % 11;
                $digits[] = $remainder < 2 ? 0 : 11 - $remainder;
            }

            $cnpj = implode('', $digits);
            if (! DB::table('erp_companies')->where('cnpj', $cnpj)->exists()) {
                return $cnpj;
            }
        }

        throw new RuntimeException('Unable to generate an unused CNPJ for the retained commercial race fixture.');
    }

    private function signal(Process $process, string $name): string
    {
        return $this->workers[spl_object_id($process)]['base'].'.'.$name;
    }
}
