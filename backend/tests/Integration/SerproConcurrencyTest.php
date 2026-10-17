<?php

namespace Tests\Integration;

use App\Enums\SerproSyncItemState;
use App\Enums\SerproSyncRunState;
use App\Models\Account;
use App\Models\Client;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SerproConcurrencyTest extends TestCase
{
    /** @var list<Process> */
    private array $workers = [];

    /** @var list<string> */
    private array $signals = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires the isolated PostgreSQL integration configuration.');
        }

        $this->assertSame('testing', app()->environment());
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertSame('14', (string) config('database.redis.cache.database'));
        $this->assertSame('15', (string) config('database.redis.sessions.database'));
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        foreach ($this->workers as $worker) {
            $worker->stop();
        }
        foreach ($this->signals as $signal) {
            @unlink($signal);
        }

        parent::tearDown();
    }

    public function test_recount_waits_for_the_run_lock_before_reading_items(): void
    {
        [$account, $run, $item] = $this->scenario();
        DB::beginTransaction();
        SerproSyncRun::query()->lockForUpdate()->findOrFail($run->id);
        $worker = $this->worker('recount', $account->id, $run->id, $item->id);
        $this->waitFor($worker, 'started');
        $pid = (int) file_get_contents($this->signal($worker, 'started'));
        $query = $this->waitForDatabaseLock($worker, $pid);
        // The blocked SELECT must be the run claim, never a stale aggregate followed by UPDATE.
        $this->assertStringContainsString('for update', strtolower($query));
        $this->assertFileDoesNotExist($this->signal($worker, 'aggregated'));

        $item->forceFill(['state' => SerproSyncItemState::Synchronized])->save();
        DB::commit();
        $this->assertWorkerSucceeded($worker);

        $this->assertSame(1, $run->fresh()->synchronized);
        $this->assertSame(0, $run->fresh()->not_processed);
        $this->assertSame(SerproSyncRunState::Completed, $run->fresh()->state);
    }

    public function test_terminal_run_wins_against_a_waiting_send_claim(): void
    {
        [$account, $run, $item] = $this->scenario();
        DB::beginTransaction();
        SerproSyncRun::query()->lockForUpdate()->findOrFail($run->id);
        $worker = $this->worker('claim', $account->id, $run->id, $item->id);
        $this->waitFor($worker, 'started');
        $this->waitForDatabaseLock($worker, (int) file_get_contents($this->signal($worker, 'started')));
        $run->forceFill(['state' => SerproSyncRunState::Failed, 'finished_at' => now()])->save();
        DB::commit();
        $this->assertWorkerSucceeded($worker);

        $this->assertSame('false', trim($worker->getOutput()));
        $this->assertNull($item->fresh()->attempted_at);
        $this->assertNull($item->fresh()->current_obligation);
    }

    public function test_watchdog_rechecks_progress_committed_by_an_independent_claim(): void
    {
        [$account, $run, $item] = $this->scenario();
        $run->items()->update(['updated_at' => now()->subMinutes(6)]);
        $run->forceFill(['updated_at' => now()->subMinutes(6)])->save();
        $watchdog = $this->worker('watchdog', $account->id, $run->id, $item->id);
        $this->waitFor($watchdog, 'selected');

        $claim = $this->worker('claim', $account->id, $run->id, $item->id);
        $this->assertWorkerSucceeded($claim);
        $this->assertSame('true', trim($claim->getOutput()));
        touch($this->signal($watchdog, 'release'));
        $this->assertWorkerSucceeded($watchdog);

        $this->assertSame(SerproSyncRunState::Running, $run->fresh()->state);
        $this->assertNotNull($item->fresh()->attempted_at);
    }

    /** @return array{Account, SerproSyncRun, SerproSyncRunItem} */
    private function scenario(): array
    {
        $account = Account::factory()->create();
        resolve(CurrentTenant::class)->accountId = $account->id;
        $run = SerproSyncRun::factory()->running()->create(['account_id' => $account->id]);
        $client = Client::factory()->company()->create(['account_id' => $account->id]);
        $item = SerproSyncRunItem::factory()->create([
            'account_id' => $account->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
        ]);

        return [$account, $run, $item];
    }

    private function worker(string $action, int $accountId, int $runId, int $itemId): Process
    {
        $base = sys_get_temp_dir().'/opmoni-serpro-race-'.bin2hex(random_bytes(8));
        foreach (['started', 'aggregated', 'selected', 'release'] as $suffix) {
            $this->signals[] = $base.'.'.$suffix;
        }
        $code = <<<'PHP'
require 'tests/Integration/bootstrap.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'opmoni_test') {
    throw new RuntimeException('Refusing a non-test database.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
resolve(App\Tenant\CurrentTenant::class)->accountId = $input['account'];
$base = $input['base'];
$pid = Illuminate\Support\Facades\DB::selectOne('select pg_backend_pid() as pid')->pid;
file_put_contents($base.'.started', (string) $pid);
if ($input['action'] === 'recount') {
    Illuminate\Support\Facades\DB::listen(function ($query) use ($base): void {
        if (str_contains($query->sql, 'group by')) {
            touch($base.'.aggregated');
        }
    });
    resolve(App\Services\SerproRunFinalizer::class)->recount($input['run'], $input['account']);
} elseif ($input['action'] === 'claim') {
    $item = App\Models\SerproSyncRunItem::findOrFail($input['item']);
    $job = new App\Jobs\SyncSerproClientJob($input['run'], $input['account'], $item->client_id);
    $claimed = (new ReflectionMethod($job, 'claimCall'))->invoke($job, $item, 'caixas-postais/e-cac');
    echo json_encode($claimed, JSON_THROW_ON_ERROR);
} else {
    $selected = false;
    Illuminate\Support\Facades\DB::listen(function ($query) use ($base, &$selected): void {
        if ($selected || !str_starts_with($query->sql, 'select') || !str_contains($query->sql, 'updated_at')) {
            return;
        }
        $selected = true;
        touch($base.'.selected');
        $deadline = microtime(true) + 10;
        while (!file_exists($base.'.release')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Watchdog barrier timed out.');
            }
            usleep(10000);
        }
    });
    Illuminate\Support\Facades\Artisan::call('serpro:fail-abandoned');
}
PHP;
        $worker = new Process([PHP_BINARY, '-r', $code], base_path(), ['FISCAL_ENVIRONMENT' => 'producao']);
        $worker->setInput(json_encode(['action' => $action, 'account' => $accountId, 'run' => $runId, 'item' => $itemId, 'base' => $base], JSON_THROW_ON_ERROR));
        $worker->setTimeout(20);
        $this->workers[] = $worker;
        $worker->start();

        return $worker;
    }

    private function signal(Process $worker, string $suffix): string
    {
        $index = array_search($worker, $this->workers, true);

        return preg_replace('/\.started$/', '.'.$suffix, $this->signals[$index * 4]);
    }

    private function waitFor(Process $worker, string $suffix): void
    {
        $deadline = microtime(true) + 10;
        while (! file_exists($this->signal($worker, $suffix))) {
            $this->assertTrue($worker->isRunning(), $worker->getErrorOutput());
            $this->assertLessThan($deadline, microtime(true), 'Worker signal timed out: '.$suffix);
            usleep(10000);
        }
    }

    private function waitForDatabaseLock(Process $worker, int $pid): string
    {
        $deadline = microtime(true) + 10;
        do {
            // Activity columns can be cached within our open transaction; observe a fresh snapshot each poll.
            DB::select('select pg_stat_clear_snapshot()');
            $activity = DB::selectOne('select wait_event_type, query from pg_stat_activity where pid = ?', [$pid]);
            if ($activity?->wait_event_type === 'Lock') {
                return $activity->query;
            }
            $this->assertTrue($worker->isRunning(), $worker->getErrorOutput());
            $this->assertLessThan($deadline, microtime(true), 'Worker never waited for the run lock.');
            usleep(10000);
        } while (true);
    }

    private function assertWorkerSucceeded(Process $worker): void
    {
        $worker->wait();
        $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput().$worker->getOutput());
    }
}
