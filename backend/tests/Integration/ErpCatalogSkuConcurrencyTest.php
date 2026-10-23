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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCatalogSkuConcurrencyTest extends TestCase
{
    private ErpCompany $company;

    private ErpUnit $unit;

    /** @var array{user: User, company: ErpCompany, unit: ErpUnit} */
    private array $firstActor;

    /** @var array{user: User, company: ErpCompany, unit: ErpUnit} */
    private array $secondActor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('task5-20261018', getenv('ERP_CATALOG_ISOLATED'));
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

        Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);

        $this->company = $this->createCompany();
        $this->unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $this->firstActor = $this->owner($this->company, $this->unit);
        $this->secondActor = $this->owner($this->company, $this->unit);
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
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpCatalogSkuConcurrencyWorker.php')], base_path());
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

    public function test_independent_product_and_variant_sku_writers_serialize_without_partial_rows(): void
    {
        $firstSession = $this->login($this->firstActor);
        $secondSession = $this->login($this->secondActor);
        $this->assertNotSame($firstSession['cookies']['opmoni-session'], $secondSession['cookies']['opmoni-session']);

        $readyA = tempnam(sys_get_temp_dir(), 'erp-catalog-sku-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'erp-catalog-sku-b-');
        unlink($readyA);
        unlink($readyB);

        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker($this->productCreate($firstSession, $this->unit->id, 'Product SKU writer', 'RACE-SKU') + [
                'label' => 'erp-catalog-sku-race-a',
                'ready' => $readyA,
            ]);
            $workerB = $this->worker($this->productCreate($secondSession, $this->unit->id, 'Variant SKU writer', 'VARIANT-PARENT', 'RACE-SKU') + [
                'label' => 'erp-catalog-sku-race-b',
                'ready' => $readyB,
            ]);
            $this->waitForCompanyLock('erp-catalog-sku-race-a', $readyA);
            $this->waitForCompanyLock('erp-catalog-sku-race-b', $readyB);
            DB::commit();

            $first = $this->response($workerA);
            $second = $this->response($workerB);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            @unlink($readyA);
            @unlink($readyB);
        }

        $statuses = [$first['status'], $second['status']];
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $this->assertNotSame($first['pid'], $second['pid']);
        $rejected = $first['status'] === 422 ? $first : $second;
        $this->assertArrayHasKey('errors', $rejected['body']);
        $this->assertCount(1, $rejected['body']['errors']);
        $companyProducts = ErpProduct::query()->where('erp_company_id', $this->company->id)->get();
        $this->assertCount(1, $companyProducts);
        $this->assertSame(1, ErpProductVariant::query()->whereIn('erp_product_id', $companyProducts->pluck('id'))->count());

        $storedSkuCount = ErpProduct::query()->where('erp_company_id', $this->company->id)->where('sku', 'RACE-SKU')->count()
            + ErpProductVariant::query()->whereIn('erp_product_id', $companyProducts->pluck('id'))->where('sku', 'RACE-SKU')->count();
        $this->assertSame(1, $storedSkuCount);
    }

    public function test_same_sku_is_allowed_across_companies_across_product_and_variant_tables(): void
    {
        $otherCompany = $this->createCompany();
        $otherUnit = ErpUnit::factory()->create(['erp_company_id' => $otherCompany->id]);
        $otherActor = $this->owner($otherCompany, $otherUnit);

        $first = $this->request($this->productCreate($this->login($this->firstActor), $this->unit->id, 'Company One', 'CROSS-COMPANY'));
        $second = $this->request($this->productCreate($this->login($otherActor), $otherUnit->id, 'Company Two', 'OTHER-PARENT', 'CROSS-COMPANY'));

        $this->assertSame(201, $first['status']);
        $this->assertSame(201, $second['status']);
        $this->assertSame(1, ErpProduct::query()->where('erp_company_id', $this->company->id)->count());
        $this->assertSame(1, ErpProduct::query()->where('erp_company_id', $otherCompany->id)->count());
        $this->assertSame(1, ErpProduct::query()->where('erp_company_id', $this->company->id)->where('sku', 'CROSS-COMPANY')->count());
        $freshProductIds = ErpProduct::query()->whereIn('erp_company_id', [$this->company->id, $otherCompany->id])->pluck('id');
        $this->assertSame(1, ErpProductVariant::query()->whereIn('erp_product_id', $freshProductIds)->where('sku', 'CROSS-COMPANY')->count());
    }
}
