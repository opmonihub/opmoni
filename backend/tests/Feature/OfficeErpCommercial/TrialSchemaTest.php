<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Database\QueryException;
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
        $startedAt = now()->utc()->subHours(12);
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
