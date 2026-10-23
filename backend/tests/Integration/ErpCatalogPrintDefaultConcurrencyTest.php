<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Erp\ErpCatalogPrintLayout;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCatalogPrintDefaultConcurrencyTest extends TestCase
{
    private ErpCompany $company;

    /** @var array{user: User, company: ErpCompany} */
    private array $firstActor;

    /** @var array{user: User, company: ErpCompany} */
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

        config(['erp.operating_writes_enabled' => true]);
        $this->company = ErpCompany::factory()->create([
            'cnpj' => (string) random_int(10000000000000, 99999999999999),
        ]);
        $plan = app(ErpCommercialCatalog::class)->ensureBasicPlan();
        ErpSubscription::factory()->create([
            'erp_company_id' => $this->company->id,
            'erp_plan_id' => $plan->id,
            'status' => 'active',
        ]);
        $this->firstActor = $this->owner($this->company);
        $this->secondActor = $this->owner($this->company);
    }

    /** @return array{user: User, company: ErpCompany} */
    private function owner(ErpCompany $company): array
    {
        $user = User::factory()->create();
        ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
        ]);

        return compact('user', 'company');
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

        $this->fail('Label configuration worker did not reach the Company lock barrier: '.$label);
    }

    public function test_independent_default_switches_serialize_on_the_company_row(): void
    {
        $firstSession = $this->login($this->firstActor);
        $secondSession = $this->login($this->secondActor);
        $this->assertNotSame($firstSession['cookies']['opmoni-session'], $secondSession['cookies']['opmoni-session']);

        $firstLayout = ErpCatalogPrintLayout::factory()->create([
            'erp_company_id' => $this->company->id,
            'is_default' => true,
        ]);
        $secondLayout = ErpCatalogPrintLayout::factory()->create([
            'erp_company_id' => $this->company->id,
            'is_default' => false,
        ]);
        $readyA = tempnam(sys_get_temp_dir(), 'erp-label-default-a-');
        $readyB = tempnam(sys_get_temp_dir(), 'erp-label-default-b-');
        unlink($readyA);
        unlink($readyB);
        $workerA = null;
        $workerB = null;

        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker([
                'path' => '/api/v1/erp/catalog/label-layouts/'.$firstLayout->id.'/default',
                'method' => 'PATCH',
                'cookies' => $firstSession['cookies'],
                'headers' => $firstSession['headers'],
                'body' => [],
                'label' => 'erp-catalog-label-default-a',
                'ready' => $readyA,
            ]);
            $workerB = $this->worker([
                'path' => '/api/v1/erp/catalog/label-layouts/'.$secondLayout->id.'/default',
                'method' => 'PATCH',
                'cookies' => $secondSession['cookies'],
                'headers' => $secondSession['headers'],
                'body' => [],
                'label' => 'erp-catalog-label-default-b',
                'ready' => $readyB,
            ]);
            $this->waitForCompanyLock('erp-catalog-label-default-a', $readyA);
            $this->waitForCompanyLock('erp-catalog-label-default-b', $readyB);
            DB::commit();

            $first = $this->response($workerA);
            $second = $this->response($workerB);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($workerA instanceof Process && $workerA->isRunning()) {
                $workerA->stop(1);
            }
            if ($workerB instanceof Process && $workerB->isRunning()) {
                $workerB->stop(1);
            }
            @unlink($readyA);
            @unlink($readyB);
        }

        $this->assertSame(200, $first['status'], json_encode($first['body']));
        $this->assertSame(200, $second['status'], json_encode($second['body']));
        $this->assertNotSame($first['pid'], $second['pid']);
        $this->assertTrue($first['body']['data']['is_default']);
        $this->assertTrue($second['body']['data']['is_default']);
        $defaults = ErpCatalogPrintLayout::query()
            ->where('erp_company_id', $this->company->id)
            ->where('is_default', true)
            ->pluck('id')
            ->all();
        $this->assertCount(1, $defaults);
        $this->assertContains($defaults[0], [$firstLayout->id, $secondLayout->id]);

        fwrite(STDERR, sprintf(
            "Task26 retained synthetic Company %d and label layouts %d,%d; final default %d\n",
            $this->company->id,
            $firstLayout->id,
            $secondLayout->id,
            $defaults[0],
        ));
    }
}
