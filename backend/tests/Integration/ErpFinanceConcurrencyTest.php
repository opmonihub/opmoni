<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Erp\Finance\ErpFinanceReadService;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\Erp\Finance\ErpCashSession;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpFinanceConcurrencyTest extends TestCase
{
    private array $baseline = [];

    private string $clientIp;

    private ErpCompany $company;

    private ErpEstablishment $establishment;

    /** @var array{user: User, company: ErpCompany, unit: ErpUnit} */
    private array $firstActor;

    /** @var array{user: User, company: ErpCompany, unit: ErpUnit} */
    private array $secondActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task8-20261020', getenv('ERP_FINANCE_ISOLATED'));
        $this->assertSame('testing', app()->environment());
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('opmoni_test', config('database.connections.pgsql.database'));
        $this->assertSame('opmoni_test', DB::selectOne('select current_database() as name')->name);
        $this->assertSame('13', (string) config('database.redis.default.database'));
        $this->assertSame('14', (string) config('database.redis.cache.database'));
        $this->assertSame('15', (string) config('database.redis.sessions.database'));
        $this->assertSame('opmoni-access-test-', config('database.redis.options.prefix'));
        $this->assertSame('13', (string) Redis::connection('default')->client()->getDBNum());
        $this->assertSame('14', (string) Redis::connection('cache')->client()->getDBNum());
        $this->assertSame('15', (string) Redis::connection('sessions')->client()->getDBNum());
        $postgresVersion = DB::selectOne('show server_version')->server_version;
        $redisVersion = Redis::connection('cache')->info('server')['redis_version'];
        $this->assertStringStartsWith('18.', $postgresVersion);
        $this->assertStringStartsWith('8.', $redisVersion);
        fwrite(STDERR, "Retained finance stores: PostgreSQL {$postgresVersion}, database opmoni_test; Redis {$redisVersion}, databases 13/14/15\n");

        $this->assertTrue(Schema::hasTable('erp_cash_movements'), 'Root must approve and apply the exact additive finance migration.');

        foreach (['users', 'erp_companies', 'erp_contacts', 'erp_products', 'erp_product_variants', 'erp_units', 'erp_catalog_imports', 'erp_operation_receipts', 'erp_obligations', 'erp_payments', 'erp_payment_allocations', 'erp_financial_accounts', 'erp_cash_registers', 'erp_cash_sessions', 'erp_cash_movements', 'erp_stock_balances', 'erp_stock_movements', 'erp_stock_lots', 'erp_stock_allocations', 'erp_stock_cost_settlements', 'erp_stock_adjustments', 'erp_stock_transfers', 'erp_purchases', 'erp_purchase_lines', 'erp_sales', 'erp_sale_lines', 'erp_sale_returns', 'erp_purchase_returns'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->baseline[$table] = ['ids' => $rows->pluck('id')->all(), 'hash' => hash('sha256', $rows->toJson())];
        }

        $this->company = $this->createCompany();
        $this->clientIp = '10.88.'.intdiv($this->company->id % 60000, 250).'.'.($this->company->id % 250 + 1);
        $this->firstActor = $this->owner($this->company);
        $this->secondActor = $this->firstActor;
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        fwrite(STDERR, "Task8 retained Company {$this->company->id}, establishment {$this->establishment->id}, actor {$this->firstActor['user']->id}\n");
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->baseline as $table => $baseline) {
                $this->assertSame($baseline['hash'], hash('sha256', DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get()->toJson()), 'Existing rows changed in '.$table);
            }
            fwrite(STDERR, 'Retained baseline preserved: '.count($this->baseline).' tables, '.array_sum(array_map(fn (array $baseline): int => count($baseline['ids']), $this->baseline)).' rows, SHA256 '.hash('sha256', json_encode($this->baseline, JSON_THROW_ON_ERROR))."\n");
        } finally {
            parent::tearDown();
        }
    }

    /** @return array{user: User, company: ErpCompany, unit: ErpUnit} */
    private function owner(ErpCompany $company): array
    {
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);
        if (! ErpSubscription::query()->where('erp_company_id', $company->id)->exists()) {
            ErpSubscription::factory()->create([
                'erp_company_id' => $company->id,
                'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
                'status' => 'active',
            ]);
        }

        return compact('user', 'company');
    }

    private function createCompany(): ErpCompany
    {
        return ErpCompany::factory()->create([
            'cnpj' => (string) random_int(10000000000000, 99999999999999),
        ]);
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpFinanceConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode($input + ['client_ip' => $this->clientIp], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        $process->start();

        return $process;
    }

    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertSame('opmoni_test', $result['database']);
        $this->assertGreaterThan(0, $result['pg_pid']);

        return $result;
    }

    private function request(array $input): array
    {
        return $this->response($this->worker($input));
    }

    /** @return array{cookies: array<string, string>, headers: array<string, string>} */
    private function login(array $actor): array
    {
        $csrf = $this->request(['path' => '/api/v1/csrf']);
        $login = $this->request([
            'path' => '/api/v1/login',
            'method' => 'POST',
            'cookies' => $csrf['cookies'],
            'headers' => ['X-CSRF-Token' => $csrf['body']['csrf_token']],
            'body' => ['email' => $actor['user']->email, 'password' => 'password'],
        ]);
        $this->assertSame(200, $login['status'], json_encode($login['body']));
        $csrf = $this->request(['path' => '/api/v1/csrf', 'cookies' => $login['cookies']]);

        return [
            'cookies' => $csrf['cookies'] ?: $login['cookies'],
            'headers' => [
                'X-CSRF-Token' => $csrf['body']['csrf_token'],
                'X-Company-Id' => (string) $actor['company']->id,
            ],
        ];
    }

    private function waitForCompanyLock(string $label, string $ready, ?int $blocker = null): array
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $identity = file_exists($ready) ? json_decode(file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR) : null;
            $waiting = $identity === null ? 0 : DB::selectOne("select count(*) as count from pg_stat_activity where pid = ? and datname = ? and application_name = ? and wait_event_type = 'Lock'", [$identity['pg_pid'], 'opmoni_test', $label])->count;
            if ($identity !== null && (int) $waiting === 1) {
                $this->assertSame('opmoni_test', $identity['database']);
                if ($blocker !== null) {
                    $activity = DB::selectOne('select query, ? = any(pg_blocking_pids(pid)) as blocked_by_holder from pg_stat_activity where pid = ?', [$blocker, $identity['pg_pid']]);
                    $this->assertTrue($activity->blocked_by_holder);
                    $this->assertStringContainsString('"erp_companies"', $activity->query);
                    $this->assertStringContainsString('for update', $activity->query);
                }
                fwrite(STDERR, "Barrier confirmed: {$label}, OS PID {$identity['pid']}, PostgreSQL PID {$identity['pg_pid']}, database {$identity['database']}, waiting on Company row lock\n");

                return $identity;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('HTTP worker did not reach the Company lock barrier: '.$label);
    }

    private function race(array $first, array $second): array
    {
        $readyA = tempnam(sys_get_temp_dir(), 'finance-ready-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'finance-ready-b-');
        unlink($readyA);
        unlink($readyB);
        $workerA = $workerB = null;
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker($first + ['label' => 'erp-finance-race-a', 'ready' => $readyA]);
            $workerB = $this->worker($second + ['label' => 'erp-finance-race-b', 'ready' => $readyB]);
            $this->waitForCompanyLock('erp-finance-race-a', $readyA);
            $this->waitForCompanyLock('erp-finance-race-b', $readyB);
            DB::commit();
            $a = $this->response($workerA);
            $b = $this->response($workerB);
            $this->assertNotSame($a['pid'], $b['pid']);
            $this->assertNotSame($a['pg_pid'], $b['pg_pid']);

            return [$a, $b];
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
    }

    private function postInput(array $session, string $path, array $body, string $key): array
    {
        $session['headers']['Idempotency-Key'] = $key;

        return $session + ['method' => 'POST', 'path' => '/api/v1/erp/'.$path, 'body' => $body];
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }

    private function create(array $session, string $path, array $body): int
    {
        $result = $this->request($this->postInput($session, $path, $body, 'fixture-'.bin2hex(random_bytes(8))));
        $this->assertSame(201, $result['status'], json_encode($result['body']));

        return $result['body']['data']['id'];
    }

    private function obligation(array $session, string $amount = '100'): int
    {
        return $this->create($session, 'obligations', ['erp_establishment_id' => $this->establishment->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => $amount]);
    }

    private function payment(array $session, string $amount = '100'): int
    {
        return $this->create($session, 'payments', ['erp_establishment_id' => $this->establishment->id, 'direction' => 'in', 'method' => 'manual', 'amount' => $amount]);
    }

    private function open(array $session, ?int $register = null): int
    {
        $register ??= $this->create($session, 'cash-registers', ['name' => 'Synthetic finance race', 'erp_establishment_id' => $this->establishment->id]);

        return $this->create($session, 'cash-sessions', ['erp_cash_register_id' => $register, 'currency' => 'BRL', 'opening_amount' => '100']);
    }

    private function sessions(): array
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $this->assertNotSame($a['cookies']['opmoni-session'], $b['cookies']['opmoni-session']);

        return [$a, $b];
    }

    private function reportCashSession(array $session): int
    {
        $register = $this->create($session, 'cash-registers', ['name' => 'Report process acceptance', 'erp_establishment_id' => $this->establishment->id]);

        return $this->create($session, 'cash-sessions', ['erp_cash_register_id' => $register, 'currency' => 'BRL', 'opening_amount' => '0']);
    }

    private function cashPayment(array $session, int $cash, string $amount): int
    {
        return $this->create($session, 'payments', ['erp_establishment_id' => $this->establishment->id, 'erp_cash_session_id' => $cash, 'direction' => 'in', 'method' => 'cash', 'currency' => 'BRL', 'amount' => $amount]);
    }

    private function allocate(array $session, int $payment, int $obligation, string $amount): int
    {
        $result = $this->request($this->postInput($session, "payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => $amount], 'report-allocation-'.bin2hex(random_bytes(8))));
        $this->assertSame(200, $result['status'], json_encode($result['body']));

        return $result['body']['data']['id'];
    }

    private function waitForReportPause(Process $worker, string $paused, string $phase): array
    {
        $deadline = microtime(true) + 8;
        do {
            if (file_exists($paused)) {
                $identity = json_decode(file_get_contents($paused), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($phase, $identity['phase']);
                $this->assertSame('opmoni_test', $identity['database']);
                $this->assertGreaterThanOrEqual($phase === 'report-before-payments' ? 1 : 2, $identity['transaction_level']);
                $this->assertContains($this->company->id, $identity['locked_company_ids']);
                if ($phase === 'report-before-payments') {
                    $this->assertSame(2, $identity['completed_obligation_reads']);
                    $this->assertStringContainsString('from "erp_payments"', $identity['query']);
                } else {
                    $this->assertStringStartsWith('insert into "erp_payments"', $identity['query']);
                    $this->assertSame(1, $identity['completed_allocation_compensations']);
                    $this->assertSame(1, $identity['completed_obligation_updates']);
                }
                fwrite(STDERR, "Pause confirmed: {$identity['label']}, OS PID {$identity['pid']}, PostgreSQL PID {$identity['pg_pid']}, phase {$phase}, transaction level {$identity['transaction_level']}, completed obligation reads {$identity['completed_obligation_reads']}\n");

                return $identity;
            }
            if (! $worker->isRunning()) {
                $result = json_decode($worker->getOutput(), true);
                $this->fail('HTTP worker exited before pause; status '.($result['status'] ?? 'unknown').', code '.($result['body']['code'] ?? 'none').'; '.$worker->getErrorOutput());
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('HTTP worker did not reach the bounded test pause: '.$phase);
    }

    private function assertReportMoney(array $result, array $fixture, bool $reversed): void
    {
        $this->assertSame(200, $result['status'], json_encode($result['body']));
        $report = $result['body']['data'];
        $ledgers = $report['ledgers'];
        $open = $reversed ? '100.0000' : '60.0000';
        $net = $reversed ? '0.0000' : '40.0000';
        $this->assertSame('100.0000', $report['totals']['obligations_amount']);
        $this->assertSame($open, $report['totals']['obligations_open_amount']);
        $this->assertSame([$fixture['obligation']], array_column($ledgers['obligations']['rows'], 'id'));
        $this->assertSame($open, $ledgers['obligations']['rows'][0]['open_amount']);
        $this->assertSame($open, $ledgers['obligations']['totals']['open_amount']);
        $this->assertSame('100.0000', $ledgers['obligation_activity']['totals']['amount']);
        $this->assertSame($net, $ledgers['payments']['totals']['net_amount']);
        $this->assertSame($net, $ledgers['allocations']['totals']['amount']);
        $this->assertSame($net, $ledgers['cash']['totals']['net_amount']);
        foreach (['payments', 'allocations', 'cash'] as $name) {
            $ledger = $ledgers[$name];
            $this->assertSame($reversed ? 2 : 1, $ledger['row_count']);
            $this->assertCount($ledger['row_count'], $ledger['rows']);
            $this->assertFalse($ledger['detail_truncated']);
            $this->assertSame($fixture[$name], $ledger['rows'][0]['id']);
            $this->assertNull($ledger['rows'][0]['reversal_of_id']);
            $this->assertSame('40.0000', $ledger['rows'][0]['amount']);
            if ($reversed) {
                $this->assertSame($fixture[$name], $ledger['rows'][1]['reversal_of_id']);
                $this->assertSame($name === 'cash' ? '40.0000' : '-40.0000', $ledger['rows'][1]['amount']);
            }
            $sum = '0.0000';
            foreach ($ledger['rows'] as $row) {
                $amount = $name !== 'allocations' && $row['direction'] === 'out' ? bcsub('0', $row['amount'], 4) : $row['amount'];
                $sum = bcadd($sum, $amount, 4);
            }
            $this->assertSame($net, $sum, 'Details and net totals differ in '.$name);
        }
        $this->assertSame($report['totals']['payments_posted_total'], $ledgers['payments']['totals']['amount']);
        $this->assertSame($fixture['payments'], $ledgers['allocations']['rows'][0]['erp_payment_id']);
        $this->assertSame($fixture['obligation'], $ledgers['allocations']['rows'][0]['erp_obligation_id']);
        $this->assertSame($fixture['payments'], $ledgers['cash']['rows'][0]['erp_payment_id']);
        if ($reversed) {
            $this->assertSame($fixture['payments'], $ledgers['allocations']['rows'][1]['erp_payment_id']);
            $this->assertSame($ledgers['payments']['rows'][1]['id'], $ledgers['cash']['rows'][1]['erp_payment_id']);
            $this->assertSame('out', $ledgers['cash']['rows'][1]['direction']);
        }
    }

    private function reportReversalFence(bool $reportFirst): void
    {
        [$a, $b] = $this->sessions();
        $cashSession = $this->reportCashSession($a);
        $obligation = $this->obligation($a);
        $payment = $this->cashPayment($a, $cashSession, '40');
        $allocation = $this->allocate($a, $payment, $obligation, '40');
        $cash = DB::table('erp_cash_movements')->where('erp_payment_id', $payment)->sole()->id;
        $fixture = ['obligation' => $obligation, 'payments' => $payment, 'allocations' => $allocation, 'cash' => $cash];
        $originals = [];
        foreach (['erp_payments' => $payment, 'erp_payment_allocations' => $allocation, 'erp_cash_movements' => $cash] as $table => $id) {
            $originals[$table] = (array) DB::table($table)->find($id);
        }
        $report = $a + ['path' => '/api/v1/erp/reports/financial'];
        $reverse = $this->postInput($b, "payments/{$payment}/reverse", ['reason' => 'Report process acceptance'], 'report-reversal');
        $phase = $reportFirst ? 'report-before-payments' : 'reversal-before-payment-insert';
        $label = 'erp-report-holder-'.$this->company->id;
        $waitingLabel = 'erp-report-waiter-'.$this->company->id;
        $paused = tempnam(sys_get_temp_dir(), 'finance-report-pause-');
        $release = tempnam(sys_get_temp_dir(), 'finance-report-release-');
        $ready = tempnam(sys_get_temp_dir(), 'finance-report-ready-');
        foreach ([$paused, $release, $ready] as $path) {
            unlink($path);
        }
        $holder = $waiter = null;
        try {
            $holder = $this->worker(($reportFirst ? $report : $reverse) + ['label' => $label, 'pause' => ['phase' => $phase, 'paused' => $paused, 'release' => $release]]);
            $holding = $this->waitForReportPause($holder, $paused, $phase);
            $waiter = $this->worker(($reportFirst ? $reverse : $report) + ['label' => $waitingLabel, 'ready' => $ready]);
            $waiting = $this->waitForCompanyLock($waitingLabel, $ready, $holding['pg_pid']);
            $this->assertNotSame($holding['pid'], $waiting['pid']);
            $this->assertNotSame($holding['pg_pid'], $waiting['pg_pid']);
            $this->assertTrue($holder->isRunning());
            $this->assertTrue($waiter->isRunning());
            file_put_contents($release, 'release');
            $first = $this->response($holder);
            $second = $this->response($waiter);
            $this->assertSame($holding['pg_pid'], $first['pg_pid']);
            $this->assertSame($waiting['pg_pid'], $second['pg_pid']);
            $this->assertSame(200, ($reportFirst ? $second : $first)['status']);
            $this->assertReportMoney($reportFirst ? $first : $second, $fixture, ! $reportFirst);
            $fresh = $this->request($report);
            $this->assertNotSame($first['pg_pid'], $fresh['pg_pid']);
            $this->assertNotSame($second['pg_pid'], $fresh['pg_pid']);
            $this->assertReportMoney($fresh, $fixture, true);
            $this->assertSame('100.0000', ErpObligation::findOrFail($obligation)->open_amount);
            $this->assertSame('0.0000', ErpCashSession::findOrFail($cashSession)->opening_amount);
            foreach ($originals as $table => $original) {
                $this->assertSame($original, (array) DB::table($table)->find($original['id']));
                $this->assertSame(1, DB::table($table)->where('reversal_of_id', $original['id'])->count());
            }
            $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->where('operation', 'erp.payments.reverse')->count());
        } finally {
            if ($holder instanceof Process && $holder->isRunning()) {
                file_put_contents($release, 'release');
            }
            foreach ([$holder, $waiter] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach ([$paused, $paused.'.tmp', $release, $ready] as $path) {
                if (file_exists($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_actual_pg_http_financial_report_holds_company_between_ledgers_before_payment_reversal(): void
    {
        $this->reportReversalFence(true);
    }

    public function test_actual_pg_http_financial_report_waits_for_inflight_payment_reversal_before_reading_ledgers(): void
    {
        $this->reportReversalFence(false);
    }

    public function test_actual_pg_http_financial_and_overview_recheck_whole_payment_scope_after_membership_narrows(): void
    {
        [$a] = $this->sessions();
        $second = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $cash = $this->reportCashSession($a);
        $obligation = $this->obligation($a);
        $hidden = $this->create($a, 'obligations', ['erp_establishment_id' => $second->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '100']);
        $independent = $this->obligation($a, '23');
        $payment = $this->cashPayment($a, $cash, '100');
        $deniedAllocation = $this->allocate($a, $payment, $obligation, '40');
        $this->allocate($a, $payment, $hidden, '60');
        $control = $this->cashPayment($a, $cash, '7');
        $controlAllocation = $this->allocate($a, $control, $obligation, '7');
        $controlCash = DB::table('erp_cash_movements')->where('erp_payment_id', $control)->sole()->id;
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->where('user_id', $this->firstActor['user']->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach([$this->establishment->id, $second->id], ['erp_company_id' => $this->company->id]);
        $filter = '?erp_establishment_id='.$this->establishment->id;
        foreach (['financial', 'overview'] as $category) {
            $before = $this->request($a + ['path' => '/api/v1/erp/reports/'.$category.$filter]);
            $this->assertSame(200, $before['status'], json_encode($before['body']));
            $data = $before['body']['data'];
            $ledgers = $category === 'overview' ? $data['ledgers']['financial'] : $data['ledgers'];
            $this->assertSame([$payment, $control], array_column($ledgers['payments']['rows'], 'id'));
            $this->assertSame([$deniedAllocation, $controlAllocation], array_column($ledgers['allocations']['rows'], 'id'));
            $this->assertSame('107.0000', $ledgers['payments']['totals']['net_amount']);
            $this->assertSame('47.0000', $ledgers['allocations']['totals']['amount']);
            $this->assertSame('107.0000', $ledgers['cash']['totals']['net_amount']);
        }
        $membership->establishments()->detach($second->id);
        $denied = $this->request($a + ['path' => '/api/v1/erp/payments/'.$payment]);
        $this->assertSame(404, $denied['status']);
        foreach (['financial', 'overview'] as $category) {
            foreach (['', $filter] as $query) {
                $after = $this->request($a + ['path' => '/api/v1/erp/reports/'.$category.$query]);
                $this->assertSame(200, $after['status'], json_encode($after['body']));
                $data = $after['body']['data'];
                $ledgers = $category === 'overview' ? $data['ledgers']['financial'] : $data['ledgers'];
                $totals = $category === 'overview' ? $data['sections']['financial'] : $data['totals'];
                $this->assertSame('7.0000', $totals['payments_posted_total']);
                $this->assertSame('123.0000', $totals['obligations_amount']);
                $this->assertSame('76.0000', $totals['obligations_open_amount']);
                $this->assertSame([$obligation, $independent], array_column($ledgers['obligations']['rows'], 'id'));
                $this->assertSame([$control], array_column($ledgers['payments']['rows'], 'id'));
                $this->assertSame([$controlAllocation], array_column($ledgers['allocations']['rows'], 'id'));
                $this->assertSame([$controlCash], array_column($ledgers['cash']['rows'], 'id'));
                $this->assertSame([$control], array_column($ledgers['allocations']['rows'], 'erp_payment_id'));
                $this->assertSame([$control], array_column($ledgers['cash']['rows'], 'erp_payment_id'));
                $this->assertSame('7.0000', $ledgers['payments']['totals']['amount']);
                $this->assertSame('7.0000', $ledgers['payments']['totals']['net_amount']);
                $this->assertSame(['in' => '7.0000'], $ledgers['payments']['totals']['by_direction']);
                $this->assertSame('7.0000', $ledgers['allocations']['totals']['amount']);
                $this->assertSame('7.0000', $ledgers['cash']['totals']['net_amount']);
                $this->assertSame(['7.0000'], array_values($ledgers['cash']['totals']['by_group']));
                foreach (['payments', 'allocations', 'cash'] as $name) {
                    $this->assertSame(1, $ledgers[$name]['row_count']);
                    $this->assertFalse($ledgers[$name]['detail_truncated']);
                }
            }
        }
    }

    public function test_actual_http_overallocation_competitors_leave_one_bounded_effect(): void
    {
        [$a, $b] = $this->sessions();
        $obligation = $this->obligation($a);
        $first = $this->payment($a);
        $second = $this->payment($a);
        $results = $this->race($this->postInput($a, "payments/{$first}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '75'], 'allocate-a'), $this->postInput($b, "payments/{$second}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '75'], 'allocate-b'));
        $this->assertSame([200, 422], $this->statuses($results));
        $this->assertSame('25.0000', ErpObligation::findOrFail($obligation)->open_amount);
        $this->assertSame(1, DB::table('erp_payment_allocations')->where('erp_obligation_id', $obligation)->count());
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->where('operation', 'erp.payments.allocate')->count());
    }

    public function test_actual_http_equal_allocations_replay_original_and_changed_payload_conflicts(): void
    {
        [$a, $b] = $this->sessions();
        $obligation = $this->obligation($a);
        $payment = $this->payment($a);
        $input = $this->postInput($a, "payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '40'], 'equal');
        $other = $this->postInput($b, "payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '40.0000'], 'equal');
        $results = $this->race($input, $other);
        $this->assertSame([200, 200], $this->statuses($results));
        $this->assertSame($results[0]['body'], $results[1]['body']);
        $changed = $this->request($this->postInput($a, "payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '41'], 'equal'));
        $this->assertSame(409, $changed['status']);
        $this->assertSame(1, DB::table('erp_payment_allocations')->where('erp_payment_id', $payment)->count());
        $this->assertSame('60.0000', ErpObligation::findOrFail($obligation)->open_amount);
    }

    public function test_actual_http_partial_reversals_share_original_bound(): void
    {
        [$a, $b] = $this->sessions();
        $obligation = $this->obligation($a);
        $payment = $this->payment($a);
        $accepted = $this->request($this->postInput($a, "payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '100'], 'initial'));
        $this->assertSame(200, $accepted['status']);
        $allocation = $accepted['body']['data']['id'];
        $results = $this->race($this->postInput($a, "payment-allocations/{$allocation}/reverse", ['reason' => 'First', 'amount' => '70'], 'partial-a'), $this->postInput($b, "payment-allocations/{$allocation}/reverse", ['reason' => 'Second', 'amount' => '70'], 'partial-b'));
        $this->assertSame([200, 422], $this->statuses($results));
        $this->assertSame('70.0000', ErpObligation::findOrFail($obligation)->open_amount);
        $this->assertSame(1, DB::table('erp_payment_allocations')->where('reversal_of_id', $allocation)->count());
        $this->assertSame('-70.0000', ErpPaymentAllocation::where('reversal_of_id', $allocation)->sole()->amount);
    }

    public function test_actual_http_payment_reversal_and_allocation_serialize_without_partial_money(): void
    {
        [$a, $b] = $this->sessions();
        $obligation = $this->obligation($a);
        $payment = $this->payment($a);
        $accepted = $this->request($this->postInput($a, "payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '40'], 'initial'));
        $this->assertSame(200, $accepted['status']);
        $results = $this->race($this->postInput($a, "payments/{$payment}/reverse", ['reason' => 'Correct payment'], 'reverse'), $this->postInput($b, "payments/{$payment}/allocate", ['erp_obligation_id' => $obligation, 'amount' => '60'], 'allocate-last'));
        $this->assertContains($this->statuses($results), [[200, 200], [200, 422]]);
        $this->assertSame('100.0000', ErpObligation::findOrFail($obligation)->open_amount);
        $original = ErpPayment::findOrFail($payment);
        $this->assertSame('100.0000', $original->amount);
        $this->assertSame('0.0000', app(ErpFinanceReadService::class)->sumAllocations($original));
        $this->assertSame(1, DB::table('erp_payments')->where('reversal_of_id', $payment)->count());
    }

    public function test_actual_http_duplicate_payment_reversals_converge_one_compensation(): void
    {
        [$a, $b] = $this->sessions();
        $payment = $this->payment($a);
        $results = $this->race($this->postInput($a, "payments/{$payment}/reverse", ['reason' => 'Correction'], 'same-reversal'), $this->postInput($b, "payments/{$payment}/reverse", ['reason' => 'Correction'], 'same-reversal'));
        $this->assertSame([200, 200], $this->statuses($results));
        $this->assertSame($results[0]['body'], $results[1]['body']);
        $this->assertSame(1, DB::table('erp_payments')->where('reversal_of_id', $payment)->count());
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->where('operation', 'erp.payments.reverse')->count());
    }

    public function test_actual_http_cash_payment_and_close_have_one_valid_order(): void
    {
        [$a, $b] = $this->sessions();
        $session = $this->open($a);
        $results = $this->race($this->postInput($a, 'payments', ['direction' => 'in', 'method' => 'cash', 'currency' => 'BRL', 'amount' => '30', 'erp_cash_session_id' => $session, 'erp_establishment_id' => $this->establishment->id], 'cash-payment'), $this->postInput($b, "cash-sessions/{$session}/close", ['declared_closing_amount' => '100'], 'close'));
        $this->assertSame(200, $results[1]['status']);
        $this->assertContains($results[0]['status'], [201, 422]);
        $cash = ErpCashSession::findOrFail($session);
        $this->assertSame('closed', $cash->status);
        $this->assertSame($results[0]['status'] === 201 ? '130.0000' : '100.0000', $cash->expected_closing_amount);
        $count = $results[0]['status'] === 201 ? 1 : 0;
        $this->assertSame($count, DB::table('erp_cash_movements')->where('erp_cash_session_id', $session)->count());
        $this->assertSame($count, DB::table('erp_payments')->where('erp_cash_session_id', $session)->count());
        $this->assertSame($count, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->where('operation', 'erp.payments.store')->count());
    }

    public function test_actual_http_closed_source_reversal_and_target_close_preserve_original_close(): void
    {
        [$a, $b] = $this->sessions();
        $source = $this->open($a);
        $payment = $this->create($a, 'payments', ['direction' => 'in', 'method' => 'cash', 'amount' => '30', 'erp_cash_session_id' => $source, 'erp_establishment_id' => $this->establishment->id]);
        $closed = $this->request($this->postInput($a, "cash-sessions/{$source}/close", ['declared_closing_amount' => '130'], 'original-close'));
        $this->assertSame(200, $closed['status']);
        $before = (array) DB::table('erp_cash_sessions')->find($source);
        $target = $this->open($a);
        $results = $this->race($this->postInput($a, "payments/{$payment}/reverse", ['reason' => 'Closed source correction', 'erp_cash_session_id' => $target], 'reverse-closed'), $this->postInput($b, "cash-sessions/{$target}/close", ['declared_closing_amount' => '100'], 'target-close'));
        $this->assertSame(200, $results[1]['status']);
        $this->assertContains($results[0]['status'], [200, 422]);
        $this->assertSame($before, (array) DB::table('erp_cash_sessions')->find($source));
        $cash = ErpCashSession::findOrFail($target);
        $this->assertSame($results[0]['status'] === 200 ? '70.0000' : '100.0000', $cash->expected_closing_amount);
        $this->assertSame($results[0]['status'] === 200 ? 1 : 0, DB::table('erp_cash_movements')->where('erp_cash_session_id', $target)->where('kind', 'reversal')->count());
    }

    public function test_actual_http_competing_open_sessions_leave_one_open_register(): void
    {
        [$a, $b] = $this->sessions();
        $register = $this->create($a, 'cash-registers', ['name' => 'Open race', 'erp_establishment_id' => $this->establishment->id]);
        $body = ['erp_cash_register_id' => $register, 'opening_amount' => '100', 'currency' => 'BRL'];
        $results = $this->race($this->postInput($a, 'cash-sessions', $body, 'open-a'), $this->postInput($b, 'cash-sessions', $body, 'open-b'));
        $this->assertSame([201, 422], $this->statuses($results));
        $this->assertSame(1, DB::table('erp_cash_sessions')->where('erp_cash_register_id', $register)->where('status', 'open')->count());
    }

    public function test_actual_http_manual_creation_journals_replay_once_in_each_concrete_namespace(): void
    {
        [$a, $b] = $this->sessions();
        foreach ([['financial-accounts', ['name' => 'Safe account', 'erp_establishment_id' => $this->establishment->id]], ['obligations', ['amount' => '10', 'direction' => 'receivable', 'category' => 'manual', 'erp_establishment_id' => $this->establishment->id]], ['cash-registers', ['name' => 'Safe register', 'erp_establishment_id' => $this->establishment->id]]] as [$path, $body]) {
            $results = $this->race($this->postInput($a, $path, $body, 'same-create'), $this->postInput($b, $path, $body, 'same-create'));
            $this->assertSame([201, 201], $this->statuses($results));
            $this->assertSame($results[0]['body'], $results[1]['body']);
            $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->where('operation', 'erp.'.$path.'.store')->count());
            $lookup = $a;
            $lookup['headers']['Idempotency-Key'] = 'same-create';
            $result = $this->request($lookup + ['path' => '/api/v1/erp/operations/lookup?operation=erp.'.$path.'.store']);
            $this->assertSame(200, $result['status']);
            $this->assertSame($results[0]['body']['data'], $result['body']['data']['result']);
        }
    }

    public function test_actual_pg_http_ancestry_cycles_foreign_and_hidden_parents_fail_closed(): void
    {
        [$a] = $this->sessions();
        $base = ['erp_company_id' => $this->company->id, 'erp_establishment_id' => $this->establishment->id, 'amount' => '1', 'direction' => 'in', 'method' => 'manual'];
        $principal = ErpPayment::create($base);
        $first = ErpPayment::create($base + ['reversal_of_id' => $principal->id]);
        $deep = ErpPayment::create($base + ['reversal_of_id' => $first->id]);
        $visible = $this->request($a + ['path' => '/api/v1/erp/payments/'.$deep->id]);
        $this->assertSame(200, $visible['status']);
        $missing = ErpPayment::create($base + ['reversal_of_id' => 999999999]);
        $foreign = ErpPayment::create(array_replace($base, ['erp_company_id' => $this->createCompany()->id, 'erp_establishment_id' => null]));
        $foreignChild = ErpPayment::create($base + ['reversal_of_id' => $foreign->id]);
        $cycleA = ErpPayment::create($base);
        $cycleB = ErpPayment::create($base + ['reversal_of_id' => $cycleA->id]);
        $cycleA->update(['reversal_of_id' => $cycleB->id]);
        $cycleDescendant = ErpPayment::create($base + ['reversal_of_id' => $cycleA->id]);
        foreach ([$missing, $foreignChild, $cycleA, $cycleB, $cycleDescendant] as $payment) {
            $response = $this->request($a + ['path' => '/api/v1/erp/payments/'.$payment->id]);
            $this->assertSame(404, $response['status']);
        }
        $hidden = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $obligation = ErpObligation::create(['erp_company_id' => $this->company->id, 'erp_establishment_id' => $hidden->id, 'direction' => 'receivable', 'category' => 'manual', 'amount' => '1', 'open_amount' => '0']);
        ErpPaymentAllocation::create(['erp_payment_id' => $principal->id, 'erp_obligation_id' => $obligation->id, 'amount' => '1']);
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->where('user_id', $this->firstActor['user']->id)->sole();
        $membership->update(['role' => 'operator', 'action_permissions' => ['erp.read', 'erp.write'], 'access_all_establishments' => false]);
        $membership->establishments()->attach($this->establishment->id, ['erp_company_id' => $this->company->id]);
        foreach ([$principal, $first, $deep] as $payment) {
            $response = $this->request($a + ['path' => '/api/v1/erp/payments/'.$payment->id]);
            $this->assertSame(404, $response['status']);
        }
        $list = $this->request($a + ['path' => '/api/v1/erp/payments']);
        $this->assertSame(200, $list['status']);
        $this->assertSame(0, $list['body']['meta']['total']);
    }

    public function test_actual_pg_http_wide_aggregate_and_cash_bounds_remain_operable(): void
    {
        [$a] = $this->sessions();
        $this->obligation($a, '900000000000000000');
        $this->obligation($a, '900000000000000000');
        $list = $this->request($a + ['path' => '/api/v1/erp/obligations?per_page=1']);
        $this->assertSame(200, $list['status']);
        $this->assertSame('1800000000000000000.0000', $list['body']['meta']['totals'][0]['net_exposure']);
        $register = $this->create($a, 'cash-registers', ['name' => 'Wide cash', 'erp_establishment_id' => $this->establishment->id]);
        $session = $this->create($a, 'cash-sessions', ['erp_cash_register_id' => $register, 'currency' => 'BRL', 'opening_amount' => '900000000000000000']);
        $overflow = $this->request($this->postInput($a, "cash-sessions/{$session}/movements", ['direction' => 'in', 'kind' => 'deposit', 'amount' => '900000000000000000', 'reason' => 'Overflow'], 'overflow'));
        $this->assertSame(422, $overflow['status']);
        $this->assertSame(0, DB::table('erp_cash_movements')->where('erp_cash_session_id', $session)->count());
        foreach ([['withdrawal', 'out'], ['deposit', 'in'], ['withdrawal', 'out'], ['deposit', 'in']] as [$kind, $direction]) {
            $this->create($a, "cash-sessions/{$session}/movements", ['direction' => $direction, 'kind' => $kind, 'amount' => '900000000000000000', 'reason' => 'Turnover']);
        }
        $detail = $this->request($a + ['path' => '/api/v1/erp/cash-sessions/'.$session]);
        $this->assertSame(200, $detail['status']);
        $this->assertSame('1800000000000000000.0000', $detail['body']['data']['movements_in']);
        $closed = $this->request($this->postInput($a, "cash-sessions/{$session}/close", ['declared_closing_amount' => '900000000000000000'], 'wide-close'));
        $this->assertSame(200, $closed['status']);
        $this->assertSame('0.0000', $closed['body']['data']['closing_difference']);
    }
}
