<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\OfficeErpCommercialTestCase;

class TrialSchemaTest extends OfficeErpCommercialTestCase
{
    protected function refreshTestDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            if (! Schema::hasTable('erp_subscription_trials')) {
                throw new \RuntimeException('Run the additive schema migrations before the focused PostgreSQL trial checks.');
            }

            $this->beginDatabaseTransaction();

            return;
        }

        parent::refreshTestDatabase();
    }

    public function test_legacy_records_keep_owner_and_financial_snapshots(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $invitation = OfficeErpOwnerInvitation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
        ]);
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'active',
        ]);
        $invoice = $this->issuedInvoice($ctx, $contract);
        $invoiceSnapshot = $invoice->only(['number', 'original_total', 'document_status']);
        $revisionSnapshot = $contract->currentRevision->only(['price', 'currency', 'interval_months', 'due_in_days', 'module_keys']);

        self::assertSame('claimed', $company->fresh()->owner_setup_status);
        self::assertSame('legacy_invitation', $contract->fresh()->provisioning_source);
        self::assertSame('commercial_acceptance', $invitation->fresh()->purpose);
        try {
            $invitation->forceFill(['purpose' => 'access_setup'])->save();
            self::fail('An invitation token purpose cannot be changed after issuance.');
        } catch (LogicException) {
            self::assertSame($revisionSnapshot, $contract->fresh()->currentRevision->only(['price', 'currency', 'interval_months', 'due_in_days', 'module_keys']));
            self::assertSame($invoiceSnapshot, $invoice->fresh()->only(['number', 'original_total', 'document_status']));
            self::assertSame($user->id, $company->fresh()->memberships()->where('role', 'owner')->value('user_id'));
            self::assertSame('active', $subscription->fresh()->status);
        }
    }

    public function test_additive_migrations_backfill_legacy_rows_without_rewriting_snapshots(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            self::markTestSkipped('The pre-addition upgrade proof uses its own in-memory SQLite database.');
        }

        $connection = 'erp_customer_enrollment_upgrade';
        $originalConnection = DB::getDefaultConnection();
        try {
            config([
                'database.connections.'.$connection => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
                'database.default' => $connection,
            ]);
            DB::purge($connection);
            DB::setDefaultConnection($connection);

            $enrollmentMigration = database_path('migrations/2026_10_25_090000_add_erp_customer_enrollment.php');
            $trialMigration = database_path('migrations/2026_10_25_091000_create_erp_office_trials.php');
            $prior = array_values(array_filter(
                glob(database_path('migrations/*.php')),
                static fn (string $path): bool => strcmp($path, $enrollmentMigration) < 0,
            ));
            self::assertSame(0, Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => $prior,
                '--realpath' => true,
                '--force' => true,
                '--no-interaction' => true,
            ]));
            self::assertFalse(Schema::hasColumn('erp_companies', 'owner_setup_status'));
            self::assertFalse(Schema::hasColumn('office_erp_contracts', 'provisioning_source'));
            self::assertFalse(Schema::hasColumn('office_erp_owner_invitations', 'purpose'));

            $account = Account::factory()->create();
            $client = Client::factory()->company()->create(['account_id' => $account->id]);
            $owner = User::factory()->create();
            $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
            ErpCompanyMembership::factory()->create([
                'erp_company_id' => $company->id,
                'user_id' => $owner->id,
                'role' => 'owner',
            ]);
            $erpPlan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
            $subscription = ErpSubscription::factory()->create([
                'erp_company_id' => $company->id,
                'erp_plan_id' => $erpPlan->id,
                'status' => 'active',
            ]);
            $salesPlan = OfficeErpSalesPlan::factory()->create(['account_id' => $account->id]);
            $salesPlanRevision = OfficeErpSalesPlanRevision::factory()->create([
                'account_id' => $account->id,
                'sales_plan_id' => $salesPlan->id,
                'price' => '321.45',
                'module_keys' => ['purchases', 'add_sale'],
            ]);
            $salesPlan->forceFill(['current_revision_id' => $salesPlanRevision->id])->save();
            $contract = OfficeErpContract::factory()->create([
                'account_id' => $account->id,
                'client_id' => $client->id,
                'sales_plan_id' => $salesPlan->id,
                'erp_company_id' => $company->id,
                'agreement_status' => 'effective',
            ]);
            $contractRevision = OfficeErpContractRevision::factory()->create([
                'account_id' => $account->id,
                'contract_id' => $contract->id,
                'sales_plan_revision_id' => $salesPlanRevision->id,
                'price' => '321.45',
                'module_keys' => ['purchases', 'add_sale'],
            ]);
            $contract->forceFill([
                'current_revision_id' => $contractRevision->id,
                'service_anchor_on' => '2026-01-01',
                'effective_at' => '2026-01-01 12:00:00',
            ])->save();
            $invitation = OfficeErpOwnerInvitation::factory()->create([
                'account_id' => $account->id,
                'contract_id' => $contract->id,
            ]);
            $invoiceId = DB::table('office_erp_invoices')->insertGetId([
                'account_id' => $account->id,
                'contract_id' => $contract->id,
                'contract_revision_id' => $contractRevision->id,
                'kind' => 'manual',
                'document_status' => 'issued',
                'number' => 'LEGACY-2026-000001',
                'issued_at' => '2026-01-01 12:00:00',
                'period_start' => '2026-01-01',
                'period_end' => '2026-01-31',
                'due_on' => '2026-02-10',
                'currency' => 'BRL',
                'seller_snapshot' => '{"account":"legacy office"}',
                'bill_to_snapshot' => '{"company":"legacy customer"}',
                'items_snapshot' => '[{"description":"frozen service","amount":"321.45"}]',
                'original_total' => '321.45',
                'created_at' => '2026-01-01 12:00:00',
                'updated_at' => '2026-01-01 12:00:00',
            ]);

            $tables = [
                'erp_companies',
                'erp_company_memberships',
                'erp_subscriptions',
                'office_erp_contracts',
                'office_erp_contract_revisions',
                'office_erp_owner_invitations',
                'office_erp_invoices',
            ];
            $before = [];
            foreach ($tables as $table) {
                $columns = Schema::getColumnListing($table);
                $before[$table] = [$columns, DB::table($table)->select($columns)->orderBy('id')->get()->toJson()];
            }

            self::assertSame(0, Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => [$enrollmentMigration, $trialMigration],
                '--realpath' => true,
                '--force' => true,
                '--no-interaction' => true,
            ]));

            foreach ($before as $table => [$columns, $json]) {
                self::assertSame($json, DB::table($table)->select($columns)->orderBy('id')->get()->toJson(), $table);
            }
            self::assertSame('claimed', $company->fresh()->owner_setup_status);
            self::assertSame($owner->id, $company->fresh()->memberships()->where('role', 'owner')->value('user_id'));
            self::assertSame('active', $subscription->fresh()->status);
            self::assertSame('legacy_invitation', $contract->fresh()->provisioning_source);
            self::assertNull($contract->fresh()->erp_plan_id);
            self::assertNull($contract->fresh()->authorized_by_user_id);
            self::assertSame('commercial_acceptance', $invitation->fresh()->purpose);
            self::assertSame(1, DB::table('office_erp_invoices')->where('id', $invoiceId)->count());
            self::assertTrue(Schema::hasTable('erp_subscription_trials'));
            self::assertTrue(Schema::hasTable('office_erp_trial_links'));
        } finally {
            DB::purge($connection);
            config(['database.default' => $originalConnection]);
            DB::setDefaultConnection($originalConnection);
        }
    }

    public function test_trial_cnpj_is_unique_across_offices(): void
    {
        self::assertTrue(Schema::hasTable('office_erp_trial_links'));
        self::assertTrue(Schema::hasTable('erp_subscription_trials'));

        $firstOffice = $this->officeContext();
        $secondOffice = $this->officeContext();
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'trialing',
        ]);
        $now = now()->utc();
        $trial = [
            'erp_subscription_id' => $subscription->id,
            'erp_company_id' => $company->id,
            'account_id' => $firstOffice->accountId,
            'cnpj' => '30288513000100',
            'started_at' => $now,
            'ends_at' => $now->copy()->addHours(168),
            'link_revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('erp_subscription_trials')->insert($trial);
        $trialId = (int) DB::table('erp_subscription_trials')->where('erp_subscription_id', $subscription->id)->value('id');
        self::assertSame($trialId, $subscription->fresh()->trial->id);
        self::assertSame($trialId, $company->fresh()->trial->id);

        DB::table('erp_subscription_trials')->where('id', $trialId)->update(['converted_at' => $now]);
        self::assertDatabaseHas('erp_subscription_trials', ['id' => $trialId, 'converted_at' => $now]);

        try {
            DB::transaction(function () use ($trialId): void {
                DB::table('erp_subscription_trials')->where('id', $trialId)->update(['cnpj' => '11222333000181']);
            });
            self::fail('The original normalized CNPJ must remain immutable.');
        } catch (QueryException) {
            self::assertDatabaseHas('erp_subscription_trials', ['id' => $trialId, 'cnpj' => '30288513000100']);
        }

        try {
            DB::transaction(function () use ($trialId): void {
                DB::table('erp_subscription_trials')->where('id', $trialId)->update(['converted_at' => null]);
            });
            self::fail('A trial conversion marker cannot be cleared.');
        } catch (QueryException) {
            self::assertDatabaseHas('erp_subscription_trials', ['id' => $trialId, 'converted_at' => $now]);
        }

        try {
            DB::transaction(function () use ($trialId): void {
                DB::table('erp_subscription_trials')->where('id', $trialId)->delete();
            });
            self::fail('Trial history cannot be deleted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('erp_subscription_trials', 1);
        }

        foreach ([
            ['erp_companies', $company->id],
            ['erp_subscriptions', $subscription->id],
            ['accounts', $firstOffice->accountId],
        ] as [$table, $id]) {
            try {
                DB::transaction(static fn () => DB::table($table)->where('id', $id)->delete());
                self::fail('Trial provenance must restrict deletion from '.$table.'.');
            } catch (QueryException) {
                self::assertDatabaseHas($table, ['id' => $id]);
            }
        }

        $otherCompany = ErpCompany::factory()->create(['cnpj' => '11222333000181']);
        $otherSubscription = ErpSubscription::factory()->create([
            'erp_company_id' => $otherCompany->id,
            'erp_plan_id' => $plan->id,
            'status' => 'trialing',
        ]);

        try {
            DB::transaction(function () use ($trial, $secondOffice, $otherCompany, $otherSubscription): void {
                DB::table('erp_subscription_trials')->insert(array_merge($trial, [
                    'account_id' => $secondOffice->accountId,
                    'erp_company_id' => $otherCompany->id,
                    'erp_subscription_id' => $otherSubscription->id,
                ]));
            });
            self::fail('A CNPJ with an existing trial must not receive a second grant through another office.');
        } catch (QueryException) {
            self::assertDatabaseCount('erp_subscription_trials', 1);
        }
    }

    public function test_trial_read_status_is_not_generic_admin_write_permission(): void
    {
        self::assertContains(ErpSubscriptionStatus::Trialing, ErpSubscriptionStatus::cases());
        self::assertContains(ErpSubscriptionStatus::TrialExpired, ErpSubscriptionStatus::cases());

        $admin = User::factory()->create(['is_super_admin' => true]);
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $account = Account::factory()->create();
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'trialing',
        ]);
        $now = now()->utc();
        DB::table('erp_subscription_trials')->insert([
            'erp_subscription_id' => $subscription->id,
            'erp_company_id' => $company->id,
            'account_id' => $account->id,
            'cnpj' => $company->cnpj,
            'started_at' => $now,
            'ends_at' => $now->copy()->addHours(168),
            'link_revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->actingAs($admin);

        $this->getJson('/api/v1/admin/erp/subscriptions?status=trialing')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'trialing');
        $this->withHeader('X-CSRF-Token', $this->csrfToken())
            ->patchJson('/api/v1/admin/erp/subscriptions/'.$subscription->id, ['status' => 'trialing'])
            ->assertUnprocessable();
        $this->withHeader('X-CSRF-Token', $this->csrfToken())
            ->patchJson('/api/v1/admin/erp/subscriptions/'.$subscription->id, ['status' => 'active'])
            ->assertConflict()
            ->assertJsonPath('code', 'erp_trial_lifecycle_required');
        $this->assertSame('trialing', $subscription->fresh()->status);
    }

    public function test_company_owner_sees_trial_dates_and_current_situation(): void
    {
        $company = ErpCompany::factory()->create(['cnpj' => '30288513000100']);
        $owner = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
        ]);
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'trialing',
        ]);
        $startedAt = now()->utc()->startOfSecond()->subHours(12);
        $endsAt = $startedAt->copy()->addHours(168);
        DB::table('erp_subscription_trials')->insert([
            'erp_subscription_id' => $subscription->id,
            'erp_company_id' => $company->id,
            'account_id' => Account::factory()->create()->id,
            'cnpj' => $company->cnpj,
            'started_at' => $startedAt,
            'ends_at' => $endsAt,
            'link_revision' => 1,
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);

        $this->actingAs($owner)
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk()
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.trial.situation', 'trialing')
            ->assertJsonPath('data.trial.ends_at', $endsAt->toIso8601String());

        $viewer = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $viewer->id,
            'role' => 'viewer',
        ]);
        $this->actingAs($viewer)
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk()
            ->assertJsonPath('data.trial.started_at', $startedAt->toIso8601String())
            ->assertJsonPath('data.trial.ends_at', $endsAt->toIso8601String())
            ->assertJsonPath('data.trial.situation', 'trialing');

        $this->travelTo($endsAt);
        $this->actingAs($owner)
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id)
            ->getJson('/api/v1/erp/subscription')
            ->assertOk()
            ->assertJsonPath('data.trial.situation', 'expired');
    }

    public function test_erp_subscription_status_check_rejects_unknown_values(): void
    {
        $company = ErpCompany::factory()->create();
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'status' => 'pending',
        ]);

        try {
            DB::transaction(function () use ($subscription): void {
                DB::table('erp_subscriptions')->where('id', $subscription->id)->update(['status' => 'unknown']);
            });
            self::fail('The ERP subscription status constraint must reject unknown values.');
        } catch (QueryException) {
            self::assertSame('pending', $subscription->fresh()->status);
        }
    }

    private function csrfToken(): string
    {
        return $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }
}
