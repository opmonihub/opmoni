<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpContact;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpStockLotConcurrencyTest extends TestCase
{
    private array $baseline = [];

    private string $clientIp;

    private ErpCompany $company;

    private ErpUnit $unit;

    private ErpEstablishment $establishment;

    private ErpProductVariant $variant;

    /** @var array{user: User, company: ErpCompany, unit: ErpUnit} */
    private array $firstActor;

    /** @var array{user: User, company: ErpCompany, unit: ErpUnit} */
    private array $secondActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task7-20261020', getenv('ERP_STOCK_ISOLATED'));
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
        $this->assertStringStartsWith('18.', DB::selectOne('show server_version')->server_version);
        $this->assertStringStartsWith('8.', Redis::connection('cache')->info('server')['redis_version']);

        $this->assertTrue(Schema::hasTable('erp_stock_cost_settlements'), 'Root must approve and apply the exact additive stock migration.');

        foreach (['erp_contacts', 'erp_products', 'erp_product_variants', 'erp_units', 'erp_operation_receipts', 'erp_stock_balances', 'erp_stock_movements', 'erp_stock_lots', 'erp_stock_allocations', 'erp_stock_cost_settlements'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->baseline[$table] = ['ids' => $rows->pluck('id')->all(), 'hash' => hash('sha256', $rows->toJson())];
        }

        $this->company = $this->createCompany();
        $this->clientIp = '10.77.'.intdiv($this->company->id % 60000, 250).'.'.($this->company->id % 250 + 1);
        $this->unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $this->firstActor = $this->owner($this->company, $this->unit);
        $this->secondActor = $this->firstActor;
        $this->establishment = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $product = ErpProduct::factory()->create(['erp_company_id' => $this->company->id, 'erp_unit_id' => $this->unit->id, 'kind' => 'goods', 'track_stock' => true]);
        $this->variant = ErpProductVariant::factory()->create(['erp_product_id' => $product->id]);
        fwrite(STDERR, "Task7 retained Company {$this->company->id}, establishment {$this->establishment->id}, variant {$this->variant->id}\n");
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->baseline as $table => $baseline) {
                $this->assertSame($baseline['hash'], hash('sha256', DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get()->toJson()), 'Existing rows changed in '.$table);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array{user: User, company: ErpCompany, unit: ErpUnit} */
    private function owner(ErpCompany $company, ErpUnit $unit): array
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

        return compact('user', 'company', 'unit');
    }

    private function createCompany(): ErpCompany
    {
        return ErpCompany::factory()->create([
            'cnpj' => (string) random_int(10000000000000, 99999999999999),
        ]);
    }

    private function worker(array $input): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpStockLotConcurrencyWorker.php')], base_path());
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

    private function waitForCompanyLock(string $label, string $ready): void
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $identity = file_exists($ready) ? json_decode(file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR) : null;
            $waiting = $identity === null ? 0 : DB::selectOne("select count(*) as count from pg_stat_activity where pid = ? and datname = ? and application_name = ? and wait_event_type = 'Lock'", [$identity['pg_pid'], 'opmoni_test', $label])->count;
            if ($identity !== null && (int) $waiting === 1) {
                $this->assertSame('opmoni_test', $identity['database']);
                fwrite(STDERR, "Barrier confirmed: {$label}, OS PID {$identity['pid']}, PostgreSQL PID {$identity['pg_pid']}, database {$identity['database']}, waiting on Company row lock\n");

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('HTTP worker did not reach the Company lock barrier: '.$label);
    }

    private function race(array $first, array $second): array
    {
        $readyA = tempnam(sys_get_temp_dir(), 'stock-ready-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'stock-ready-b-');
        unlink($readyA);
        unlink($readyB);
        $workerA = $workerB = null;
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker($first + ['label' => 'erp-stock-race-a', 'ready' => $readyA]);
            $workerB = $this->worker($second + ['label' => 'erp-stock-race-b', 'ready' => $readyB]);
            $this->waitForCompanyLock('erp-stock-race-a', $readyA);
            $this->waitForCompanyLock('erp-stock-race-b', $readyB);
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

    private function purchase(array $session, string $quantity, string $cost, ?int $establishment = null, bool $post = true): int
    {
        $supplier = ErpContact::factory()->create(['erp_company_id' => $this->company->id, 'is_supplier' => true]);
        $result = $this->request($this->postInput($session, 'purchases', ['erp_establishment_id' => $establishment ?? $this->establishment->id, 'erp_contact_id' => $supplier->id, 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_cost' => $cost]]], 'draft-'.bin2hex(random_bytes(6))));
        $this->assertSame(201, $result['status'], json_encode($result['body']));
        $id = $result['body']['data']['id'];
        if ($post) {
            $result = $this->request($this->postInput($session, 'purchases/'.$id.'/post', [], 'purchase-'.$id));
            $this->assertSame(200, $result['status'], json_encode($result['body']));
        }

        return $id;
    }

    private function sale(array $session, string $quantity, bool $post = false): int
    {
        $result = $this->request($this->postInput($session, 'sales', ['erp_establishment_id' => $this->establishment->id, 'invoice_no' => 'PG-'.bin2hex(random_bytes(6)), 'lines' => [['erp_product_variant_id' => $this->variant->id, 'quantity' => $quantity, 'unit_price' => '40']]], 'draft-sale-'.bin2hex(random_bytes(6))));
        $this->assertSame(201, $result['status'], json_encode($result['body']));
        $id = $result['body']['data']['id'];
        if ($post) {
            $result = $this->request($this->postInput($session, 'sales/'.$id.'/post', [], 'sale-'.$id));
            $this->assertSame(200, $result['status'], json_encode($result['body']));
        }

        return $id;
    }

    private function quantity(int $establishment): string
    {
        return bcadd((string) DB::table('erp_stock_balances')->where('erp_company_id', $this->company->id)->where('erp_establishment_id', $establishment)->where('erp_product_variant_id', $this->variant->id)->value('quantity_on_hand'), '0', 4);
    }

    private function statuses(array $results): array
    {
        $statuses = array_column($results, 'status');
        sort($statuses);

        return $statuses;
    }

    public function test_independent_sales_compete_for_the_final_actual_receipt_lot(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $this->assertNotSame($a['cookies']['opmoni-session'], $b['cookies']['opmoni-session']);
        $this->purchase($a, '1', '10');
        $first = $this->sale($a, '1');
        $second = $this->sale($b, '1');
        $results = $this->race($this->postInput($a, 'sales/'.$first.'/post', [], 'final-a'), $this->postInput($b, 'sales/'.$second.'/post', [], 'final-b'));
        $this->assertSame([200, 422], $this->statuses($results), json_encode($results));
        $this->assertSame('0.0000', $this->quantity($this->establishment->id));
        $allocations = DB::table('erp_stock_allocations')->where('erp_company_id', $this->company->id)->get();
        $this->assertCount(1, $allocations);
        $this->assertSame('10.0000', (string) $allocations[0]->cost);
        $this->assertSame(1, DB::table('erp_sales')->where('erp_company_id', $this->company->id)->where('status', 'posted')->count());
    }

    public function test_independent_equal_sale_posts_replay_one_original_result(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $this->purchase($a, '2', '10');
        $sale = $this->sale($a, '1');
        $results = $this->race($this->postInput($a, 'sales/'.$sale.'/post', [], 'same-sale'), $this->postInput($b, 'sales/'.$sale.'/post', [], 'same-sale'));
        $this->assertSame([200, 200], $this->statuses($results), json_encode($results));
        $this->assertSame($results[0]['body'], $results[1]['body']);
        $this->assertSame('1.0000', $this->quantity($this->establishment->id));
        $this->assertSame(1, DB::table('erp_stock_allocations')->where('erp_company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->where('operation', 'erp.sales.post')->count());
    }

    public function test_independent_partial_reversals_cannot_exceed_original_receipt(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $result = $this->request($this->postInput($a, 'stock/adjustments', ['erp_establishment_id' => $this->establishment->id, 'erp_product_variant_id' => $this->variant->id, 'quantity_delta' => '1', 'reason' => 'Retained race fixture'], 'opening'));
        $this->assertSame(201, $result['status'], json_encode($result['body']));
        $movement = DB::table('erp_stock_movements')->where('erp_company_id', $this->company->id)->sole();
        $results = $this->race($this->postInput($a, 'stock/movements/'.$movement->id.'/reverse', ['quantity' => '0.75', 'reason' => 'Partial A'], 'reverse-a'), $this->postInput($b, 'stock/movements/'.$movement->id.'/reverse', ['quantity' => '0.75', 'reason' => 'Partial B'], 'reverse-b'));
        $this->assertSame([201, 422], $this->statuses($results), json_encode($results));
        $this->assertSame('0.2500', $this->quantity($this->establishment->id));
        $this->assertSame(1, DB::table('erp_stock_movements')->where('reversal_of_id', $movement->id)->count());
    }

    public function test_independent_receipts_settle_the_final_deficit_once(): void
    {
        $this->company->update(['operating_settings' => ['stock_overselling_enabled' => true]]);
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $this->purchase($a, '1', '10');
        $sale = $this->sale($a, '2', true);
        $issue = DB::table('erp_stock_movements')->where('erp_company_id', $this->company->id)->where('source_type', 'erp_sale')->where('source_id', $sale)->sole();
        $before = (array) $issue;
        $first = $this->purchase($a, '1', '20', post: false);
        $second = $this->purchase($b, '1', '30', post: false);
        $results = $this->race($this->postInput($a, 'purchases/'.$first.'/post', [], 'settle-a'), $this->postInput($b, 'purchases/'.$second.'/post', [], 'settle-b'));
        $this->assertSame([200, 200], $this->statuses($results), json_encode($results));
        $this->assertSame('1.0000', $this->quantity($this->establishment->id));
        $settlements = DB::table('erp_stock_cost_settlements')->where('erp_company_id', $this->company->id)->get();
        $this->assertCount(1, $settlements);
        $this->assertContains((string) $settlements[0]->cost, ['20.0000', '30.0000']);
        $this->assertSame($before, (array) DB::table('erp_stock_movements')->find($issue->id));
        $deficit = DB::table('erp_stock_allocations')->where('erp_stock_movement_id', $issue->id)->whereNull('erp_stock_lot_id')->sole();
        $this->assertSame('1.0000', (string) $deficit->settled_quantity);
    }

    public function test_independent_opposing_transfers_keep_both_positions_and_exact_cost(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->purchase($a, '1', '10');
        $this->purchase($a, '1', '20', $destination->id);
        $body = ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '1'];
        $results = $this->race($this->postInput($a, 'stock/transfers', $body, 'outbound'), $this->postInput($b, 'stock/transfers', [...$body, 'from_establishment_id' => $destination->id, 'to_establishment_id' => $this->establishment->id], 'inbound'));
        $this->assertSame([201, 201], $this->statuses($results), json_encode($results));
        $this->assertSame('1.0000', $this->quantity($this->establishment->id));
        $this->assertSame('1.0000', $this->quantity($destination->id));
        $this->assertSame(2, DB::table('erp_stock_transfers')->where('erp_company_id', $this->company->id)->count());
        $this->assertSame(6, DB::table('erp_stock_movements')->where('erp_company_id', $this->company->id)->count());
        $cost = '0.0000';
        foreach (DB::table('erp_stock_lots')->where('erp_company_id', $this->company->id)->where('available_quantity', '>', 0)->get() as $lot) {
            $cost = bcadd($cost, bcsub((string) $lot->total_cost, (string) $lot->consumed_cost, 4), 4);
        }
        $this->assertSame('30.0000', $cost);
    }

    public function test_independent_duplicate_transfer_reuses_one_pair_and_changed_payload_conflicts(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->purchase($a, '3', '10');
        $body = ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '1'];
        $results = $this->race($this->postInput($a, 'stock/transfers', $body, 'same-transfer'), $this->postInput($b, 'stock/transfers', $body, 'same-transfer'));
        $this->assertSame([201, 201], $this->statuses($results), json_encode($results));
        $this->assertSame($results[0]['body'], $results[1]['body']);
        $this->assertSame('2.0000', $this->quantity($this->establishment->id));
        $this->assertSame('1.0000', $this->quantity($destination->id));
        $changed = $this->request($this->postInput($a, 'stock/transfers', [...$body, 'quantity' => '2'], 'same-transfer'));
        $this->assertSame(409, $changed['status']);
        $this->assertSame(1, DB::table('erp_stock_transfers')->where('erp_company_id', $this->company->id)->count());
    }

    public function test_independent_transfer_reversals_share_one_cumulative_bound(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $destination = ErpEstablishment::factory()->create(['erp_company_id' => $this->company->id]);
        $this->purchase($a, '1', '10');
        $body = ['from_establishment_id' => $this->establishment->id, 'to_establishment_id' => $destination->id, 'erp_product_variant_id' => $this->variant->id, 'quantity' => '1'];
        $transfer = $this->request($this->postInput($a, 'stock/transfers', $body, 'reversible-transfer'));
        $this->assertSame(201, $transfer['status'], json_encode($transfer['body']));
        $id = $transfer['body']['data']['id'];
        $results = $this->race($this->postInput($a, 'stock/transfers/'.$id.'/reverse', ['quantity' => '0.75', 'reason' => 'Partial A'], 'pair-a'), $this->postInput($b, 'stock/transfers/'.$id.'/reverse', ['quantity' => '0.75', 'reason' => 'Partial B'], 'pair-b'));
        $this->assertSame([201, 422], $this->statuses($results), json_encode($results));
        $this->assertSame('0.7500', $this->quantity($this->establishment->id));
        $this->assertSame('0.2500', $this->quantity($destination->id));
        $this->assertSame(2, DB::table('erp_stock_movements')->where('erp_company_id', $this->company->id)->where('source_type', 'erp_stock_transfer_return')->count());
    }
}
