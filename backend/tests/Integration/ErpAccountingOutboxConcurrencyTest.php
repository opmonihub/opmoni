<?php

namespace Tests\Integration;

use App\Erp\Accounting\ErpAccountingHandoffService;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Accounting\ErpAccountingDelivery;
use App\Models\Erp\Accounting\ErpAccountingDeliveryDispatch;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\ApprovesErpAccountingMappings;
use Tests\TestCase;

class ErpAccountingOutboxConcurrencyTest extends TestCase
{
    use ApprovesErpAccountingMappings;

    /** @var array<string, array{ids: list<int>, hash: string}> */
    private array $baseline = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task16-20261021', getenv('ERP_ACCOUNTING_OUTBOX_ISOLATED'));
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertSame('opmoni_test', DB::selectOne('select current_database() as name')->name);
        $this->assertSame('13', (string) config('database.redis.default.database'));
        $this->assertSame('14', (string) config('database.redis.cache.database'));
        $this->assertSame('15', (string) config('database.redis.sessions.database'));
        $this->assertSame('opmoni-access-test-', config('database.redis.options.prefix'));
        $this->assertTrue(Schema::hasTable('erp_accounting_delivery_dispatches'), 'Root must review and apply the exact additive Task16 migration.');
        $this->assertTrue(Schema::hasTable('jobs'), 'Task16 acceptance requires the isolated persistent database queue.');

