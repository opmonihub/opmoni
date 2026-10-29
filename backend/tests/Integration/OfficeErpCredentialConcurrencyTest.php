<?php

namespace Tests\Integration;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\Commercial\OfficeErpMemberCredentials;
use App\Erp\ErpCommercialCatalog;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

class OfficeErpCredentialConcurrencyTest extends OfficeErpCommercialProcessTestCase
{
    private string $credentialDirectory;

    /** @var list<Process> */
    private array $credentialWorkers = [];

    /** @var array<string, array{column:string,ids:list<int|string>,hash:string}> */
    private array $credentialBaselines = [];

    /** @var array<string, string> */
    private array $credentialSourceBaseline = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(0, DB::transactionLevel());
        $this->credentialDirectory = sys_get_temp_dir().'/opmoni-credential-races-'.bin2hex(random_bytes(10));
        if (! mkdir($this->credentialDirectory, 0700)) {
            throw new RuntimeException('Unable to create the credential race synchronization directory.');
        }
        $broker = (string) config('auth.defaults.passwords', 'users');
        foreach ([
            'office_erp_company_registrations' => 'id',
            'erp_company_member_invitations' => 'id',
            'office_erp_access_deliveries' => 'id',
            (string) config("auth.passwords.{$broker}.table") => 'email',
        ] as $table => $column) {
            self::assertTrue(Schema::hasTable($table), 'The credential race requires migrated table '.$table.'.');
            $rows = DB::table($table)->orderBy($column)->get();
            $this->credentialBaselines[$table] = [
                'column' => $column,
                'ids' => $rows->pluck($column)->map(static fn ($value): int|string => is_int($value) ? $value : (string) $value)->all(),
                'hash' => hash('sha256', $rows->toJson()),
            ];
        }
        $this->credentialSourceBaseline = self::sourceHashes();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->credentialWorkers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            if (isset($this->credentialDirectory)) {
                foreach (glob($this->credentialDirectory.'/*') ?: [] as $path) {
                    unlink($path);
                }
                rmdir($this->credentialDirectory);
            }

