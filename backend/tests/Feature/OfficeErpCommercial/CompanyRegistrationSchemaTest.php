<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\OfficeErpCommercialTestCase;

class CompanyRegistrationSchemaTest extends OfficeErpCommercialTestCase
{
    public function test_additive_migration_preserves_existing_records_and_uses_nullable_defaults(): void
    {
        $migration = require database_path('migrations/2026_10_28_173939_add_office_erp_company_registrations.php');
        $this->assertTrue(Schema::hasTable('office_erp_company_registrations'));
        $accessMigration = require database_path('migrations/2026_10_28_174126_create_office_erp_member_access.php');
        $ctx = $this->officeContext();
        $fixtures = $this->legacyRecords($ctx);
        $before = $this->snapshotLegacyRows();
        $configHash = $this->protectedConfigHash();
        $laterMigrations = [];
        foreach ([
            '2026_10_28_201500_add_authority_invalidation_to_office_erp_access_deliveries.php',
            '2026_10_28_230000_add_recovery_completion_receipts.php',
            '2026_10_28_233000_add_recovery_completion_nonce_hash.php',
        ] as $migrationFile) {
            $migrationPath = database_path('migrations/'.$migrationFile);
            if (is_file($migrationPath)) {
                $laterMigrations[] = require $migrationPath;
            }
        }

        $this->loginThroughRoute($ctx->actor, 0);
        foreach (array_reverse($laterMigrations) as $laterMigration) {
            $laterMigration->down();
        }
        $accessMigration->down();
        $migration->down();
        $migration->up();
        $accessMigration->up();
        foreach ($laterMigrations as $laterMigration) {
            $laterMigration->up();
        }
        self::assertTrue(Schema::hasColumn('office_erp_access_deliveries', 'password_recovery_completion_nonce_hash'));

        self::assertSame($before, $this->snapshotLegacyRows());
        self::assertSame($configHash, $this->protectedConfigHash());
        self::assertSame($fixtures['idempotency_keys'], DB::table('office_erp_commercial_operations')->orderBy('id')->pluck('idempotency_key')->all());
        self::assertSame(['occupied', 'occupied', 'released'], DB::table('office_erp_licence_allocations')->orderBy('id')->pluck('state')->all());
        self::assertSame(['active', 'trialing', 'canceled'], DB::table('erp_subscriptions')->orderBy('id')->pluck('status')->all());
        self::assertSame(1, DB::table('erp_subscription_trials')->count());
        self::assertSame(2, DB::table('office_erp_invoices')->count(), 'Trial subscriptions must not be represented by paid invoices.');
        foreach ($fixtures['user_ids'] as $userId) {
            self::assertSame(0, (int) DB::table('users')->where('id', $userId)->value('access_auth_revision'));
        }
        self::assertSame(3, DB::table('office_erp_contracts')->whereNull('planned_start_on')->count());
        self::assertSame(3, DB::table('office_erp_contract_revisions')->whereNull('superseded_at')->count());
        self::assertSame(3, DB::table('clients')->whereNull('contact_person')->whereNull('whatsapp')->count());
        $this->assertDatabaseCount('office_erp_company_registrations', 0);
        $this->assertDatabaseCount('erp_company_member_invitations', 0);
        $this->assertDatabaseCount('office_erp_access_deliveries', 0);

        $this->getJson('/api/v1/me')->assertOk();
        DB::table('users')->where('id', $ctx->actor->id)->update(['access_auth_revision' => 1]);
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->assertGuest('web');
        $this->loginThroughRoute($ctx->actor, 1);
        $this->getJson('/api/v1/me')->assertOk();
        self::assertSame($configHash, $this->protectedConfigHash());

        $beforeRepeat = $this->snapshotCurrentRows();
        self::assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
        self::assertSame($beforeRepeat, $this->snapshotCurrentRows());
        self::assertSame($configHash, $this->protectedConfigHash());
    }

    public function test_registration_accepts_only_a_client_from_the_same_account(): void
    {
        $account = Account::factory()->create();
        $foreign = Client::factory()->company()->create();
        $company = ErpCompany::factory()->create();
        $this->assertTrue(Schema::hasTable('office_erp_company_registrations'));
        $this->expectException(QueryException::class);
        DB::table('office_erp_company_registrations')->insert($this->registration($account->id, $foreign->id, $company->id));
    }

