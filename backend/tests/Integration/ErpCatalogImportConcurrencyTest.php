<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
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

class ErpCatalogImportConcurrencyTest extends TestCase
{
    private array $baseline = [];

    private ErpCompany $company;

    private ErpUnit $unit;

    /** @var array{user: User, company: ErpCompany, unit: ErpUnit} */
    private array $firstActor;

    /** @var array{user: User, company: ErpCompany, unit: ErpUnit} */
    private array $secondActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task6-20261020', getenv('ERP_IMPORT_ISOLATED'));
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

        $this->assertTrue(Schema::hasTable('erp_catalog_imports'), 'Root must approve and apply the exact additive import migration.');

        foreach (['erp_contacts', 'erp_products', 'erp_product_variants', 'erp_units', 'erp_brands', 'erp_categories', 'erp_operation_receipts'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->baseline[$table] = ['ids' => $rows->pluck('id')->all(), 'hash' => hash('sha256', $rows->toJson())];
        }

        $this->company = $this->createCompany();
        $this->unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $this->firstActor = $this->owner($this->company, $this->unit);
        $this->secondActor = $this->firstActor;
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
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpCatalogImportConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
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

    private function productCreate(array $session, int $unitId, string $name, string $sku, ?string $variantSku = null): array
    {
        return $session + [
            'method' => 'POST',
            'path' => '/api/v1/erp/products',
            'body' => [
                'name' => $name,
                'sku' => $variantSku === null ? $sku : 'PARENT-'.$sku,
                'erp_unit_id' => $unitId,
                'sale_price' => '10.0000',
                'variants' => [[
                    'name' => 'Default variant',
                    'sku' => $variantSku,
                ]],
            ],
        ];
    }

    private function waitForCompanyLock(string $label, string $ready): void
    {
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select count(*) as count from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$label])->count;
            if (file_exists($ready) && (int) $waiting === 1) {
                fwrite(STDERR, "Barrier confirmed: {$label}, PID ".file_get_contents($ready).", waiting on Company row lock\n");

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('HTTP worker did not reach the Company lock barrier: '.$label);
    }

    private function preview(array $session, string $csv): string
    {
        $result = $this->request($session + ['method' => 'POST', 'path' => '/api/v1/erp/catalog/import/products/preview', 'csv' => $csv]);
        $this->assertSame(200, $result['status'], json_encode($result['body']));

        return $result['body']['data']['token'];
    }

    private function confirmation(array $session, string $token): array
    {
        $session['headers']['Idempotency-Key'] = 'pg-import-original';

        return $session + ['method' => 'POST', 'path' => '/api/v1/erp/catalog/import/products/confirm', 'body' => ['token' => $token, 'erp_unit_id' => $this->unit->id]];
    }

    private function race(array $first, array $second): array
    {
        $readyA = tempnam(sys_get_temp_dir(), 'import-ready-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'import-ready-b-');
        unlink($readyA);
        unlink($readyB);
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker($first + ['label' => 'erp-import-race-a', 'ready' => $readyA]);
            $workerB = $this->worker($second + ['label' => 'erp-import-race-b', 'ready' => $readyB]);
            $this->waitForCompanyLock('erp-import-race-a', $readyA);
            $this->waitForCompanyLock('erp-import-race-b', $readyB);
            DB::commit();
            $a = $this->response($workerA);
            $b = $this->response($workerB);
            $this->assertNotSame($a['pid'], $b['pid']);

            return [$a, $b];
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            @unlink($readyA);
            @unlink($readyB);
        }
    }

    public function test_independent_same_key_confirms_commit_one_full_original_result(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $this->assertNotSame($a['cookies']['opmoni-session'], $b['cookies']['opmoni-session']);
        $token = $this->preview($a, "name,sku\nFirst,0\nSecond,000002\n");
        [$first, $second] = $this->race($this->confirmation($a, $token), $this->confirmation($b, $token));
        $this->assertSame(200, $first['status'], json_encode($first['body']));
        $this->assertSame($first['body'], $second['body']);
        $this->assertSame(200, $second['status']);
        $this->assertSame(['data' => ['created' => 2]], $first['body']);
        $products = ErpProduct::where('erp_company_id', $this->company->id)->get();
        $this->assertCount(2, $products);
        $this->assertSame(2, ErpProductVariant::whereIn('erp_product_id', $products->pluck('id'))->count());
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('erp_catalog_imports')->where('erp_company_id', $this->company->id)->whereNotNull('completed_at')->count());
    }

    public function test_import_and_ordinary_variant_sku_race_has_one_complete_winner(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $token = $this->preview($a, "name,sku\nFirst,SAFE-ROW\nSecond,RACE-SKU\n");
        [$first, $second] = $this->race($this->confirmation($a, $token), $this->productCreate($b, $this->unit->id, 'CRUD competitor', 'OTHER', 'RACE-SKU'));
        $statuses = [$first['status'], $second['status']];
        sort($statuses);
        $this->assertContains($statuses, [[200, 422], [201, 422]]);
        $products = ErpProduct::where('erp_company_id', $this->company->id)->get();
        $importWon = $first['status'] === 200;
        $this->assertCount($importWon ? 2 : 1, $products);
        $this->assertSame($importWon ? 2 : 1, ErpProductVariant::whereIn('erp_product_id', $products->pluck('id'))->count());
        $this->assertSame($importWon ? 1 : 0, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count());
        $this->assertSame($importWon ? 1 : 0, DB::table('erp_catalog_imports')->where('erp_company_id', $this->company->id)->whereNotNull('completed_at')->count());
        $rejected = $importWon ? $second : $first;
        $this->assertArrayHasKey('errors', $rejected['body']);
    }

    public function test_independent_changed_choice_competitor_conflicts_without_a_second_effect(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $otherUnit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $token = $this->preview($a, "name,sku\nFirst,CHOICE\n");
        $changed = $this->confirmation($b, $token);
        $changed['body']['erp_unit_id'] = $otherUnit->id;
        [$first, $second] = $this->race($this->confirmation($a, $token), $changed);
        $statuses = [$first['status'], $second['status']];
        sort($statuses);
        $this->assertSame([200, 409], $statuses);
        $this->assertSame(1, ErpProduct::where('erp_company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count());
    }
}
