<?php

namespace Tests\Integration;

use App\Jobs\SendWhatsAppDocumentJob;
use App\Models\Account;
use App\Models\Client;
use App\Models\ClientDispatchContact;
use App\Models\SerproMonitoring;
use App\Models\User;
use App\Models\WhatsAppChannel;
use App\Models\WhatsAppDispatch;
use App\Models\WhatsAppWebhookEvent;
use App\Services\WhatsApp\WhatsAppDispatchService;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WhatsAppConcurrencyTest extends TestCase
{
    private const API_KEY = 'whatsapp-concurrency-test-only';

    /** @var list<Process> */
    private array $workers = [];

    /** @var array<int, string> */
    private array $barriers = [];

    private ?string $queueName = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires phpunit.pgsql.xml and isolated PostgreSQL/Redis.');
        }
        self::guardIsolation();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->queueName = 'whatsapp-concurrency-'.bin2hex(random_bytes(12));
        self::configureQueue($this->queueName);
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($this->queueName !== null) {
            self::guardIsolation();
            Queue::connection('redis')->clear($this->queueName);
        }
        foreach ($this->barriers as $base) {
            foreach (['started', 'creating', 'inserted', 'ready', 'release', 'injected'] as $suffix) {
                @unlink($base.'.'.$suffix);
            }
        }
        resolve(CurrentTenant::class)->accountId = null;

        parent::tearDown();
    }

    public static function guardIsolation(): void
    {
        if (app()->environment() !== 'testing'
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'opmoni_test'
            || ! empty(config('database.connections.pgsql.url'))
            || config('database.redis.options.prefix') !== 'opmoni-access-test-'
            || config('cache.prefix') !== 'opmoni-access-test-cache-'
            || config('cache.default') !== 'redis'
            || config('session.connection') !== 'sessions') {
            throw new RuntimeException('Refusing non-isolated WhatsApp integration configuration.');
        }
        if (DB::selectOne('select current_database() as name')->name !== 'opmoni_test') {
            throw new RuntimeException('Refusing a non-test PostgreSQL connection.');
        }
        foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $name => $database) {
            if ((int) config("database.redis.{$name}.database") !== $database
                || ! empty(config("database.redis.{$name}.url"))) {
                throw new RuntimeException('Refusing a non-test Redis connection: '.$name);
            }
            $client = Redis::connection($name)->client();
            if (! $client instanceof \Redis || $client->getDBNum() !== $database
                || $client->getOption(\Redis::OPT_PREFIX) !== 'opmoni-access-test-') {
                throw new RuntimeException('Redis runtime database/prefix differs from the test configuration.');
            }
        }
    }

    public static function configureQueue(string $queue): void
    {
        if (! preg_match('/^whatsapp-concurrency-[a-f0-9]{24}$/', $queue)) {
            throw new RuntimeException('Refusing a non-test queue.');
        }
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.connection', 'default');
        config()->set('queue.connections.redis.queue', $queue);
        config()->set('queue.connections.redis.after_commit', false);
        config()->set('queue.connections.redis.block_for', null);
    }

    public function test_independent_process_cannot_see_dispatch_or_redis_job_before_commit(): void
    {
        $input = $this->scenario();
        $worker = $this->worker($input + ['action' => 'dispatch-held', 'commit' => true]);
        $this->waitFor($worker, 'ready');

        $this->assertSame(0, WhatsAppDispatch::count());
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));
        $this->assertNull(Queue::connection('redis')->pop($this->queueName));
        touch($this->signal($worker, 'release'));
        $result = $this->workerResult($worker);

        $this->assertTrue($result['enqueued']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertSame(1, WhatsAppDispatch::count());
        $this->assertSame(1, Queue::connection('redis')->size($this->queueName));
        $this->assertQueuedDispatch($result['dispatch_id']);
    }

    public function test_independent_transaction_rollback_never_publishes_redis_job(): void
    {
        $input = $this->scenario();
        $worker = $this->worker($input + ['action' => 'dispatch-held', 'commit' => false]);
        $this->waitFor($worker, 'ready');
        $this->assertSame(0, WhatsAppDispatch::count());
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));
        touch($this->signal($worker, 'release'));
        $result = $this->workerResult($worker);

        $this->assertSame(0, $result['transaction_level']);
        $this->assertSame(0, WhatsAppDispatch::count());
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));
        $this->assertNull(Queue::connection('redis')->pop($this->queueName));
    }

    public function test_concurrent_retries_preserve_failure_and_enqueue_exactly_one_new_attempt(): void
    {
        $input = $this->scenario();
        $original = resolve(WhatsAppDispatchService::class)->dispatch(
            $input['account'], $input['client'], '2026-01', 'guia', User::findOrFail($input['actor']),
        )['dispatch'];
        $this->assertQueuedDispatch($original->getKey());
        $channel = WhatsAppChannel::forAccount($input['account']);
        $channel->forceFill(['status' => WhatsAppChannel::STATUS_DISCONNECTED])->save();
        (new SendWhatsAppDocumentJob($input['account'], $original->getKey()))->handle();
        $channel->forceFill(['status' => WhatsAppChannel::STATUS_CONNECTED])->save();
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $original->fresh()->status);

        $winner = $this->worker($input + ['action' => 'retry', 'hold' => true]);
        $this->waitFor($winner, 'inserted');
        $contender = $this->worker($input + ['action' => 'retry', 'hold' => false]);
        $this->waitFor($contender, 'creating');
        $query = $this->waitForDatabaseLock($contender);
        $this->assertStringContainsString('insert into "whatsapp_dispatches"', $query);
        $this->assertSame(1, WhatsAppDispatch::count());
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));

        touch($this->signal($winner, 'release'));
        $first = $this->workerResult($winner);
        $second = $this->workerResult($contender);
        $this->assertTrue($first['enqueued']);
        $this->assertFalse($second['enqueued']);
        $this->assertSame($first['dispatch_id'], $second['dispatch_id']);
        $this->assertSame(1, $second['database_healthy']);
        $this->assertSame(2, WhatsAppDispatch::count());
        $this->assertSame(1, WhatsAppDispatch::where('status', WhatsAppDispatch::STATUS_QUEUED)->count());
        $this->assertSame(WhatsAppDispatch::STATUS_FAILED, $original->fresh()->status);
        $this->assertSame('O Canal WhatsApp do escritório não está conectado.', $original->fresh()->failure_reason);
        $this->assertNotSame($original->idempotency_key, WhatsAppDispatch::findOrFail($first['dispatch_id'])->idempotency_key);
        $this->assertSame(1, Queue::connection('redis')->size($this->queueName));

        $repeat = $this->workerResult($this->worker($input + ['action' => 'retry', 'hold' => false]));
        $this->assertFalse($repeat['enqueued']);
        $this->assertSame($first['dispatch_id'], $repeat['dispatch_id']);
        $this->assertSame(2, WhatsAppDispatch::count());
        $this->assertSame(1, Queue::connection('redis')->size($this->queueName));
        $this->assertQueuedDispatch($first['dispatch_id']);
    }

    public function test_concurrent_webhook_dedupe_survives_postgresql_unique_with_savepoint(): void
    {
        $input = $this->scenario();
        $dispatch = $this->sentDispatch($input);
        $event = 'same-event-'.bin2hex(random_bytes(8));
        $winner = $this->worker($input + ['action' => 'webhook', 'event' => $event, 'hold' => true, 'status' => 'delivered']);
        $this->waitFor($winner, 'inserted');
        $contender = $this->worker($input + ['action' => 'webhook', 'event' => $event, 'hold' => false, 'status' => 'read']);
        $this->waitFor($contender, 'creating');
        $this->assertStringContainsString('insert into "whatsapp_webhook_events"', $this->waitForDatabaseLock($contender));
        $this->assertSame(0, WhatsAppWebhookEvent::count());
        touch($this->signal($winner, 'release'));

        $first = $this->workerResult($winner);
        $second = $this->workerResult($contender);
        $this->assertSame(200, $first['status']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(1, $second['database_healthy']);
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->fresh()->status);
        $this->assertSame(1, WhatsAppWebhookEvent::count());
        $this->assertSame($dispatch->getKey(), WhatsAppWebhookEvent::sole()->whatsapp_dispatch_id);
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));
    }

    public function test_webhook_failure_rolls_back_dispatch_and_dedupe_then_redelivery_succeeds(): void
    {
        $input = $this->scenario();
        $dispatch = $this->sentDispatch($input);
        $event = 'rollback-event-'.bin2hex(random_bytes(8));
        $worker = $this->worker($input + [
            'action' => 'webhook', 'event' => $event, 'hold' => false, 'status' => 'delivered', 'fail' => true,
        ]);
        $failed = $this->workerResult($worker);
        $this->assertSame(503, $failed['status']);
        $this->assertFileExists($this->signal($worker, 'injected'));
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, file_get_contents($this->signal($worker, 'injected')));
        $this->assertSame(1, $failed['database_healthy']);
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(0, WhatsAppWebhookEvent::count());

        $retry = $this->workerResult($this->worker($input + [
            'action' => 'webhook', 'event' => $event, 'hold' => false, 'status' => 'delivered',
        ]));
        $this->assertSame(200, $retry['status']);
        $this->assertSame(WhatsAppDispatch::STATUS_DELIVERED, $dispatch->fresh()->status);
        $this->assertSame(1, WhatsAppWebhookEvent::count());
        $this->assertSame($dispatch->getKey(), WhatsAppWebhookEvent::sole()->whatsapp_dispatch_id);
    }

    public function test_distinct_concurrent_webhook_read_cannot_be_regressed_by_stale_delivered(): void
    {
        $this->assertDistinctWebhookRace('read', 'delivered');
    }

    public function test_distinct_concurrent_webhook_delivery_cannot_be_overwritten_by_stale_failed(): void
    {
        $this->assertDistinctWebhookRace('delivered', 'failed');
    }

    public function test_distinct_concurrent_webhook_failed_remains_terminal_after_stale_delivery(): void
    {
        $this->assertDistinctWebhookRace('failed', 'delivered');
    }

    public function test_distinct_concurrent_receipt_batches_lock_in_dispatch_order_despite_reversed_aliases(): void
    {
        $input = $this->scenario();
        $first = $this->sentDispatch($input);
        $second = WhatsAppDispatch::factory()->create([
            'account_id' => $input['account'], 'client_id' => $input['client'],
            'status' => WhatsAppDispatch::STATUS_SENT, 'message_id' => 'message-second', 'whatsapp_id' => 'wamid.SECOND',
        ]);
        $winner = $this->worker($input + [
            'action' => 'webhook', 'event' => 'batch-read', 'hold' => false, 'status' => 'read',
            'message_ids' => ['wamid.SECOND', 'message-test'], 'hold_dispatch' => 'updating',
        ]);
        $this->waitFor($winner, 'ready');
        $contender = $this->worker($input + [
            'action' => 'webhook', 'event' => 'batch-delivered', 'hold' => false, 'status' => 'delivered',
            'message_ids' => ['wamid.INTEGRATION', 'message-second'],
        ]);
        $this->assertStringContainsString('whatsapp_dispatches', $this->waitForDatabaseLock($contender));
        touch($this->signal($winner, 'release'));

        $this->assertSame(200, $this->workerResult($winner)['status']);
        $this->assertSame(200, $this->workerResult($contender)['status']);
        $this->assertSame(WhatsAppDispatch::STATUS_READ, $first->fresh()->status);
        $this->assertSame(WhatsAppDispatch::STATUS_READ, $second->fresh()->status);
        $this->assertSame(2, WhatsAppWebhookEvent::count());
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));
    }

    private function assertDistinctWebhookRace(string $winnerStatus, string $contenderStatus): void
    {
        $input = $this->scenario();
        $dispatch = $this->sentDispatch($input);
        $winner = $this->worker($input + [
            'action' => 'webhook', 'event' => 'winner-'.$winnerStatus, 'hold' => false,
            'status' => $winnerStatus, 'hold_dispatch' => 'updating',
        ]);
        $this->waitFor($winner, 'ready');
        $contender = $this->worker($input + [
            'action' => 'webhook', 'event' => 'contender-'.$contenderStatus, 'hold' => false,
            'status' => $contenderStatus, 'hold_dispatch' => 'retrieved',
        ]);
        $this->waitFor($contender, 'ready');
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, file_get_contents($this->signal($winner, 'ready')));
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, file_get_contents($this->signal($contender, 'ready')));
        $this->assertSame(WhatsAppDispatch::STATUS_SENT, $dispatch->fresh()->status);
        touch($this->signal($winner, 'release'));
        $this->assertSame(200, $this->workerResult($winner)['status']);
        $this->assertSame($winnerStatus, $dispatch->fresh()->status);
        touch($this->signal($contender, 'release'));
        $result = $this->workerResult($contender);

        $this->assertSame(200, $result['status']);
        $this->assertSame(1, $result['database_healthy']);
        $this->assertSame($winnerStatus, $dispatch->fresh()->status);
        $this->assertSame($winnerStatus === 'failed' ? 'late concurrent failure' : null, $dispatch->fresh()->failure_reason);
        $this->assertSame(2, WhatsAppWebhookEvent::where('whatsapp_dispatch_id', $dispatch->getKey())->count());
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));
    }

    /** @return array{account: int, client: int, actor: int} */
    private function scenario(): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->getKey();
        $client = Client::factory()->company()->create(['account_id' => $account->getKey()]);
        ClientDispatchContact::factory()->whatsapp()->create([
            'account_id' => $account->getKey(), 'client_id' => $client->getKey(), 'value' => '5511987654321',
        ]);
        WhatsAppChannel::factory()->connected(self::API_KEY)->create(['account_id' => $account->getKey()]);
        SerproMonitoring::factory()->create([
            'account_id' => $account->getKey(), 'client_id' => $client->getKey(), 'obligation' => 'declaracoes/pgdas',
            'periods' => [['period' => '2026-01', 'slip_number' => '07202215764027873']],
        ]);

        return ['account' => $account->getKey(), 'client' => $client->getKey(), 'actor' => User::factory()->create()->getKey()];
    }

    /** @param array{account: int, client: int, actor: int} $input */
    private function sentDispatch(array $input): WhatsAppDispatch
    {
        return WhatsAppDispatch::factory()->create([
            'account_id' => $input['account'], 'client_id' => $input['client'],
            'status' => WhatsAppDispatch::STATUS_SENT, 'message_id' => 'message-test', 'whatsapp_id' => 'wamid.INTEGRATION',
        ]);
    }

    private function assertQueuedDispatch(int $id): void
    {
        $job = Queue::connection('redis')->pop($this->queueName);
        $this->assertNotNull($job);
        $command = unserialize($job->payload()['data']['command']);
        $this->assertInstanceOf(SendWhatsAppDocumentJob::class, $command);
        $this->assertSame($id, $command->dispatchId);
        $this->assertNotNull(WhatsAppDispatch::find($command->dispatchId));
        $job->delete();
        $this->assertSame(0, Queue::connection('redis')->size($this->queueName));
    }

    /** @param array<string, mixed> $input */
    private function worker(array $input): Process
    {
        $base = sys_get_temp_dir().'/opmoni-whatsapp-race-'.bin2hex(random_bytes(12));
        $code = <<<'PHP'
require 'tests/Integration/bootstrap.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
Tests\Integration\WhatsAppConcurrencyTest::guardIsolation();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Tests\Integration\WhatsAppConcurrencyTest::configureQueue($input['queue']);
resolve(App\Tenant\CurrentTenant::class)->accountId = $input['account'];
$base = $input['base'];
$wait = function () use ($base): void {
    $deadline = microtime(true) + 10;
    while (! file_exists($base.'.release')) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('WhatsApp barrier timed out.');
        }
        usleep(10000);
    }
};
$pid = Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid;
Illuminate\Support\Facades\DB::statement("set lock_timeout = '10s'");
file_put_contents($base.'.started', (string) $pid);
Illuminate\Support\Facades\DB::beginTransaction();
if ($input['action'] === 'webhook') {
    if (isset($input['hold_dispatch'])) {
        $paused = false;
        $pause = function (App\Models\WhatsAppDispatch $dispatch) use ($base, $wait, &$paused): void {
            if ($paused) {
                return;
            }
            $paused = true;
            file_put_contents($base.'.ready', $dispatch->getOriginal('status'));
            $wait();
        };
        if ($input['hold_dispatch'] === 'retrieved') {
            App\Models\WhatsAppDispatch::retrieved($pause);
        } else {
            App\Models\WhatsAppDispatch::updating($pause);
        }
    }
    App\Models\WhatsAppWebhookEvent::creating(function () use ($base): void {
        touch($base.'.creating');
    });
    if ($input['hold']) {
        App\Models\WhatsAppWebhookEvent::created(function () use ($base, $wait): void {
            touch($base.'.inserted');
            $wait();
        });
    }
    if ($input['fail'] ?? false) {
        App\Models\WhatsAppWebhookEvent::updating(function (App\Models\WhatsAppWebhookEvent $event) use ($base): void {
            $status = Illuminate\Support\Facades\DB::table('whatsapp_dispatches')->where('id', $event->whatsapp_dispatch_id)->value('status');
            file_put_contents($base.'.injected', $status);
            throw new RuntimeException('Injected integration failure after dispatch update.');
        });
    }
    $request = Illuminate\Http\Request::create('/api/v1/webhooks/wzap', 'POST', server: [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_APIKEY' => $input['apikey'],
    ], content: json_encode([
        'event_id' => $input['event'], 'type' => $input['status'] === 'failed' ? 'message.status' : 'receipt',
        'payload' => $input['status'] === 'failed'
            ? ['message_id' => 'message-test', 'status' => 'failed', 'error' => 'late concurrent failure']
            : ['message_ids' => $input['message_ids'] ?? ['wamid.INTEGRATION'], 'status' => $input['status']],
    ], JSON_THROW_ON_ERROR));
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $result = ['status' => $response->getStatusCode()];
    $kernel->terminate($request, $response);
} else {
    if ($input['action'] === 'retry') {
        App\Models\WhatsAppDispatch::creating(function () use ($base): void {
            touch($base.'.creating');
        });
        if ($input['hold']) {
            App\Models\WhatsAppDispatch::created(function () use ($base, $wait): void {
                touch($base.'.inserted');
                $wait();
            });
        }
    }
    $dispatch = resolve(App\Services\WhatsApp\WhatsAppDispatchService::class)->dispatch(
        $input['account'], $input['client'], '2026-01', 'guia', App\Models\User::findOrFail($input['actor']),
    );
    $result = ['dispatch_id' => $dispatch['dispatch']->getKey(), 'enqueued' => $dispatch['enqueued']];
    if ($input['action'] === 'dispatch-held') {
        touch($base.'.ready');
        $wait();
    }
}
$result['database_healthy'] = (int) Illuminate\Support\Facades\DB::selectOne('select 1 as healthy')->healthy;
if (($input['commit'] ?? true) === true) {
    Illuminate\Support\Facades\DB::commit();
} else {
    Illuminate\Support\Facades\DB::rollBack();
}
$result['transaction_level'] = Illuminate\Support\Facades\DB::transactionLevel();
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
        $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['FISCAL_ENVIRONMENT' => 'producao']);
        $worker->setInput(json_encode($input + ['base' => $base, 'queue' => $this->queueName, 'apikey' => self::API_KEY], JSON_THROW_ON_ERROR));
        $worker->setTimeout(20);
        $this->workers[] = $worker;
        $this->barriers[spl_object_id($worker)] = $base;
        $worker->start();

        return $worker;
    }

    private function signal(Process $worker, string $suffix): string
    {
        return $this->barriers[spl_object_id($worker)].'.'.$suffix;
    }

    private function waitFor(Process $worker, string $suffix): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($this->signal($worker, $suffix))) {
            if (! $worker->isRunning() || microtime(true) >= $deadline) {
                $this->fail('Worker did not reach '.$suffix.': '.$worker->getErrorOutput().$worker->getOutput());
            }
            usleep(10000);
        }
    }

    private function waitForDatabaseLock(Process $worker): string
    {
        $this->waitFor($worker, 'started');
        $pid = (int) file_get_contents($this->signal($worker, 'started'));
        $deadline = microtime(true) + 10;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne('select wait_event_type, query from pg_stat_activity where pid = ?', [$pid]);
            if ($activity?->wait_event_type === 'Lock') {
                return $activity->query;
            }
            if (! $worker->isRunning() || microtime(true) >= $deadline) {
                $this->fail('Worker never reached the conflicting INSERT: '.$worker->getErrorOutput().$worker->getOutput());
            }
            usleep(10000);
        } while (true);
    }

    /** @return array<string, mixed> */
    private function workerResult(Process $worker): array
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());

        return json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