    public function test_one_client_cannot_be_registered_twice_in_an_office(): void
    {
        $ctx = $this->officeContext();
        $client = $this->clientFor($ctx);
        DB::table('office_erp_company_registrations')->insert($this->registration($ctx->accountId, $client->id, ErpCompany::factory()->create()->id));
        $this->assertTrue(Schema::hasTable('office_erp_company_registrations'));
        $this->expectException(QueryException::class);
        DB::table('office_erp_company_registrations')->insert($this->registration($ctx->accountId, $client->id, ErpCompany::factory()->create()->id));
    }

    public function test_one_company_cannot_have_two_clients_in_the_same_office(): void
    {
        $ctx = $this->officeContext();
        $company = ErpCompany::factory()->create();
        DB::table('office_erp_company_registrations')->insert($this->registration($ctx->accountId, $this->clientFor($ctx)->id, $company->id));
        $this->assertTrue(Schema::hasTable('office_erp_company_registrations'));
        $this->expectException(QueryException::class);
        DB::table('office_erp_company_registrations')->insert($this->registration($ctx->accountId, $this->clientFor($ctx)->id, $company->id));
    }

    public function test_a_company_can_retain_distinct_historical_office_registrations(): void
    {
        $company = ErpCompany::factory()->create();
        foreach ([$this->officeContext(), $this->officeContext()] as $ctx) {
            DB::table('office_erp_company_registrations')->insert($this->registration($ctx->accountId, $this->clientFor($ctx)->id, $company->id));
        }
        $this->assertDatabaseCount('office_erp_company_registrations', 2);
    }

    private function registration(int $accountId, int $clientId, int $companyId): array
    {
        return ['account_id' => $accountId, 'client_id' => $clientId, 'erp_company_id' => $companyId, 'registered_at' => now(), 'created_at' => now(), 'updated_at' => now()];
    }

    /** @return array{user_ids: list<int>, idempotency_keys: list<string>} */
    private function legacyRecords(OfficeErpExecutionContext $ctx): array
    {
        $erpPlan = ErpPlan::factory()->create(['slug' => 'schema-preservation-'.bin2hex(random_bytes(6))]);
        $erpPlan->modules()->create(['module_key' => 'purchases', 'quota' => null]);
        $userIds = [(int) $ctx->actor->id];
        $idempotencyKeys = [];

        foreach (['paid', 'trial', 'historical'] as $kind) {
            $contract = $this->acceptedContract($ctx);
            $company = ErpCompany::factory()->create();
            $status = match ($kind) {
                'paid' => 'effective',
                'trial' => 'accepted',
                default => 'canceled',
            };
            $contract->forceFill(['erp_company_id' => $company->id, 'agreement_status' => $status])->save();

            $member = User::factory()->create(['email' => 'schema-member-'.$kind.'-'.bin2hex(random_bytes(5)).'@example.test']);
            $userIds[] = (int) $member->id;
            ErpCompanyMembership::factory()->create([
                'erp_company_id' => $company->id,
                'user_id' => $member->id,
                'role' => 'owner',
            ]);

            $subscription = ErpSubscription::factory()->create([
                'erp_company_id' => $company->id,
                'erp_plan_id' => $erpPlan->id,
                'office_erp_contract_id' => $contract->id,
                'status' => match ($kind) {
                    'paid' => 'active',
                    'trial' => 'trialing',
                    default => 'canceled',
                },
            ]);

            $allocationState = $kind === 'historical' ? 'released' : 'occupied';
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $ctx->accountId,
                'contract_id' => $contract->id,
                'erp_company_id' => $company->id,
                'state' => $allocationState,
                'expires_at' => null,
                'released_at' => $kind === 'historical' ? now()->subDay() : null,
            ]);

            if ($kind === 'trial') {
                ErpSubscriptionTrial::query()->create([
                    'erp_subscription_id' => $subscription->id,
                    'erp_company_id' => $company->id,
                    'account_id' => $ctx->accountId,
                    'cnpj' => $company->cnpj,
                    'started_at' => now()->subDay(),
                    'ends_at' => now()->addDays(6),
                    'link_revision' => 1,
                ]);
            }
            $invoice = $kind === 'trial' ? null : $this->issuedInvoice($ctx, $contract);

