<?php

declare(strict_types=1);

use App\Erp\Commercial\OfficeErpBalances;
use App\Erp\Commercial\OfficeErpContracts;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpReceipts;
use App\Erp\ErpCommercialGate;
use App\Http\Resources\Erp\ErpSubscriptionResource;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpContractRevision;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

require '/workspace/backend/vendor/autoload.php';
$app = require '/workspace/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$phase = $argv[1] ?? '';
$statePath = $argv[2] ?? '';
$commercialMigrations = [
    '2026_10_24_090000_add_erp_commercial_to_plans_table',
    '2026_10_24_100000_create_office_erp_commercial_core',
    '2026_10_24_110000_create_office_erp_billing',
    '2026_10_24_120000_create_office_erp_receipt_proofs',
    '2026_10_24_130000_create_office_erp_owner_invitations',
];
$commercialTables = [
    'office_erp_sales_plans', 'office_erp_sales_plan_revisions', 'office_erp_contracts',
    'office_erp_contract_revisions', 'office_erp_licence_allocations', 'office_erp_commercial_operations',
    'office_erp_audit_events', 'office_erp_invoice_counters', 'office_erp_invoices',
    'office_erp_invoice_adjustments', 'office_erp_receipts', 'office_erp_receipt_allocations',
    'office_erp_receipt_reversals', 'office_erp_commercial_settings', 'office_erp_receipt_proofs',
    'office_erp_owner_invitations',
];
$legacyTables = [
    'accounts', 'account_memberships', 'subscriptions', 'plans', 'clients',
    'erp_companies', 'erp_company_memberships', 'erp_subscriptions', 'users',
];

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$readState = static function () use ($statePath): array {
    if ($statePath === '' || ! is_file($statePath) || is_link($statePath)) {
        return [];
    }
    $state = json_decode((string) file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);

    return is_array($state) ? $state : [];
};
$writeState = static function (array $state) use ($statePath): void {
    $temporary = $statePath.'.tmp';
    file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
    chmod($temporary, 0600);
    rename($temporary, $statePath);
};
$markPhase = static function (array &$state, string $marker) use ($writeState): void {
    $state['probe_markers'] ??= [];
    if (! in_array($marker, $state['probe_markers'], true)) {
        $state['probe_markers'][] = $marker;
    }
    $writeState($state);
};
$assertRuntime = static function () use ($assert): void {
    $assert(app()->environment('local'), 'Expected private local Laravel environment.');
    $assert(config('database.default') === 'pgsql', 'Expected PostgreSQL connection.');
    $assert(config('database.connections.pgsql.host') === 'postgres', 'Unexpected PostgreSQL host.');
    $assert(config('database.connections.pgsql.database') === 'opmoni_test', 'Unsafe database name.');
    $server = DB::selectOne("select current_database() as name, current_setting('server_version_num')::integer as version");
    $assert($server->name === 'opmoni_test' && $server->version >= 180000 && $server->version < 190000, 'Expected isolated PostgreSQL 18.');
    foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $number) {
        $redis = Redis::connection($connection)->client();
        $assert(config('database.redis.'.$connection.'.host') === 'redis', 'Unexpected Redis host.');
        $assert($redis->getDBNum() === $number, 'Unexpected Redis database.');
        $assert($redis->getOption(\Redis::OPT_PREFIX) === 'opmoni-office-erp-commercial-rehearsal-', 'Unexpected Redis key prefix.');
        $assert(str_starts_with($redis->info('server')['redis_version'], '8.'), 'Expected Redis 8.');
    }
    foreach (['registration_enabled', 'release_enabled', 'fiscal_production_enabled'] as $gate) {
        $assert(config('erp.'.$gate) === false, 'ERP launch gate must remain disabled.');
    }
    $assert(config('erp.operating_writes_enabled') === true && config('erp.release_mode') === 'preview', 'Existing operating/release preview configuration changed.');
    $assert(config('erp.fiscal_provider') === 'fake' && config('whatsapp.enabled') === false, 'External integrations must remain disabled.');
    foreach (['cte_enabled', 'nfse_enabled', 'manifestacao_enabled'] as $gate) {
        $assert(config('fiscal.'.$gate) === false, 'Office Fiscal launch gate must remain disabled.');
    }
    $assert(config('fiscal.manifestacao_rollout_mode') === 'disabled', 'Fiscal manifestation rollout must remain disabled.');
};
$tableSnapshot = static function (array $tables, array $excludedColumns = []): array {
    $snapshot = [];
    foreach ($tables as $table) {
        if (! Schema::hasTable($table)) {
            $snapshot[$table] = ['count' => null, 'sha256' => null];

            continue;
        }
        $columns = array_values(array_diff(Schema::getColumnListing($table), $excludedColumns[$table] ?? []));
        $rows = DB::table($table)->select($columns)->orderByRaw('to_jsonb('.$table.')::text')->get()->map(static fn ($row): array => (array) $row)->all();
        $snapshot[$table] = ['count' => count($rows), 'sha256' => hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }

    return $snapshot;
};
$schemaDigest = static function (array $tables): string {
    $columns = DB::table('information_schema.columns')->select('table_name', 'column_name', 'data_type', 'is_nullable', 'column_default')
        ->where('table_schema', 'public')->whereIn('table_name', $tables)->orderBy('table_name')->orderBy('ordinal_position')->get()->all();
    $indexes = DB::table('pg_indexes')->select('tablename', 'indexname', 'indexdef')->where('schemaname', 'public')
        ->whereIn('tablename', $tables)->orderBy('tablename')->orderBy('indexname')->get()->all();
    $triggers = DB::table('information_schema.triggers')->select('event_object_table as table_name', 'trigger_name', 'action_timing', 'event_manipulation', 'action_statement')
        ->where('trigger_schema', 'public')->whereIn('event_object_table', $tables)->orderBy('event_object_table')->orderBy('trigger_name')->get()->all();

    return hash('sha256', json_encode([$columns, $indexes, $triggers], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};
$migrationNames = static fn (): array => DB::table('migrations')->orderBy('migration')->pluck('migration')->all();
$legacySnapshot = static function () use ($legacyTables, $tableSnapshot, $commercialMigrations): array {
    $migration = Schema::hasTable('migrations')
        ? DB::table('migrations')->whereNotIn('migration', $commercialMigrations)->orderBy('migration')->get(['migration', 'batch'])->all()
        : [];
    $legacyColumns = ['plans' => ['erp_commercial'], 'erp_subscriptions' => ['office_erp_contract_id']];

    return ['tables' => $tableSnapshot($legacyTables, $legacyColumns), 'migrations' => hash('sha256', json_encode($migration, JSON_THROW_ON_ERROR))];
};
$fullSnapshot = static function () use ($legacyTables, $commercialTables, $tableSnapshot, $migrationNames, $schemaDigest): array {
    $tables = array_merge($legacyTables, $commercialTables, ['migrations']);

    return [
        'tables' => $tableSnapshot($tables),
        'migrations' => hash('sha256', json_encode($migrationNames(), JSON_THROW_ON_ERROR)),
        'schema' => $schemaDigest(array_merge($legacyTables, $commercialTables)),
        'app_key_digest' => hash('sha256', (string) config('app.key')),
    ];
};

try {
    $assertRuntime();
    $state = $readState();
    if ($phase === 'preflight') {
        $assert(! Schema::hasTable('migrations'), 'Private database already has a schema; refusing to reuse it.');
        foreach (['accounts', 'users', 'clients', 'erp_companies', 'erp_subscriptions'] as $table) {
            $assert(! Schema::hasTable($table), 'Private database already has application tables; refusing to reuse it.');
        }
        $markPhase($state, 'preflight');
        echo "PASS preflight\n";
        exit(0);
    }

    if ($phase === 'seed-baseline') {
        $assert(! isset($state['baseline_ids']), 'Baseline fixture already exists; preserving it.');
        foreach ($commercialMigrations as $migration) {
            $assert(! in_array($migration, $migrationNames(), true), 'Baseline unexpectedly contains commercial migrations.');
        }
        $account = Account::query()->where('name', 'P5.3 rehearsal office')->first();
        $ids = DB::transaction(static function () use ($account): array {
            if ($account === null) {
                $account = Account::factory()->create(['name' => 'P5.3 rehearsal office', 'status' => 'active']);
                $actor = User::factory()->create(['email' => 'office-erp-p53-rehearsal@example.test']);
                $membership = AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $actor->id, 'role' => 'admin']);
                $client = app(CurrentTenant::class)->run((int) $account->id, static fn (): Client => Client::factory()->company()->create(['account_id' => $account->id, 'name' => 'P5.3 pre-existing client']));
                $company = ErpCompany::factory()->create(['name' => 'P5.3 pre-existing ERP company', 'cnpj' => '10000000000001']);
                $companyMembership = ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $actor->id, 'role' => 'owner']);
                $plan = ErpPlan::factory()->create(['slug' => 'p53-rehearsal-technical', 'name' => 'P5.3 rehearsal technical']);
                $subscription = ErpSubscription::factory()->create(['erp_company_id' => $company->id, 'erp_plan_id' => $plan->id, 'status' => 'pending']);
            } else {
                $actor = User::query()->where('email', 'office-erp-p53-rehearsal@example.test')->firstOrFail();
                $membership = AccountMembership::query()->where('account_id', $account->id)->where('user_id', $actor->id)->where('role', 'admin')->firstOrFail();
                $client = Client::withoutGlobalScopes()->where('account_id', $account->id)->where('name', 'P5.3 pre-existing client')->firstOrFail();
                $company = ErpCompany::query()->where('name', 'P5.3 pre-existing ERP company')->where('cnpj', '10000000000001')->firstOrFail();
                $companyMembership = ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $actor->id)->where('role', 'owner')->firstOrFail();
                $plan = ErpPlan::query()->where('slug', 'p53-rehearsal-technical')->firstOrFail();
                $subscription = ErpSubscription::query()->where('erp_company_id', $company->id)->firstOrFail();
            }

            return ['account' => $account->id, 'membership' => $membership->id, 'user' => $actor->id, 'client' => $client->id,
                'company' => $company->id, 'company_membership' => $companyMembership->id, 'erp_plan' => $plan->id, 'erp_subscription' => $subscription->id,
                'account_subscription' => (int) Subscription::query()->where('account_id', $account->id)->value('id')];
        });
        $state['baseline_ids'] = $ids;
        $state['baseline_snapshot'] = $legacySnapshot();
        $state['baseline_app_key_digest'] = hash('sha256', (string) config('app.key'));
        $markPhase($state, 'seed-baseline');
        $writeState($state);
        echo "PASS baseline seed and immutable data snapshot\n";
        exit(0);
    }

    if ($phase === 'verify-forward') {
        $assert(isset($state['baseline_ids'], $state['baseline_snapshot']), 'Missing baseline provenance.');
        $applied = $migrationNames();
        $commercialApplied = array_values(array_filter($applied, static fn (string $name): bool => in_array($name, $commercialMigrations, true)));
        $assert($commercialApplied === $commercialMigrations, 'Expected exactly the five additive commercial migration records.');
        foreach ($commercialTables as $table) {
            $assert(Schema::hasTable($table), 'Missing migrated commercial table '.$table.'.');
        }
        $requiredConstraints = [
            'office_erp_contract_client_account_fk', 'office_erp_contract_current_revision_fk',
            'office_erp_operations_identity_unique', 'office_erp_invoices_account_number_unique',
            'office_erp_allocations_receipt_invoice_unique', 'office_erp_owner_invitation_contract_fk',
            'erp_subscriptions_office_contract_company_fk',
        ];
        $validated = DB::table('pg_constraint')->whereIn('conname', $requiredConstraints)->where('convalidated', true)->pluck('conname')->all();
        $assert(array_diff($requiredConstraints, $validated) === [], 'A required commercial foreign key or unique constraint is missing or unvalidated.');
        $recurringIndex = DB::table('pg_indexes')->where('schemaname', 'public')->where('indexname', 'office_erp_invoices_recurring_period_unique')->exists();
        $assert($recurringIndex, 'Commercial recurring invoice index is missing.');
        $triggers = DB::table('information_schema.triggers')->where('trigger_schema', 'public')
            ->whereIn('trigger_name', ['office_erp_invoice_adjustments_append_only', 'office_erp_receipt_reversals_append_only'])
            ->distinct()->count('trigger_name');
        $assert($triggers === 2, 'Append-only billing triggers are missing.');
        $assert(DB::table('erp_subscriptions')->whereNotNull('office_erp_contract_id')->count() === 0, 'Pre-existing subscriptions were unexpectedly linked during migration.');
        $after = $legacySnapshot();
        $assert($after === $state['baseline_snapshot'], 'Pre-existing office, membership, subscription, client, or ERP data changed during migration.');
        $assert(hash('sha256', (string) config('app.key')) === $state['baseline_app_key_digest'], 'APP_KEY changed during the rehearsal.');
        $state['post_migration_legacy_snapshot'] = $after;
        $state['post_migration_schema_digest'] = $schemaDigest(array_merge($legacyTables, $commercialTables));
        $markPhase($state, 'verify-forward');
        $writeState($state);
        echo "PASS additive migration preservation, tables, and five migration records\n";
        exit(0);
    }

    if ($phase === 'commercial-fixture') {
        $assert(isset($state['baseline_ids'], $state['post_migration_legacy_snapshot']), 'Migration preservation phase is incomplete.');
        $assert(! isset($state['fixture_ids']), 'Commercial fixture is already recorded; preserving it.');
        $ids = $state['baseline_ids'];
        $fixture = DB::transaction(static function () use ($ids, $assert): array {
            $account = Account::query()->findOrFail($ids['account']);
            $actor = User::query()->findOrFail($ids['user']);
            $membership = AccountMembership::query()->findOrFail($ids['membership']);
            $accountSubscription = Subscription::query()->where('account_id', $account->id)->firstOrFail();
            $officePlan = Plan::query()->where('slug', 'p53-rehearsal-office-plan')->first()
                ?? Plan::factory()->create([
                    'slug' => 'p53-rehearsal-office-plan',
                    'erp_commercial' => ['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']],
                ]);
            $accountSubscription->forceFill(['plan_id' => $officePlan->id, 'status' => 'active'])->save();
            request()->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));
            $ctx = OfficeErpExecutionContext::officeAdmin($account, $actor, 'p5.3-rehearsal:'.$account->id);
            $company = ErpCompany::query()->findOrFail($ids['company']);
            $subscription = ErpSubscription::query()->findOrFail($ids['erp_subscription']);
            $salesPlan = OfficeErpSalesPlan::query()->where('account_id', $account->id)->where('name', 'P5.3 rehearsal plan')->first()
                ?? OfficeErpSalesPlan::factory()->create(['account_id' => $account->id, 'name' => 'P5.3 rehearsal plan']);
            $salesRevision = OfficeErpSalesPlanRevision::query()->where('account_id', $account->id)->where('sales_plan_id', $salesPlan->id)->where('revision_number', 1)->first()
                ?? OfficeErpSalesPlanRevision::factory()->create([
                    'account_id' => $account->id, 'sales_plan_id' => $salesPlan->id, 'revision_number' => 1,
                    'name' => 'P5.3 rehearsal plan', 'price' => '100.00', 'interval_months' => 1, 'due_in_days' => 10,
                    'module_keys' => ['purchases'], 'published_at' => now(),
                ]);
            $salesPlan->forceFill(['current_revision_id' => $salesRevision->id])->save();
            $contract = OfficeErpContract::query()->where('account_id', $account->id)->where('client_id', $ids['client'])
                ->where('sales_plan_id', $salesPlan->id)->first() ?? OfficeErpContract::factory()->create([
                    'account_id' => $account->id, 'client_id' => $ids['client'], 'sales_plan_id' => $salesPlan->id,
                    'erp_company_id' => $company->id, 'agreement_status' => 'accepted', 'accepted_at' => now(),
                    'billing_timezone' => 'America/Sao_Paulo',
                ]);
            $contractRevision = OfficeErpContractRevision::query()->where('account_id', $account->id)->where('contract_id', $contract->id)->where('revision_number', 1)->first()
                ?? OfficeErpContractRevision::factory()->create([
                    'account_id' => $account->id, 'contract_id' => $contract->id, 'revision_number' => 1,
                    'sales_plan_revision_id' => $salesRevision->id, 'price' => '100.00', 'currency' => 'BRL',
                    'interval_months' => 1, 'due_in_days' => 10, 'module_keys' => ['purchases'], 'created_by_user_id' => $actor->id,
                ]);
            $contract->forceFill(['current_revision_id' => $contractRevision->id])->save();
            if (! OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->exists()) {
                OfficeErpLicenceAllocation::factory()->create([
                    'account_id' => $account->id, 'contract_id' => $contract->id, 'erp_company_id' => $company->id,
                    'state' => 'occupied', 'expires_at' => null,
                ]);
            }
            $activation = app(OfficeErpContracts::class)->activate($ctx, $contract, 'p53-activate-'.$account->id);
            $invoice = OfficeErpInvoice::query()->findOrFail($activation['invoice_id']);
            $receipts = app(OfficeErpReceipts::class);
            $receivedOn = $invoice->issued_at->toDateString();
            $first = $receipts->post($ctx, ['amount' => '40.00', 'received_on' => $receivedOn, 'allocations' => [['invoice_id' => $invoice->id, 'amount' => '40.00']]], 'p53-receipt-40-'.$account->id);
            $balanceAfterForty = OfficeErpBalances::forInvoice($invoice->fresh());
            $second = $receipts->post($ctx, ['amount' => '60.00', 'received_on' => $receivedOn, 'allocations' => [['invoice_id' => $invoice->id, 'amount' => '60.00']]], 'p53-receipt-60-'.$account->id);
            $settledBalance = OfficeErpBalances::forInvoice($invoice->fresh());
            $receipts->reverse($ctx, $second, 'Rehearsal reversal', 'p53-reversal-'.$account->id);
            $balanceAfterReversal = OfficeErpBalances::forInvoice($invoice->fresh());
            app(OfficeErpContracts::class)->suspend($ctx, $contract->fresh(), 'Rehearsal manual hold', 'p53-suspend-'.$account->id);
            $assert($balanceAfterForty['outstanding'] === '60.00', 'Forty receipt did not leave sixty outstanding.');
            $assert($settledBalance['outstanding'] === '0.00', 'Settlement did not clear outstanding balance.');
            $assert($balanceAfterReversal['outstanding'] === '60.00', 'Reversal did not restore sixty outstanding.');
            $assert($contract->fresh()->manual_hold === true && $subscription->fresh()->status === 'suspended', 'Manual hold/suspension was not persisted.');

            return ['account' => $account->id, 'contract' => $contract->id, 'invoice' => $invoice->id,
                'receipt_partial' => $first->id, 'receipt_settlement' => $second->id, 'company' => $company->id,
                'subscription' => $subscription->id, 'status' => $subscription->fresh()->status];
        });
        $state['fixture_ids'] = $fixture;
        $state['fixture_snapshot'] = $fullSnapshot();
        $markPhase($state, 'commercial-fixture');
        $writeState($state);
        echo "PASS service-created invoice, partial receipt, settlement, reversal, and suspended manual hold\n";
        exit(0);
    }

    if ($phase === 'compatible-readonly' || $phase === 'current-readonly') {
        $assert(isset($state['fixture_snapshot'], $state['fixture_ids']), 'Commercial fixture has not been snapshotted.');
        $before = $fullSnapshot();
        $assert(isset($state['post_migration_schema_digest']) && $before['schema'] === $state['post_migration_schema_digest'], 'Schema differs from the accepted post-migration snapshot.');
        $ids = $state['fixture_ids'];
        $company = ErpCompany::query()->findOrFail($ids['company']);
        $subscription = ErpSubscription::query()->whereKey($ids['subscription'])->where('erp_company_id', $company->id)->firstOrFail();
        $assert($subscription->status === 'suspended', 'Expected suspended subscription for old-app compatibility probe.');
        $assert(app(ErpCommercialGate::class)->allowsOperatingWrites($company) === false, 'Suspended subscription unexpectedly allows ERP writes.');
        $subscription->loadMissing('company', 'plan');
        $resource = new ErpSubscriptionResource($subscription);
        $resource->resolve(request());
        $assert($company->subscription()->whereKey($subscription->id)->exists(), 'Legacy company subscription lookup failed.');
        $after = $fullSnapshot();
        $assert($before === $after && $after === $state['fixture_snapshot'], 'Read-only compatible/current probe changed data, schema, migrations, or key digest.');
        $state[$phase.'_snapshot'] = $after;
        $markPhase($state, $phase);
        $writeState($state);
        echo 'PASS '.$phase.' bounded read-only gates/resource and identical history digests'."\n";
        exit(0);
    }

    throw new InvalidArgumentException('Unknown rehearsal probe phase.');
} catch (Throwable $exception) {
    $code = (string) $exception->getCode();
    $safeCode = preg_match('/^[A-Z0-9]{5}$/', $code) === 1 ? ', SQLSTATE '.$code : '';
    fwrite(STDERR, 'ABORT probe phase '.$phase.' ('.get_class($exception).$safeCode.')'.PHP_EOL);
    exit(1);
}