            foreach ($this->credentialBaselines as $table => $baseline) {
                $rows = $baseline['ids'] === []
                    ? collect()
                    : DB::table($table)->whereIn($baseline['column'], $baseline['ids'])->orderBy($baseline['column'])->get();
                self::assertSame($baseline['hash'], hash('sha256', $rows->toJson()), 'Pre-existing credential rows changed: '.$table);
            }
            self::assertSame($this->credentialSourceBaseline, self::sourceHashes(), 'Credential race source identity changed during the run.');
        } finally {
            parent::tearDown();
        }
    }

    public function test_independent_reset_commands_with_the_same_expected_revision_have_one_winner(): void
    {
        $fixture = $this->credentialFixture();
        $barrier = $this->credentialDirectory.'/reset-race-release';
        $workers = [];
        $keys = [];
        foreach (['first', 'second'] as $suffix) {
            $key = 'credential-race-'.$suffix.'-'.bin2hex(random_bytes(8));
            $keys[] = $key;
            $workers[] = $this->credentialWorker([
                'action' => 'reset',
                'account_id' => $fixture['account_id'],
                'actor_id' => $fixture['actor_id'],
                'company_id' => $fixture['company_id'],
                'erp_membership_id' => $fixture['erp_membership_id'],
                'expected_revision' => 0,
                'idempotency_key' => $key,
                'password' => 'Race reset secret '.$suffix,
                'ip' => $suffix === 'first' ? '192.0.2.71' : '192.0.2.72',
                'release' => $barrier,
            ]);
        }
        $identities = array_map(fn (Process $worker): array => $this->awaitCredentialReady($worker), $workers);
        self::assertNotSame($identities[0]['pid'], $identities[1]['pid']);
        self::assertNotSame($identities[0]['database_pid'], $identities[1]['database_pid']);
        touch($barrier);
        $results = array_map($this->credentialResult(...), $workers);
        $statuses = array_column($results, 'status');
        sort($statuses, SORT_NUMERIC);
        self::assertSame([200, 409], $statuses);
        $this->assertDatabaseHas('users', ['id' => $fixture['target_user_id'], 'access_auth_revision' => 1]);
        self::assertSame(1, DB::table('office_erp_commercial_operations')->where('account_id', $fixture['account_id'])
            ->whereIn('idempotency_key', $keys)->count());
    }

    public function test_independent_account_admin_promotion_and_member_reset_serialize_without_deadlock(): void
    {
        $fixture = $this->credentialFixture();
        AccountMembership::factory()->create([
            'account_id' => $fixture['account_id'],
            'user_id' => $fixture['target_user_id'],
            'role' => 'user',
        ]);
        $barrier = $this->credentialDirectory.'/promotion-race-release';
        $reset = $this->credentialWorker([
            'action' => 'reset', 'account_id' => $fixture['account_id'], 'actor_id' => $fixture['actor_id'],
            'company_id' => $fixture['company_id'], 'erp_membership_id' => $fixture['erp_membership_id'],
            'expected_revision' => 0, 'idempotency_key' => 'promotion-reset-'.bin2hex(random_bytes(8)),
            'password' => 'Promotion race secret', 'ip' => '192.0.2.73', 'release' => $barrier,
        ]);
        $promotion = $this->credentialWorker([
            'action' => 'promote', 'account_id' => $fixture['account_id'], 'actor_id' => $fixture['actor_id'],
            'target_user_id' => $fixture['target_user_id'], 'ip' => '192.0.2.74', 'release' => $barrier,
        ]);
        $resetIdentity = $this->awaitCredentialReady($reset);
        $promotionIdentity = $this->awaitCredentialReady($promotion);
        self::assertNotSame($resetIdentity['database_pid'], $promotionIdentity['database_pid']);
        touch($barrier);

        $results = [$this->credentialResult($reset), $this->credentialResult($promotion)];
        self::assertContains($results[0]['status'], [200, 403]);
        self::assertSame(200, $results[1]['status'], 'Promotion exception class: '.$results[1]['exception_class']);
        self::assertNotContains(503, array_column($results, 'status'));
        self::assertDatabaseHas('account_memberships', ['account_id' => $fixture['account_id'], 'user_id' => $fixture['target_user_id'], 'role' => 'admin']);
        $revision = (int) DB::table('users')->where('id', $fixture['target_user_id'])->value('access_auth_revision');
        self::assertContains($revision, [0, 1]);
    }

    public function test_public_recovery_consume_races_direct_reset_and_only_one_password_effect_wins(): void
    {
        Queue::fake();
        $fixture = $this->credentialFixture();
        $issueRequest = Request::create('/api/v1/erp-commercial/recovery', 'POST', server: ['REMOTE_ADDR' => '192.0.2.77']);
        $issued = $this->officeAdmin($fixture, fn (): array => $fixture['context']->run(fn (): array => app(OfficeErpMemberCredentials::class)->issueRecovery(
            $issueRequest,
            $fixture['context'],
            $fixture['company_id'],
            $fixture['erp_membership_id'],
            'credential-race-seed-'.bin2hex(random_bytes(8)),
        )));
        $delivery = OfficeErpAccessDelivery::query()->findOrFail($issued['data']['delivery_id']);
        $token = $delivery->payload['token'];
        $completionPassword = 'Concurrent public recovery password';
        $resetPassword = 'Concurrent direct reset password';
        $barrier = $this->credentialDirectory.'/consume-reset-release';

        $completion = $this->credentialWorker([
            'action' => 'public_recovery',
            'delivery_id' => (int) $delivery->id,
            'token' => $token,
            'password' => $completionPassword,
            'ip' => '192.0.2.78',
            'release' => $barrier,
        ]);
        $resetKey = 'credential-race-reset-'.bin2hex(random_bytes(8));
        $reset = $this->credentialWorker([
            'action' => 'reset', 'account_id' => $fixture['account_id'], 'actor_id' => $fixture['actor_id'],
            'company_id' => $fixture['company_id'], 'erp_membership_id' => $fixture['erp_membership_id'],
            'expected_revision' => 0, 'idempotency_key' => $resetKey, 'password' => $resetPassword,
            'ip' => '192.0.2.79', 'release' => $barrier,
        ]);
        $completionIdentity = $this->awaitCredentialReady($completion);
        $resetIdentity = $this->awaitCredentialReady($reset);
        self::assertNotSame($completionIdentity['database_pid'], $resetIdentity['database_pid']);
        self::assertSame('valid', $completionIdentity['initial_recovery_state']);
        self::assertSame('valid', $completionIdentity['initial_current_state']);
        touch($barrier);

        $completionResult = $this->credentialResult($completion);
        $resetResult = $this->credentialResult($reset);
        $completionWon = $completionResult['completion_state'] === 'completed';
        $resetWon = $resetResult['status'] === 200;
        self::assertSame(1, (int) $completionWon + (int) $resetWon, 'Exactly one password-changing effect must win.');
        self::assertSame(1, (int) DB::table('users')->where('id', $fixture['target_user_id'])->value('access_auth_revision'));
        self::assertContains($completionResult['current_before_state'], ['valid', 'invalid']);
        self::assertSame($completionWon ? 'completed' : 'invalid', $completionResult['current_after_state']);
        self::assertNull($delivery->fresh()->payload);
        self::assertNotNull($delivery->fresh()->authority_invalidated_at);
        self::assertFalse(Password::broker()->tokenExists(User::query()->findOrFail($fixture['target_user_id']), $token));
        self::assertSame($resetWon ? 1 : 0, DB::table('office_erp_commercial_operations')
            ->where('account_id', $fixture['account_id'])->where('idempotency_key', $resetKey)->count());
        if ($completionWon) {
            self::assertTrue(Hash::check($completionPassword, User::query()->findOrFail($fixture['target_user_id'])->password));
            self::assertSame(409, $resetResult['status']);
        } else {
            self::assertTrue(Hash::check($resetPassword, User::query()->findOrFail($fixture['target_user_id'])->password));
            self::assertContains($completionResult['completion_state'], ['invalid', 'identity_mismatch']);
        }
    }

    public function test_two_public_receivers_completing_one_recovery_link_leave_only_winner_continuation_valid(): void
    {
        Queue::fake();
        $fixture = $this->credentialFixture();
        $issueRequest = Request::create('/api/v1/erp-commercial/recovery', 'POST', server: ['REMOTE_ADDR' => '192.0.2.90']);
        $issued = $this->officeAdmin($fixture, fn (): array => $fixture['context']->run(fn (): array => app(OfficeErpMemberCredentials::class)->issueRecovery(
            $issueRequest,
            $fixture['context'],
            $fixture['company_id'],
            $fixture['erp_membership_id'],
            'credential-two-receivers-seed-'.bin2hex(random_bytes(8)),
        )));
        $delivery = OfficeErpAccessDelivery::query()->findOrFail($issued['data']['delivery_id']);
        $token = $delivery->payload['token'];
        $passwords = ['Independent receiver password alpha', 'Independent receiver password beta'];
        $barrier = $this->credentialDirectory.'/two-receivers-complete-release';
        $workers = [];
        foreach ($passwords as $index => $password) {
            $workers[] = $this->credentialWorker([
                'action' => 'public_recovery',
                'delivery_id' => (int) $delivery->id,
                'token' => $token,
                'password' => $password,
                'ip' => '192.0.2.'.(91 + $index),
                'release' => $barrier,
            ]);
        }

        $identities = array_map(fn (Process $worker): array => $this->awaitCredentialReady($worker), $workers);
        self::assertNotSame($identities[0]['pid'], $identities[1]['pid']);
        self::assertNotSame($identities[0]['database_pid'], $identities[1]['database_pid']);
        foreach ($identities as $identity) {
            self::assertSame('valid', $identity['initial_recovery_state']);
            self::assertSame('valid', $identity['initial_current_state']);
        }

        touch($barrier);
        $results = array_map($this->credentialResult(...), $workers);
        $statuses = array_column($results, 'status');
        sort($statuses, SORT_NUMERIC);
        self::assertSame([200, 410], $statuses);
        $completionStates = array_column($results, 'completion_state');
        sort($completionStates);
        self::assertSame(['completed', 'invalid'], $completionStates);
        foreach ($results as $result) {
            self::assertSame($result['completion_state'], $result['current_after_state']);
            self::assertSame($result['completion_state'] === 'completed' ? 200 : 410, $result['status']);
        }

        $recipient = User::query()->findOrFail($fixture['target_user_id']);
        self::assertSame(1, (int) $recipient->access_auth_revision);
        $passwordMatches = array_map(static fn (string $password): bool => Hash::check($password, $recipient->password), $passwords);
        self::assertSame(1, count(array_filter($passwordMatches)), 'Exactly one of the independent receiver passwords must be persisted.');
        self::assertFalse(Password::broker()->tokenExists($recipient, $token));
        self::assertSame(1, DB::table('office_erp_audit_events')->where('action', 'member.password_recovery.complete')
            ->where('subject_id', $fixture['erp_membership_id'])->count());
        self::assertNotNull($delivery->fresh()->password_recovered_at);
        self::assertSame(1, (int) $delivery->fresh()->password_recovered_auth_revision);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $delivery->fresh()->password_recovery_completion_nonce_hash);
    }

    public function test_new_recovery_issue_wins_before_old_public_completion_and_invalidates_old_proof(): void
    {
        Queue::fake();
        $fixture = $this->credentialFixture();
        $oldDelivery = $this->seedRecoveryAuthority($fixture, 'recovery-reissue-seed-'.bin2hex(random_bytes(8)));
        $this->expireRecipientBudgetForFixture($fixture);
        $oldToken = $oldDelivery->payload['token'];
        $completionPassword = 'Old recovery completion secret';
        $completionRelease = $this->credentialDirectory.'/reissue-first-completion-release';
        $issueRelease = $this->credentialDirectory.'/reissue-first-issue-release';
        $completion = $this->credentialWorker([
            'action' => 'public_recovery', 'delivery_id' => (int) $oldDelivery->id,
            'token' => $oldToken, 'password' => $completionPassword,
            'ip' => '192.0.2.80', 'release' => $completionRelease,
        ]);
        $issueKey = 'recovery-reissue-first-'.bin2hex(random_bytes(8));
        $issue = $this->credentialWorker([
            'action' => 'issue_recovery', 'account_id' => $fixture['account_id'], 'actor_id' => $fixture['actor_id'],
            'company_id' => $fixture['company_id'], 'erp_membership_id' => $fixture['erp_membership_id'],
            'idempotency_key' => $issueKey, 'ip' => '192.0.2.81', 'release' => $issueRelease,
        ]);
        $completionIdentity = $this->awaitCredentialReady($completion);
        $issueIdentity = $this->awaitCredentialReady($issue);
        self::assertNotSame($completionIdentity['database_pid'], $issueIdentity['database_pid']);
        self::assertSame('valid', $completionIdentity['initial_recovery_state']);
        self::assertSame('valid', $completionIdentity['initial_current_state']);

        touch($issueRelease);
        self::assertSame(200, $this->credentialResult($issue)['status']);
        $newDelivery = $this->deliveryForOperationKey($fixture['account_id'], $issueKey);
        $newToken = $newDelivery->payload['token'];
        self::assertSame(0, (int) $newDelivery->access_auth_revision);
        self::assertTrue(Password::broker()->tokenExists(User::query()->findOrFail($fixture['target_user_id']), $newToken));

        touch($completionRelease);
        $completionResult = $this->credentialResult($completion);
        self::assertSame('invalid', $completionResult['completion_state']);
        self::assertSame('invalid', $completionResult['current_before_state']);
        self::assertSame('invalid', $completionResult['current_after_state']);
        self::assertSame(0, (int) DB::table('users')->where('id', $fixture['target_user_id'])->value('access_auth_revision'));
        self::assertNull($oldDelivery->fresh()->payload);
        self::assertNotNull($oldDelivery->fresh()->authority_invalidated_at);
        self::assertFalse(Password::broker()->tokenExists(User::query()->findOrFail($fixture['target_user_id']), $oldToken));
        self::assertTrue(Password::broker()->tokenExists(User::query()->findOrFail($fixture['target_user_id']), $newToken));
        self::assertNull($newDelivery->fresh()->authority_invalidated_at);
    }

    public function test_old_public_completion_wins_before_new_recovery_issue_and_issue_uses_revision_one(): void
    {
        Queue::fake();
        $fixture = $this->credentialFixture();
        $oldDelivery = $this->seedRecoveryAuthority($fixture, 'recovery-consume-seed-'.bin2hex(random_bytes(8)));
        $this->expireRecipientBudgetForFixture($fixture);
        $oldToken = $oldDelivery->payload['token'];
        $completionPassword = 'Winning recovery completion secret';
        $completionRelease = $this->credentialDirectory.'/consume-first-completion-release';
        $issueRelease = $this->credentialDirectory.'/consume-first-issue-release';
        $completion = $this->credentialWorker([
            'action' => 'public_recovery', 'delivery_id' => (int) $oldDelivery->id,
            'token' => $oldToken, 'password' => $completionPassword,
            'ip' => '192.0.2.82', 'release' => $completionRelease,
        ]);
        $issueKey = 'recovery-consume-first-'.bin2hex(random_bytes(8));
        $issue = $this->credentialWorker([
            'action' => 'issue_recovery', 'account_id' => $fixture['account_id'], 'actor_id' => $fixture['actor_id'],
            'company_id' => $fixture['company_id'], 'erp_membership_id' => $fixture['erp_membership_id'],
            'idempotency_key' => $issueKey, 'ip' => '192.0.2.83', 'release' => $issueRelease,
        ]);
        $completionIdentity = $this->awaitCredentialReady($completion);
        $issueIdentity = $this->awaitCredentialReady($issue);
        self::assertNotSame($completionIdentity['database_pid'], $issueIdentity['database_pid']);
        self::assertSame('valid', $completionIdentity['initial_recovery_state']);
        self::assertSame('valid', $completionIdentity['initial_current_state']);

        touch($completionRelease);
        $completionResult = $this->credentialResult($completion);
        self::assertSame(200, $completionResult['status']);
        self::assertSame('completed', $completionResult['completion_state']);
        self::assertSame('completed', $completionResult['current_after_state']);
        self::assertSame(1, (int) DB::table('users')->where('id', $fixture['target_user_id'])->value('access_auth_revision'));

        touch($issueRelease);
        self::assertSame(200, $this->credentialResult($issue)['status']);
        $newDelivery = $this->deliveryForOperationKey($fixture['account_id'], $issueKey);
        $newToken = $newDelivery->payload['token'];
        $recipient = User::query()->findOrFail($fixture['target_user_id']);
        self::assertSame(1, (int) $newDelivery->access_auth_revision);
        self::assertSame(1, (int) $recipient->access_auth_revision);
        self::assertTrue(Hash::check($completionPassword, $recipient->password));
        self::assertFalse(Password::broker()->tokenExists($recipient, $oldToken));
        self::assertTrue(Password::broker()->tokenExists($recipient, $newToken));
        self::assertNotNull($oldDelivery->fresh()->authority_invalidated_at);
        self::assertNull($oldDelivery->fresh()->payload);
        self::assertNull($newDelivery->fresh()->authority_invalidated_at);
    }

    public function test_redis_recipient_budget_allows_only_one_independent_process(): void
    {
        $barrier = $this->credentialDirectory.'/budget-race-release';
        $email = 'recovery-budget-'.bin2hex(random_bytes(8)).'@example.test';
        $workers = [];
        foreach (['first', 'second'] as $suffix) {
            $workers[] = $this->credentialWorker([
                'action' => 'budget', 'actor_id' => $suffix === 'first' ? 9000001 : 9000002,
                'email' => strtoupper($email), 'ip' => $suffix === 'first' ? '192.0.2.75' : '192.0.2.76',
                'release' => $barrier,
            ]);
        }
        $identities = array_map(fn (Process $worker): array => $this->awaitCredentialReady($worker), $workers);
        self::assertNotSame($identities[0]['database_pid'], $identities[1]['database_pid']);
        touch($barrier);
        $statuses = array_column(array_map($this->credentialResult(...), $workers), 'status');
        sort($statuses, SORT_NUMERIC);
        self::assertSame([204, 429], $statuses);
    }

    /** @return array<string, mixed> */
    private function credentialFixture(): array
    {
        self::assertSame(0, DB::transactionLevel());
        $office = $this->commercialFixture(3);
        $contract = $office['contracts'][0];
        $client = Client::withoutGlobalScopes()
            ->where('account_id', $office['account_id'])
            ->findOrFail($contract->client_id);
        $company = ErpCompany::factory()->create([
            'name' => 'Credential race company '.bin2hex(random_bytes(5)),
            'cnpj' => $client->tax_id,
        ]);
        $contract->forceFill(['erp_company_id' => $company->id, 'agreement_status' => 'accepted'])->save();
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'office_erp_contract_id' => $contract->id,
        ]);
        $registration = OfficeErpCompanyRegistration::query()->create([
            'account_id' => $office['account_id'],
            'client_id' => $contract->client_id,
            'erp_company_id' => $company->id,
            'created_by_user_id' => $office['actor_id'],
            'registered_at' => now(),
        ]);
        $target = User::factory()->create(['email' => 'target-'.bin2hex(random_bytes(8)).'@example.test']);
        $membership = ErpCompanyMembership::factory()->role(ErpCompanyRole::Operator)->create([
            'erp_company_id' => $company->id,
            'user_id' => $target->id,
        ]);

        return [
            'account_id' => (int) $office['account_id'],
            'actor_id' => (int) $office['actor_id'],
            'membership_id' => (int) $office['membership_id'],
            'account' => $office['account'],
            'actor' => $office['actor'],
            'context' => $office['context'],
            'company_id' => (int) $company->id,
            'registration_id' => (int) $registration->id,
            'erp_membership_id' => (int) $membership->id,
            'target_user_id' => (int) $target->id,
        ];
    }

    private function seedRecoveryAuthority(array $fixture, string $operationKey): OfficeErpAccessDelivery
    {
        $ctx = $fixture['context'];
        $request = Request::create('/api/v1/erp-commercial/recovery', 'POST', server: ['REMOTE_ADDR' => '192.0.2.89']);
        $issued = $this->officeAdmin($fixture, fn (): array => $ctx->run(fn (): array => app(OfficeErpMemberCredentials::class)->issueRecovery(
            $request,
            $ctx,
            $fixture['company_id'],
            $fixture['erp_membership_id'],
            $operationKey,
        )));

        $user = User::query()->findOrFail($fixture['target_user_id']);
        DB::table((string) config('auth.passwords.'.config('auth.defaults.passwords', 'users').'.table'))
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(2)]);

        return OfficeErpAccessDelivery::query()->findOrFail($issued['data']['delivery_id']);
    }

    /** @param array<string, mixed> $fixture */
    private function expireRecipientBudgetForFixture(array $fixture): void
    {
        $email = (string) User::query()->findOrFail($fixture['target_user_id'])->email;
        $identity = 'recovery:identity:'.hash('sha256', mb_strtolower(trim($email)));
        $key = (string) config('cache.prefix').'access-limit:'.hash('sha256', $identity);
        $connection = Redis::connection(config('cache.stores.redis.connection', 'cache'));
        self::assertTrue((bool) $connection->expire($key, 1), 'The real issue operation must have created the recipient budget key.');
        usleep(1_100_000);
        self::assertSame(-2, $connection->ttl($key), 'The test-controlled recipient budget TTL must have elapsed before reissue.');
    }

    private function deliveryForOperationKey(int $accountId, string $operationKey): OfficeErpAccessDelivery
    {
        $operationId = DB::table('office_erp_commercial_operations')
            ->where('account_id', $accountId)
            ->where('idempotency_key', $operationKey)
            ->value('id');
        self::assertNotNull($operationId);

        return OfficeErpAccessDelivery::query()->where('operation_id', $operationId)->firstOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function credentialWorker(array $payload): Process
    {
        $ready = $this->credentialDirectory.'/worker-'.count($this->credentialWorkers).'-ready';
        $payload['ready'] = $ready;
        $payload['source_hashes'] = self::sourceHashes();
        $process = new Process([PHP_BINARY, __DIR__.'/OfficeErpCredentialConcurrencyWorker.php'], base_path());
        $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $this->credentialWorkers[] = $process;
        $process->start();

        return $process;
    }

    /** @return array<string, mixed> */
    private function awaitCredentialReady(Process $process): array
    {
        $ready = null;
        foreach (glob($this->credentialDirectory.'/worker-*-ready') ?: [] as $file) {
            $contents = file_get_contents($file);
            if (is_string($contents) && str_contains($contents, '"pid":'.$process->getPid())) {
                $ready = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                break;
            }
        }
        $deadline = microtime(true) + 20;
        while ($ready === null) {
            if (! $process->isRunning()) {
                self::fail('Credential worker exited before readiness; output bytes='.strlen($process->getOutput()).'; diagnostic bytes='.strlen($process->getErrorOutput()));
            }
            if (microtime(true) >= $deadline) {
                self::fail('Credential worker readiness barrier timed out.');
            }
            usleep(10000);
            foreach (glob($this->credentialDirectory.'/worker-*-ready') ?: [] as $file) {
                $contents = file_get_contents($file);
                if (is_string($contents) && str_contains($contents, '"pid":'.$process->getPid())) {
                    $ready = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                    break;
                }
            }
        }

        return $ready;
    }

    /** @return array{status:int,code:?string,transaction_level:int} */
    private function credentialResult(Process $process): array
    {
        $process->wait();
        self::assertTrue($process->isSuccessful(), 'Credential worker exit='.$process->getExitCode().'; stdout bytes='.strlen($process->getOutput()).'; stderr bytes='.strlen($process->getErrorOutput()));
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $result['transaction_level']);

        return $result;
    }

    /** @return array<string, string> */
    public static function sourceHashes(): array
    {
        $paths = [
            'app/Erp/Commercial/OfficeErpMemberCredentials.php',
            'app/Erp/Commercial/OfficeErpPasswordRecovery.php',
            'app/Erp/Commercial/OfficeErpMemberAuthority.php',
            'app/Erp/Commercial/OfficeErpOperations.php',
            'app/Erp/Commercial/OfficeErpAdmission.php',
            'app/Erp/Commercial/OfficeErpAccessDeliveries.php',
            'app/Support/AccessRecoveryBudget.php',
            'app/Http/Controllers/Api/V1/Tenant/AccountMemberController.php',
            'app/Http/Middleware/ResolveAccount.php',
            'app/Models/Erp/Commercial/OfficeErpAccessDelivery.php',
            'app/Models/Erp/Commercial/OfficeErpAuditEvent.php',
            'database/migrations/2026_10_28_173939_add_office_erp_company_registrations.php',
            'database/migrations/2026_10_28_174126_create_office_erp_member_access.php',
            'database/migrations/2026_10_28_201500_add_authority_invalidation_to_office_erp_access_deliveries.php',
            'database/migrations/2026_10_28_230000_add_recovery_completion_receipts.php',
            'database/migrations/2026_10_28_233000_add_recovery_completion_nonce_hash.php',
            'tests/Integration/OfficeErpCredentialConcurrencyTest.php',
            'tests/Integration/OfficeErpCredentialConcurrencyWorker.php',
        ];
        $hashes = [];
        foreach ($paths as $path) {
            $hash = hash_file('sha256', base_path($path));
            if (! is_string($hash)) {
                throw new RuntimeException('Credential concurrency source fingerprint is unavailable.');
            }
            $hashes[$path] = $hash;
        }

        return $hashes;
    }
}