            $key = 'schema-preservation-'.$kind;
            $idempotencyKeys[] = $key;
            $operation = OfficeErpCommercialOperation::factory()->create([
                'account_id' => $ctx->accountId,
                'actor_kind' => 'office_admin',
                'actor_identity' => $ctx->actorIdentity,
                'idempotency_key' => $key,
                'action' => $invoice === null ? 'trial.register' : 'invoice.issue',
                'payload_hash' => hash('sha256', $kind),
                'payload' => ['preservation_case' => $kind],
                'outcome' => $invoice === null ? ['erp_company_id' => $company->id] : ['invoice_id' => $invoice->id],
            ]);
            OfficeErpAuditEvent::factory()->create([
                'account_id' => $ctx->accountId,
                'operation_id' => $operation->id,
                'actor_kind' => 'office_admin',
                'actor_identity' => $ctx->actorIdentity,
                'actor_user_id' => $ctx->actor->id,
                'action' => $invoice === null ? 'trial.register' : 'invoice.issue',
                'metadata' => ['preservation_case' => $kind],
            ]);
        }

        return ['user_ids' => $userIds, 'idempotency_keys' => $idempotencyKeys];
    }

    /** @return array<string, array{ids: list<int|string>, hash: string}> */
    private function snapshotLegacyRows(): array
    {
        return $this->snapshotRows([
            'accounts' => [],
            'account_memberships' => [],
            'users' => ['access_auth_revision'],
            'plans' => [],
            'subscriptions' => [],
            'clients' => ['contact_person', 'whatsapp'],
            'erp_companies' => [],
            'erp_company_memberships' => [],
            'erp_plans' => [],
            'erp_subscriptions' => [],
            'erp_subscription_trials' => [],
            'office_erp_sales_plans' => [],
            'office_erp_sales_plan_revisions' => [],
            'office_erp_contracts' => ['planned_start_on'],
            'office_erp_contract_revisions' => ['superseded_at'],
            'office_erp_invoices' => [],
            'office_erp_invoice_counters' => [],
            'office_erp_licence_allocations' => [],
            'office_erp_commercial_operations' => [],
            'office_erp_audit_events' => [],
        ]);
    }

    /** @return array<string, array{ids: list<int|string>, hash: string}> */
    private function snapshotCurrentRows(): array
    {
        return $this->snapshotRows([
            'accounts' => [],
            'account_memberships' => [],
            'users' => [],
            'plans' => [],
            'subscriptions' => [],
            'clients' => [],
            'erp_companies' => [],
            'erp_company_memberships' => [],
            'erp_plans' => [],
            'erp_subscriptions' => [],
            'erp_subscription_trials' => [],
            'office_erp_sales_plans' => [],
            'office_erp_sales_plan_revisions' => [],
            'office_erp_contracts' => [],
            'office_erp_contract_revisions' => [],
            'office_erp_invoices' => [],
            'office_erp_invoice_counters' => [],
            'office_erp_licence_allocations' => [],
            'office_erp_commercial_operations' => [],
            'office_erp_audit_events' => [],
            'office_erp_company_registrations' => [],
            'erp_company_member_invitations' => [],
            'office_erp_access_deliveries' => [],
        ]);
    }

    /** @param array<string, list<string>> $tables @return array<string, array{ids: list<int|string>, hash: string}> */
    private function snapshotRows(array $tables): array
    {
        $snapshot = [];
        foreach ($tables as $table => $excludedColumns) {
            $rows = DB::table($table)->orderBy('id')->get()->map(static function ($row) use ($excludedColumns): array {
                return array_diff_key((array) $row, array_fill_keys($excludedColumns, true));
            })->all();
            $snapshot[$table] = [
                'ids' => array_map(static fn (array $row): int|string => is_int($row['id']) ? $row['id'] : (string) $row['id'], $rows),
                'hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            ];
        }

        return $snapshot;
    }

    private function loginThroughRoute(User $user, int $revision): void
    {
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertSessionHas('access_auth_revision', $revision);
    }

    private function protectedConfigHash(): string
    {
        $erpGates = array_intersect_key((array) config('erp'), array_fill_keys([
            'operating_writes_enabled',
            'registration_enabled',
            'office_trial_enabled',
            'release_enabled',
            'release_mode',
        ], true));

        return hash('sha256', json_encode([
            'app_key' => config('app.key'),
            'erp_gates' => $erpGates,
        ], JSON_THROW_ON_ERROR));
    }
}