        foreach (['erp_companies', 'accounts', 'erp_establishments', 'erp_fiscal_documents', 'erp_accounting_deliveries', 'erp_accounting_delivery_dispatches'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->baseline[$table] = ['ids' => $rows->pluck('id')->all(), 'hash' => hash('sha256', $rows->toJson())];
        }
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->baseline as $table => $baseline) {
                $this->assertSame(
                    $baseline['hash'],
                    hash('sha256', DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get()->toJson()),
                    'Task16 changed pre-existing rows in '.$table,
                );
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_parallel_publishers_claim_one_generation_and_stale_due_candidate_rechecks_backoff(): void
    {
        $fixture = $this->fixture();
        $first = $this->dispatch($fixture['delivery']);
        $queue = 'erp-accounting-task16-'.bin2hex(random_bytes(6));
        config(['queue.default' => 'database', 'queue.connections.database.queue' => $queue]);

        $readyA = tempnam(sys_get_temp_dir(), 'accounting-ready-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'accounting-ready-b-');
        unlink($readyA);
        unlink($readyB);
        $workerA = $workerB = null;
        DB::beginTransaction();
        DB::table('erp_accounting_delivery_dispatches')->where('id', $first->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker($first->id, $queue, 'erp-accounting-publish-a', $readyA);
            $workerB = $this->worker($first->id, $queue, 'erp-accounting-publish-b', $readyB);
            $this->waitForRowLock('erp-accounting-publish-a', $readyA);
            $this->waitForRowLock('erp-accounting-publish-b', $readyB);
            DB::commit();
            $resultA = $this->response($workerA);
            $resultB = $this->response($workerB);

            $this->assertNotSame($resultA['pg_pid'], $resultB['pg_pid']);
            $this->assertSame(1, $resultA['queued'] + $resultB['queued']);
            $this->assertSame(1, ErpAccountingDeliveryDispatch::query()->whereKey($first->id)->value('dispatch_attempts'));
            $this->assertSame(1, DB::table('jobs')->where('queue', $queue)->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ([$workerA, $workerB] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            @unlink($readyA);
            @unlink($readyB);
        }

        $second = $this->dispatch($fixture['delivery'], generation: 2);
        $staleQueue = $queue.'-stale';
        $ready = tempnam(sys_get_temp_dir(), 'accounting-stale-ready-');
        unlink($ready);
        $staleWorker = null;
        DB::beginTransaction();
        DB::table('erp_accounting_delivery_dispatches')->where('id', $second->id)->lockForUpdate()->first();
        try {
            $staleWorker = $this->worker($second->id, $staleQueue, 'erp-accounting-stale-candidate', $ready);
            $this->waitForRowLock('erp-accounting-stale-candidate', $ready);
            DB::table('erp_accounting_delivery_dispatches')->where('id', $second->id)->update(['available_at' => now()->addMinutes(5)]);
            DB::commit();
            $staleResult = $this->response($staleWorker);
            $this->assertFalse($staleResult['queued']);
            $this->assertSame(0, DB::table('jobs')->where('queue', $staleQueue)->count());
            $this->assertSame('pending', ErpAccountingDeliveryDispatch::query()->whereKey($second->id)->value('status'));
            $this->assertSame(0, ErpAccountingDeliveryDispatch::query()->whereKey($second->id)->value('dispatch_attempts'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($staleWorker instanceof Process && $staleWorker->isRunning()) {
                $staleWorker->stop(1);
            }
            @unlink($ready);
        }
    }

    public function test_duplicate_queue_consumers_fence_success_and_failure_backoff(): void
    {
        $storageRoot = sys_get_temp_dir().'/erp-accounting-outbox-'.bin2hex(random_bytes(6));
        $queue = 'erp-accounting-consumer-'.bin2hex(random_bytes(6));
        mkdir($storageRoot.'/local', 0770, true);
        mkdir($storageRoot.'/fiscal', 0770, true);
        config([
            'queue.default' => 'database',
            'queue.connections.database.queue' => $queue,
            'filesystems.disks.local.root' => $storageRoot,
            'filesystems.disks.fiscal.root' => $storageRoot.'/fiscal',
        ]);
        Storage::forgetDisk('local');
        Storage::forgetDisk('fiscal');

        foreach ([true, false] as $destinationAvailable) {
            $destinationRoot = $storageRoot.'/fiscal';
            if (! $destinationAvailable) {
                $destinationRoot = $storageRoot.'/fiscal-is-a-file';
                file_put_contents($destinationRoot, 'blocks destination storage');
            }
            $fixture = $this->handoffFixture($storageRoot);
            app(ErpAccountingHandoffService::class)->queueAfterCommit($fixture['delivery']);
            $dispatch = ErpAccountingDeliveryDispatch::query()->where('erp_accounting_delivery_id', $fixture['delivery']->id)->firstOrFail();
            $this->duplicateQueuedMessage($queue);

            $readyA = tempnam(sys_get_temp_dir(), 'accounting-consumer-a-');
            $readyB = tempnam(sys_get_temp_dir(), 'accounting-consumer-b-');
            unlink($readyA);
            unlink($readyB);
            $workerA = $workerB = null;
            DB::beginTransaction();
            DB::table('erp_companies')->where('id', $fixture['company']->id)->lockForUpdate()->first();
            try {
                $workerA = $this->worker(null, $queue, 'erp-accounting-consume-a', $readyA, 'work', $storageRoot, $destinationRoot);
                $workerB = $this->worker(null, $queue, 'erp-accounting-consume-b', $readyB, 'work', $storageRoot, $destinationRoot);
                $this->waitForRowLock('erp-accounting-consume-a', $readyA);
                $this->waitForRowLock('erp-accounting-consume-b', $readyB);
                DB::commit();
                $this->response($workerA);
                $this->response($workerB);
            } finally {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                foreach ([$workerA, $workerB] as $worker) {
                    if ($worker instanceof Process && $worker->isRunning()) {
                        $worker->stop(1);
                    }
                }
                @unlink($readyA);
                @unlink($readyB);
            }

            $fixture['delivery']->refresh();
            $dispatch->refresh();
            if ($destinationAvailable) {
                $this->assertSame('delivered', $fixture['delivery']->status);
                $this->assertSame(1, $fixture['delivery']->attempts);
                $this->assertSame('completed', $dispatch->status);
                $this->assertSame(0, DB::table('jobs')->where('queue', $queue)->count());
            } else {
                $this->assertSame('failed', $fixture['delivery']->status);
                $this->assertSame('publication_failed', $fixture['delivery']->reason_code);
                $this->assertSame(1, $fixture['delivery']->attempts);
                $this->assertSame('pending', $dispatch->status);
                $this->assertSame(2, $dispatch->attempt_sequence);
                $this->assertTrue($dispatch->available_at->isFuture());
                $this->assertNull($dispatch->lease_token);
                $this->assertSame(0, DB::table('jobs')->where('queue', $queue)->count());
            }
        }
    }

    /** @return array{company: ErpCompany, delivery: ErpAccountingDelivery} */
    private function fixture(): array
    {
        $company = ErpCompany::factory()->create(['cnpj' => (string) random_int(10000000000000, 99999999999999)]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $office = Account::factory()->create();
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => random_int(100000, 999999),
            'access_key' => null,
            'status' => 'draft',
            'idempotency_key' => 'task16-outbox-'.bin2hex(random_bytes(12)),
        ]);
        $delivery = ErpAccountingDelivery::query()->create([
            'erp_company_id' => $company->id,
            'erp_fiscal_document_id' => $document->id,
            'account_id' => $office->id,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        return compact('company', 'delivery');
    }

    private function dispatch(ErpAccountingDelivery $delivery, int $generation = 1): ErpAccountingDeliveryDispatch
    {
        return ErpAccountingDeliveryDispatch::query()->create([
            'erp_accounting_delivery_id' => $delivery->id,
            'generation' => $generation,
            'status' => 'pending',
            'dispatch_attempts' => 0,
            'attempt_sequence' => 1,
            'available_at' => now(),
        ]);
    }

    private function worker(?int $dispatchId, string $queue, string $label, string $ready, string $mode = 'publish', ?string $storageRoot = null, ?string $fiscalRoot = null): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpAccountingOutboxConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode(compact('dispatchId', 'queue', 'label', 'ready', 'mode', 'storageRoot', 'fiscalRoot'), JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    /** @return array{company: ErpCompany, delivery: ErpAccountingDelivery} */
    private function handoffFixture(string $storageRoot): array
    {
        $cnpj = (string) random_int(10000000000000, 99999999999999);
        $accessKey = substr_replace('35251030288513000100550010000090011234567890', $cnpj, 6, 14);
        $company = ErpCompany::factory()->create(['cnpj' => $cnpj]);
        $establishment = ErpEstablishment::factory()->create(['erp_company_id' => $company->id]);
        $office = Account::factory()->create();
        $actor = User::factory()->create();
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $company->id,
            'account_id' => $office->id,
            'authorized_by_user_id' => $actor->id,
        ]);
        $client = resolve(CurrentTenant::class)->run($office->id, fn (): Client => Client::factory()->company()->create([
            'account_id' => $office->id,
            'tax_id' => $cnpj,
        ]));
        $this->approveAccountingMapping($delegation, $establishment, $client);
        $path = 'erp/accounting/task16-'.$company->id.'.xml';
        $bytes = '<nfeProc/>';
        Storage::disk('local')->put($path, $bytes);
        $document = ErpFiscalDocument::query()->create([
            'erp_company_id' => $company->id,
            'erp_establishment_id' => $establishment->id,
            'model' => '55',
            'environment' => 'homologation',
            'series' => 1,
            'number' => random_int(100000, 999999),
            'access_key' => $accessKey,
            'status' => 'authorized',
            'idempotency_key' => 'task16-handoff-'.bin2hex(random_bytes(10)),
            'storage_path' => $path,
            'artifact_byte_length' => strlen($bytes),
            'artifact_sha256' => hash('sha256', $bytes),
            'commercial_snapshot' => ['total_amount' => '150.00'],
            'authorized_at' => now(),
        ]);
        $delivery = app(ErpAccountingHandoffService::class)->ensureDeliveryIntent($document, $actor->id);

        return compact('company', 'delivery');
    }

    private function duplicateQueuedMessage(string $queue): void
    {
        $job = DB::table('jobs')->where('queue', $queue)->orderBy('id')->first();
        $this->assertNotNull($job, 'Outbox publication must first persist a database queue message.');
        DB::table('jobs')->insert([
            'queue' => $job->queue,
            'payload' => $job->payload,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => $job->available_at,
            'created_at' => $job->created_at,
        ]);
    }

    private function waitForRowLock(string $label, string $ready): void
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $identity = file_exists($ready) ? json_decode(file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR) : null;
            $waiting = $identity === null ? 0 : DB::selectOne(
                "select count(*) as count from pg_stat_activity where pid = ? and datname = 'opmoni_test' and application_name = ? and wait_event_type = 'Lock'",
                [$identity['pg_pid'], $label],
            )->count;
            if ($identity !== null && (int) $waiting === 1) {
                $this->assertSame('opmoni_test', $identity['database']);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('Outbox worker did not wait on the locked dispatch row: '.$label);
    }

    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('opmoni_test', $result['database']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertGreaterThan(0, $result['pg_pid']);

        return $result;
    }
}
