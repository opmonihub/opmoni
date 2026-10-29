<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpAccessDeliveries;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpOperations;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;

class OfficeErpAccessDeliveryConcurrencyTest extends OfficeErpCommercialProcessTestCase
{
    private string $directory;

    /** @var list<Process> */
    private array $processes = [];

    /** @var array<string, array{ids: list<int|string>, hash: string}> */
    private array $preserved = [];

    /** @var array<int, string> */
    private array $workerBases = [];

    protected function setUp(): void
    {
        parent::setUp();
        OfficeErpCommercialProcessTestCase::assertIsolatedStores();
        self::assertSame(0, DB::transactionLevel(), 'Delivery race fixtures must be committed before workers start.');
        self::assertTrue(DB::getSchemaBuilder()->hasTable('office_erp_access_deliveries'));
        self::assertTrue(DB::getSchemaBuilder()->hasColumn('office_erp_access_deliveries', 'authority_invalidated_at'));
        self::assertSame(13, (int) Redis::connection('default')->client()->getDBNum());
        self::assertSame(14, (int) Redis::connection('cache')->client()->getDBNum());
        self::assertSame(15, (int) Redis::connection('sessions')->client()->getDBNum());
        foreach (['office_erp_access_deliveries', 'office_erp_company_registrations', 'erp_company_member_invitations'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->preserved[$table] = [
                'ids' => $rows->pluck('id')->map(static fn ($id): int|string => is_int($id) ? $id : (string) $id)->all(),
                'hash' => hash('sha256', $rows->toJson()),
            ];
        }
        $this->directory = sys_get_temp_dir().'/opmoni-access-delivery-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach ($this->preserved as $table => $baseline) {
            $rows = $baseline['ids'] === [] ? collect() : DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get();
            self::assertSame($baseline['hash'], hash('sha256', $rows->toJson()), 'A pre-existing row changed in '.$table.'.');
        }
        if (isset($this->directory)) {
            foreach (glob($this->directory.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_two_workers_claim_one_delivery_and_send_once(): void
    {
        Bus::fake();
        $fixture = $this->recoveryFixture();
        $first = $this->deliveryWorker('deliver', ['delivery_id' => $fixture['delivery_id']]);
        $this->await($first, 'sending');
        $second = $this->deliveryWorker('deliver', ['delivery_id' => $fixture['delivery_id']]);
        $this->finish($second);
        $this->releaseDelivery($first, 'release-send');
        $this->finish($first);

        self::assertSame(1, $this->sendCount($first));
        self::assertSame(0, $this->sendCount($second));
        self::assertSame('transport_accepted', OfficeErpAccessDelivery::query()->findOrFail($fixture['delivery_id'])->state);
    }

    public function test_membership_revocation_that_locks_first_prevents_delivery_send(): void
    {
        Bus::fake();
        $fixture = $this->recoveryFixture();
        $revoke = $this->deliveryWorker('revoke_membership', $fixture);
        $this->await($revoke, 'authority-held');
        $deliver = $this->deliveryWorker('deliver', ['delivery_id' => $fixture['delivery_id']]);
        $this->assertWorkerWaitingOn($deliver, $revoke);
        $this->releaseDelivery($revoke, 'release-revocation');
        $this->finish($revoke);
        $this->finish($deliver);

        $delivery = OfficeErpAccessDelivery::query()->findOrFail($fixture['delivery_id']);
        self::assertSame(0, $this->sendCount($deliver));
        self::assertSame('failed', $delivery->state);
        self::assertNull($delivery->payload);
        self::assertNotNull($delivery->authority_invalidated_at);
    }

    public function test_issuer_role_revocation_that_locks_first_prevents_delivery_send(): void
    {
        Bus::fake();
        $fixture = $this->recoveryFixture();
        $revoke = $this->deliveryWorker('revoke_issuer', $fixture);
        $this->await($revoke, 'authority-held');
        $deliver = $this->deliveryWorker('deliver', ['delivery_id' => $fixture['delivery_id']]);
        $this->assertWorkerWaitingOn($deliver, $revoke);
        $this->releaseDelivery($revoke, 'release-revocation');
        $this->finish($revoke);
        $this->finish($deliver);

        $delivery = OfficeErpAccessDelivery::query()->findOrFail($fixture['delivery_id']);
        self::assertSame(0, $this->sendCount($deliver));
        self::assertSame('failed', $delivery->state);
        self::assertNull($delivery->payload);
        self::assertNotNull($delivery->authority_invalidated_at);
    }

    public function test_delivery_holding_real_authority_locks_finishes_once_before_later_membership_revocation(): void
    {
        Bus::fake();
        $fixture = $this->recoveryFixture();
        $deliver = $this->deliveryWorker('deliver', ['delivery_id' => $fixture['delivery_id']]);
        $this->await($deliver, 'sending');
        $revoke = $this->deliveryWorker('revoke_membership', $fixture);
        $this->assertWorkerWaitingOn($revoke, $deliver);
        $this->releaseDelivery($deliver, 'release-send');
        $this->finish($deliver);
        $this->await($revoke, 'authority-held');
        $this->releaseDelivery($revoke, 'release-revocation');
        $this->finish($revoke);

        $delivery = OfficeErpAccessDelivery::query()->findOrFail($fixture['delivery_id']);
        self::assertSame(1, $this->sendCount($deliver));
        self::assertSame('transport_accepted', $delivery->state);
        self::assertNull($delivery->payload);
        self::assertNotNull($delivery->authority_invalidated_at);

        $duplicate = $this->deliveryWorker('deliver', ['delivery_id' => $fixture['delivery_id']]);
        $this->finish($duplicate);
        self::assertSame(1, $this->sendCount($deliver));
    }

    public function test_uncertain_transport_is_not_retried_by_a_later_worker(): void
    {
        Bus::fake();
        $fixture = $this->recoveryFixture();
        $first = $this->deliveryWorker('deliver', ['delivery_id' => $fixture['delivery_id'], 'uncertain' => true]);
        $this->finish($first);
        $second = $this->deliveryWorker('deliver', ['delivery_id' => $fixture['delivery_id']]);
        $this->finish($second);

        $delivery = OfficeErpAccessDelivery::query()->findOrFail($fixture['delivery_id']);
        self::assertSame(1, $this->sendCount($first));
        self::assertSame('unknown', $delivery->state);
        self::assertNull($delivery->payload);
    }

    /** @return array<string, int> */
    private function recoveryFixture(): array
    {
        $office = $this->commercialFixture(8);
        /** @var OfficeErpExecutionContext $ctx */
        $ctx = $office['context'];
        /** @var OfficeErpContract $contract */
        $contract = $office['contracts'][0];
        $recipient = User::factory()->create(['email' => 'delivery-recipient-'.bin2hex(random_bytes(6)).'@example.test', 'access_auth_revision' => 0]);
        $client = Client::withoutGlobalScopes()->where('account_id', $ctx->accountId)->findOrFail($contract->client_id);
        $company = ErpCompany::factory()->create([
            'name' => 'Delivery race company',
            'cnpj' => $client->tax_id,
            'status' => 'active',
        ]);
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        $registration = $ctx->run(fn (): OfficeErpCompanyRegistration => OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $contract->client_id,
            'erp_company_id' => $company->id,
        ]));
        $plan = ErpPlan::factory()->create(['slug' => 'delivery-race-'.bin2hex(random_bytes(6))]);
        $plan->modules()->create(['module_key' => 'purchases', 'quota' => null]);
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => $plan->id,
            'office_erp_contract_id' => $contract->id,
        ]);
        $membership = ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $recipient->id,
            'role' => 'viewer',
        ]);
        $key = 'access-delivery-race-'.bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(32));

        $result = DB::transaction(fn (): array => $ctx->run(fn (): array => app(OfficeErpOperations::class)->execute(
            $ctx,
            $key,
            'credential.recovery.email',
            ['membership_id' => (int) $membership->id, 'recipient_user_id' => (int) $recipient->id],
            static function (): void {},
            fn (): array => app(OfficeErpAccessDeliveries::class)->prepare(
                $ctx,
                $key,
                'password_recovery',
                [
                    'registration_id' => (int) $registration->id,
                    'company_id' => (int) $company->id,
                    'membership_id' => (int) $membership->id,
                    'recipient_user_id' => (int) $recipient->id,
                    'recipient_email' => $recipient->email,
                    'access_auth_revision' => (int) $recipient->access_auth_revision,
                ],
                ['token' => $token],
                CarbonImmutable::now()->addMinutes(50),
            ),
        )));

        return [
            'account_id' => (int) $ctx->accountId,
            'actor_id' => (int) $office['actor_id'],
            'admin_membership_id' => (int) $office['membership_id'],
            'company_id' => (int) $company->id,
            'membership_id' => (int) $membership->id,
            'delivery_id' => (int) $result['id'],
        ];
    }

    private function deliveryWorker(string $action, array $payload): Process
    {
        $base = $this->directory.'/worker-'.bin2hex(random_bytes(6));
        $process = new Process([PHP_BINARY, __DIR__.'/OfficeErpAccessDeliveryConcurrencyWorker.php'], base_path());
        $process->setInput(json_encode([...$payload, 'action' => $action, 'base' => $base], JSON_THROW_ON_ERROR));
        $process->setTimeout(35);
        $this->processes[] = $process;
        $this->workerBases[spl_object_id($process)] = $base;
        $process->start();

        return $process;
    }

    private function await(Process $process, string $signal): void
    {
        $base = $this->base($process);
        $deadline = microtime(true) + 20;
        while (! file_exists($base.'.'.$signal)) {
            if (! $process->isRunning() || microtime(true) >= $deadline) {
                self::fail('Access delivery worker stopped before its private barrier.');
            }
            usleep(10000);
        }
    }

    private function releaseDelivery(Process $process, string $signal): void
    {
        self::assertTrue(touch($this->base($process).'.'.$signal));
    }

    private function finish(Process $process): void
    {
        $process->wait();
        self::assertTrue($process->isSuccessful(), 'Access delivery worker failed; stderr fingerprint '.hash('sha256', $process->getErrorOutput()));
        $result = json_decode((string) file_get_contents($this->base($process).'.result'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(0, $result['transaction_level']);
    }

    private function assertWorkerWaitingOn(Process $contender, Process $holder): void
    {
        $holderSignal = is_file($this->base($holder).'.authority-held') ? 'authority-held' : 'sending';
        $holderPid = (int) file_get_contents($this->base($holder).'.'.$holderSignal);
        $deadline = microtime(true) + 12;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select pid, wait_event, wait_event_type from pg_stat_activity where application_name like 'access-delivery-%' and pid <> ? and wait_event_type = 'Lock' and ? = any(pg_blocking_pids(pid)) order by pid desc limit 1", [DB::selectOne('select pg_backend_pid() as pid')->pid, $holderPid]);
            if ($waiting !== null && $waiting->wait_event === 'advisory') {
                return;
            }
            if (! $contender->isRunning() || microtime(true) >= $deadline) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        self::fail('Expected worker to wait for the other transaction on the real email identity advisory lock.');
    }

    private function sendCount(Process $process): int
    {
        $path = $this->base($process).'.send-count';

        return is_file($path) ? (int) file_get_contents($path) : 0;
    }

    private function base(Process $process): string
    {
        return $this->workerBases[spl_object_id($process)];
    }
}
