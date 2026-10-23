<?php

namespace Tests\Integration;

use App\Erp\ErpCommercialCatalog;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpUnit;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ErpCatalogImportConcurrencyTest extends TestCase
{
    private array $baseline = [];

    /** @var list<Process> */
    private array $workers = [];

    /** @var list<string> */
    private array $temporaryPaths = [];

    private ?string $temporaryDirectory = null;

    private array $temporaryDirectoryIdentity = [];

    private array $temporaryFileIdentities = [];

    private static bool $baselineReported = false;

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
        $this->assertSame('task20-20261022', getenv('ERP_CATALOG_ATTRIBUTION_ISOLATED'));
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
        $this->assertTrue(Schema::hasTable('erp_catalog_attributions'), 'Coordinator must apply the exact additive attribution migration.');

        foreach (['erp_contacts', 'erp_products', 'erp_product_variants', 'erp_units', 'erp_brands', 'erp_categories', 'erp_operation_receipts', 'erp_catalog_imports', 'erp_catalog_attributions', 'erp_companies', 'users', 'erp_company_memberships', 'erp_office_delegations', 'accounts', 'account_memberships', 'erp_subscriptions', 'erp_plans'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();
            $this->baseline[$table] = ['ids' => $rows->pluck('id')->all(), 'hash' => hash('sha256', $rows->toJson())];
        }
        if (! self::$baselineReported) {
            fwrite(STDERR, 'Attribution baseline '.json_encode(array_map(fn (array $entry): array => ['rows' => count($entry['ids']), 'sha256' => $entry['hash']], $this->baseline), JSON_THROW_ON_ERROR)."\n");
            self::$baselineReported = true;
        }

        $this->company = $this->createCompany();
        $this->unit = ErpUnit::factory()->create(['erp_company_id' => $this->company->id]);
        $this->firstActor = $this->owner($this->company, $this->unit);
        $this->secondActor = $this->firstActor;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
                $this->assertFalse($worker->isRunning(), 'An owned worker survived bounded termination.');
            }
            foreach ($this->temporaryPaths as $path) {
                $this->assertPrivateTemporaryFile($path);
                $this->assertTrue(unlink($path), 'Only this test owns this reserved temporary file.');
                $this->assertFileDoesNotExist($path);
            }
            if ($this->temporaryDirectory !== null) {
                $this->assertSame(['.', '..'], scandir($this->temporaryDirectory), 'Never clean unowned directory entries.');
                $this->assertTrue(rmdir($this->temporaryDirectory));
                $this->assertDirectoryDoesNotExist($this->temporaryDirectory);
                fwrite(STDERR, 'Filesystem privacy: exclusive directory 0700; retained files 0600; stable dev/inode/owner before writes and cleanup; removed '.count($this->temporaryPaths)." owned files and directory\n");
            }
            foreach ($this->baseline as $table => $baseline) {
                $this->assertSame($baseline['hash'], hash('sha256', DB::table($table)->whereIn('id', $baseline['ids'])->orderBy('id')->get()->toJson()), 'Existing rows changed in '.$table);
            }
            fwrite(STDERR, 'Preserved baseline '.hash('sha256', json_encode($this->baseline, JSON_THROW_ON_ERROR)).'; reaped '.count($this->workers).' workers; cleaned '.count($this->temporaryPaths)." owned paths\n");
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
        $input['client_ip'] = '198.19.'.intdiv($this->company->id % 65025, 255).'.'.($this->company->id % 255);
        if (isset($input['csv'])) {
            $input['csv_path'] = $this->temporaryPath();
        }
        $input['private_directory'] = $this->temporaryDirectory;
        $input['private_directory_identity'] = $this->temporaryDirectoryIdentity;
        $input['private_file_identities'] = $this->temporaryFileIdentities;
        $process = new Process([PHP_BINARY, base_path('tests/Integration/ErpCatalogImportConcurrencyWorker.php')], base_path());
        $process->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $process->setTimeout(30);
        foreach ($this->temporaryPaths as $path) {
            $this->assertPrivateTemporaryFile($path);
        }
        $process->start();
        $this->workers[] = $process;

        return $process;
    }

    private function response(Process $process): array
    {
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertGreaterThanOrEqual(4 * $result['private_file_writes'], $result['private_file_checks'], 'Every worker write verifies directory, reserved path and open descriptor before writing, then verifies again afterwards.');
        fwrite(STDERR, 'Worker '.json_encode(array_intersect_key($result, array_flip(['pid', 'pg_pid', 'application_name', 'status', 'transaction_level', 'attribution_inserts', 'receipt_inserts', 'failure_after_insert', 'outer_fence', 'private_file_checks', 'private_file_writes', 'temporary_umask'])), JSON_THROW_ON_ERROR)."\n");

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

        $session = [
            'cookies' => $csrf['cookies'] ?: $login['cookies'],
            'headers' => [
                'X-CSRF-Token' => $csrf['body']['csrf_token'],
                'X-Company-Id' => (string) $actor['company']->id,
            ],
        ];
        $selection = $this->request($session + ['method' => 'POST', 'path' => '/api/v1/erp/company/switch', 'body' => ['company_id' => $actor['company']->id]]);
        $this->assertSame(200, $selection['status'], json_encode($selection['body']));
        $session['cookies'] = $selection['cookies'] ?: $session['cookies'];
        $csrf = $this->request(['path' => '/api/v1/csrf', 'cookies' => $session['cookies']]);
        $session['cookies'] = $csrf['cookies'] ?: $session['cookies'];
        $session['headers']['X-CSRF-Token'] = $csrf['body']['csrf_token'];

        return $session;
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

    private function temporaryPath(): string
    {
        if ($this->temporaryDirectory === null) {
            $directory = sys_get_temp_dir().'/task20-attribution-'.bin2hex(random_bytes(16));
            $this->assertTrue(mkdir($directory, 0700), 'Reserve a new private directory atomically, never reuse an existing path.');
            $this->temporaryDirectory = $directory;
            $stat = lstat($directory);
            $this->assertIsArray($stat);
            $this->temporaryDirectoryIdentity = array_intersect_key($stat, array_flip(['dev', 'ino', 'uid']));
            $this->assertSame(0700, $stat['mode'] & 07777);
        }
        $path = tempnam($this->temporaryDirectory, 'reserved-');
        $this->assertIsString($path);
        $this->temporaryPaths[] = $path;
        $this->assertFileExists($path, 'Private temporary files must remain exclusively reserved before their first write.');
        $stat = lstat($path);
        $this->assertIsArray($stat);
        $this->temporaryFileIdentities[$path] = array_intersect_key($stat, array_flip(['dev', 'ino', 'uid']));
        $this->assertPrivateTemporaryFile($path);
        $this->assertSame(0, $stat['size']);
        $this->assertSame('', $this->readPrivateTemporaryFile($path), 'A reserved but empty file must not signal readiness.');

        return $path;
    }

    private function assertPrivateTemporaryFile(string $path): void
    {
        $this->assertSame($this->temporaryDirectory, dirname($path));
        foreach ([$this->temporaryDirectory => [$this->temporaryDirectoryIdentity, 0700, 0040000], $path => [$this->temporaryFileIdentities[$path], 0600, 0100000]] as $entry => [$identity, $mode, $type]) {
            clearstatcache(true, $entry);
            $stat = lstat($entry);
            $this->assertIsArray($stat);
            $this->assertSame($identity, array_intersect_key($stat, array_flip(['dev', 'ino', 'uid'])), 'Reserved path identity changed.');
            $this->assertSame($mode, $stat['mode'] & 07777, 'Privacy must hold before any read, write, or cleanup.');
            $this->assertSame($type, $stat['mode'] & 0170000, 'Reserved paths must not be symlinks.');
            $this->assertSame($this->temporaryDirectoryIdentity['uid'], $stat['uid']);
        }
    }

    private function privateTemporaryContents(string $path, ?string $contents = null): string
    {
        $this->assertPrivateTemporaryFile($path);
        $handle = fopen($path, 'r+b');
        $this->assertIsResource($handle);
        try {
            $this->assertTrue(flock($handle, $contents === null ? LOCK_SH : LOCK_EX));
            $stat = fstat($handle);
            $this->assertSame($this->temporaryFileIdentities[$path], array_intersect_key($stat, array_flip(['dev', 'ino', 'uid'])));
            if ($contents !== null) {
                $this->assertTrue(ftruncate($handle, 0));
                $this->assertSame(strlen($contents), fwrite($handle, $contents));
                $this->assertTrue(fflush($handle));
                $this->assertPrivateTemporaryFile($path);

                return $contents;
            }
            $result = stream_get_contents($handle);
            $this->assertIsString($result);

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readPrivateTemporaryFile(string $path): string
    {
        return $this->privateTemporaryContents($path);
    }

    private function waitForCompanyLock(string $label, string $ready, Process $worker): int
    {
        $holder = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = DB::selectOne("select pid, wait_event_type, wait_event, pg_blocking_pids(pid)::text as blockers from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'", [$label]);
            $contents = $this->readPrivateTemporaryFile($ready);
            if ($contents !== '' && $waiting) {
                $facts = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($worker->getPid(), $facts['pid']);
                $this->assertSame((int) $waiting->pid, $facts['pg_pid']);
                $this->assertSame($label, $facts['application_name']);
                $this->assertSame('company_for_update', $facts['barrier']);
                $this->assertSame($this->company->id, $facts['company_id']);
                $rooted = DB::selectOne(
                    'with recursive blockers(pid) as (select unnest(pg_blocking_pids(?)) union select unnest(pg_blocking_pids(blockers.pid)) from blockers) select count(*) as count from blockers where pid = ?',
                    [$waiting->pid, $holder],
                );
                $this->assertSame(1, (int) $rooted->count, 'The actual lock chain must reach our Company-lock holder.');
                $this->assertNotSame($holder, (int) $waiting->pid);
                fwrite(STDERR, 'Company barrier '.json_encode(['label' => $label, 'os_pid' => $facts['pid'], 'pg_pid' => (int) $waiting->pid, 'holder_pg_pid' => $holder, 'wait_event_type' => $waiting->wait_event_type, 'wait_event' => $waiting->wait_event, 'direct_blockers' => $waiting->blockers, 'company_id' => $facts['company_id']], JSON_THROW_ON_ERROR)."\n");

                return (int) $waiting->pid;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('HTTP worker did not reach the Company lock barrier: '.$label);
    }

    private function preview(array $session, string $csv, string $kind = 'products'): string
    {
        $result = $this->request($session + ['method' => 'POST', 'path' => '/api/v1/erp/catalog/import/'.$kind.'/preview', 'csv' => $csv]);
        $this->assertSame(200, $result['status'], json_encode($result['body']));
        $this->assertSame(0, $result['attribution_inserts']);
        $this->assertSame(0, $result['receipt_inserts']);
        $this->assertSame(0, $this->events()->count());
        $read = $this->request($session + ['method' => 'POST', 'path' => '/api/v1/erp/catalog/import/'.$kind.'/preview/read', 'body' => ['token' => $result['body']['data']['token']]]);
        $this->assertSame(200, $read['status']);
        $this->assertSame('pending', $read['body']['data']['state']);
        $this->assertSame(0, $read['attribution_inserts']);
        $this->assertSame(0, $read['receipt_inserts']);
        $this->assertSame(0, $this->events()->count());

        return $result['body']['data']['token'];
    }

    private function confirmation(array $session, string $token, string $kind = 'products'): array
    {
        $session['headers']['Idempotency-Key'] = 'pg-import-original';

        return $session + ['method' => 'POST', 'path' => '/api/v1/erp/catalog/import/'.$kind.'/confirm', 'body' => ['token' => $token, ...($kind === 'products' ? ['erp_unit_id' => $this->unit->id] : [])]];
    }

    private function race(array $first, array $second): array
    {
        $readyA = $this->temporaryPath();
        $readyB = $this->temporaryPath();
        $label = 'task20-import-'.getmypid().'-'.count($this->workers);
        $workerA = $workerB = null;
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $workerA = $this->worker($first + ['label' => $label.'-a', 'ready' => $readyA, 'company_id' => $this->company->id]);
            $pgA = $this->waitForCompanyLock($label.'-a', $readyA, $workerA);
            $workerB = $this->worker($second + ['label' => $label.'-b', 'ready' => $readyB, 'company_id' => $this->company->id]);
            $pgB = $this->waitForCompanyLock($label.'-b', $readyB, $workerB);
            $this->assertNotSame($pgA, $pgB);
            DB::commit();
            $a = $this->response($workerA);
            $b = $this->response($workerB);
            $this->assertNotSame($a['pid'], $b['pid']);

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
        $this->assertArrayHasKey('attribution_inserts', $first, 'Each real worker must count its own actual attribution INSERTs.');
        $counts = [$first['attribution_inserts'], $second['attribution_inserts']];
        sort($counts);
        $this->assertSame([0, 4], $counts);
        $receipts = [$first['receipt_inserts'], $second['receipt_inserts']];
        sort($receipts);
        $this->assertSame([0, 1], $receipts);
        $this->assertSame([4, 1], $first['attribution_inserts'] === 4 ? [$first['attribution_inserts'], $first['receipt_inserts']] : [$second['attribution_inserts'], $second['receipt_inserts']]);
        $this->assertAttribution(['product' => 2, 'product_variant' => 2], 'import_created', $this->batch()->id);
        $fingerprints = $this->effectsFingerprint();
        $third = $this->request($this->confirmation($a, $token));
        $this->assertSame(200, $third['status']);
        $this->assertSame($first['body'], $third['body']);
        $this->assertNotContains($third['pid'], [$first['pid'], $second['pid']]);
        $this->assertNotContains($third['pg_pid'], [$first['pg_pid'], $second['pg_pid']]);
        $this->assertSame([0, 0], [$third['attribution_inserts'], $third['receipt_inserts']]);
        $this->assertSame($fingerprints, $this->effectsFingerprint());
        fwrite(STDERR, 'Same-key winner/replay fingerprints '.json_encode($fingerprints, JSON_THROW_ON_ERROR)."\n");
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
        $this->assertSame(0, $rejected['attribution_inserts']);
        $this->assertSame(0, $rejected['receipt_inserts']);
        $this->assertTrue($importWon, 'The first confirmed PostgreSQL waiter must win this ordering.');
        $this->assertAttribution(['product' => 2, 'product_variant' => 2], 'import_created', $this->batch()->id);
        $this->assertSame([4, 1], [$first['attribution_inserts'], $first['receipt_inserts']]);
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
        $winner = $first['status'] === 200 ? $first : $second;
        $loser = $first['status'] === 409 ? $first : $second;
        $this->assertSame([2, 1], [$winner['attribution_inserts'], $winner['receipt_inserts']]);
        $this->assertSame([0, 0], [$loser['attribution_inserts'], $loser['receipt_inserts']]);
        $this->assertNotNull($this->batch()->completed_at);
        $this->assertAttribution(['product' => 1, 'product_variant' => 1], 'import_created', $this->batch()->id);
        $this->assertSame($first['status'] === 200 ? $this->unit->id : $otherUnit->id, ErpProduct::where('erp_company_id', $this->company->id)->sole()->erp_unit_id);
    }

    private function events(): Builder
    {
        return DB::table('erp_catalog_attributions')->where('erp_company_id', $this->company->id);
    }

    private function companyRows(string $table): Builder
    {
        return $table === 'erp_product_variants'
            ? DB::table($table)->whereIn('erp_product_id', DB::table('erp_products')->where('erp_company_id', $this->company->id)->select('id'))
            : DB::table($table)->where('erp_company_id', $this->company->id);
    }

    private function batch(): object
    {
        return DB::table('erp_catalog_imports')->where('erp_company_id', $this->company->id)->sole();
    }

    private function assertAttribution(array $types, string $action, ?int $batchId): void
    {
        $events = $this->events()->orderBy('id')->get();
        $this->assertCount(array_sum($types), $events);
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->where('user_id', $this->firstActor['user']->id)->sole();
        foreach ($events as $event) {
            $this->assertSame($this->company->id, $event->erp_company_id);
            $this->assertSame($this->firstActor['user']->id, $event->actor_user_id);
            $this->assertSame('direct', $event->access_kind);
            $this->assertSame($membership->id, $event->erp_company_membership_id);
            $this->assertNull($event->account_id);
            $this->assertNull($event->erp_office_delegation_id);
            $this->assertSame($action, $event->action);
            $this->assertSame($batchId, $event->batch_id);
            $this->assertNotNull($event->recorded_at);
            $this->assertTrue(Carbon::parse($event->recorded_at)->betweenIncluded($this->company->fresh()->created_at, now()));
            if ($batchId !== null) {
                $this->assertSame($this->firstActor['user']->id, $this->batch()->actor_user_id);
                $this->assertTrue(Carbon::parse($event->recorded_at)->betweenIncluded(Carbon::parse($this->batch()->created_at), Carbon::parse($this->batch()->completed_at)));
            }
        }
        foreach ($types as $type => $count) {
            $table = match ($type) {
                'product' => 'erp_products',
                'product_variant' => 'erp_product_variants',
                'contact' => 'erp_contacts',
            };
            $ids = $events->where('resource_type', $type)->pluck('resource_id')->all();
            $this->assertCount($count, $ids);
            $this->assertSame($count, count(array_unique($ids)));
            $resources = $this->companyRows($table)->orderBy('id')->pluck('id')->all();
            $this->assertEqualsCanonicalizing($resources, $ids);
        }
    }

    private function effectsFingerprint(): array
    {
        $fingerprints = [];
        foreach (['erp_products', 'erp_product_variants', 'erp_contacts', 'erp_catalog_attributions', 'erp_operation_receipts', 'erp_catalog_imports'] as $table) {
            $rows = $this->companyRows($table)->orderBy('id')->get();
            $fingerprints[$table] = ['rows' => $rows->count(), 'sha256' => hash('sha256', $rows->toJson())];
        }

        return $fingerprints;
    }

    private function assertPendingWithoutEffects(): void
    {
        foreach (['erp_products', 'erp_product_variants', 'erp_contacts', 'erp_catalog_attributions', 'erp_operation_receipts'] as $table) {
            $this->assertSame(0, $this->companyRows($table)->count(), $table);
        }
        $batch = $this->batch();
        $this->assertNull($batch->completed_at);
        $this->assertNull($batch->completed_key_digest);
        $this->assertNull($batch->completed_payload_hash);
        $this->assertNull($batch->result);
    }

    public function test_ordinary_crud_winner_leaves_import_pending_and_no_partial_import_event(): void
    {
        $a = $this->login($this->firstActor);
        $b = $this->login($this->secondActor);
        $token = $this->preview($a, "name,sku\nFirst,SAFE-ROW\nSecond,RACE-SKU\n");
        [$crud, $import] = $this->race($this->productCreate($b, $this->unit->id, 'CRUD competitor', 'OTHER', 'RACE-SKU'), $this->confirmation($a, $token));
        $this->assertSame(201, $crud['status']);
        $this->assertSame(422, $import['status']);
        $this->assertSame([2, 0], [$crud['attribution_inserts'], $crud['receipt_inserts']]);
        $this->assertSame([2, 0], [$import['attribution_inserts'], $import['receipt_inserts']], 'First-row insertions were real but must roll back when row two conflicts.');
        $this->assertAttribution(['product' => 1, 'product_variant' => 1], 'created', null);
        $this->assertNull($this->batch()->completed_at);
        $this->assertSame(0, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count());
    }

    public function test_contact_import_has_two_real_events_and_replay_has_no_insert(): void
    {
        $session = $this->login($this->firstActor);
        $token = $this->preview($session, "name,is_customer\nFirst,1\nSecond,1\n", 'contacts');
        $first = $this->request($this->confirmation($session, $token, 'contacts'));
        $this->assertSame(200, $first['status']);
        $this->assertSame(['data' => ['created' => 2]], $first['body']);
        $this->assertSame([2, 1], [$first['attribution_inserts'], $first['receipt_inserts']]);
        $this->assertAttribution(['contact' => 2], 'import_created', $this->batch()->id);
        $fingerprints = $this->effectsFingerprint();
        $replay = $this->request($this->confirmation($session, $token, 'contacts'));
        $this->assertSame(200, $replay['status']);
        $this->assertSame($first['body'], $replay['body']);
        $this->assertNotSame($first['pid'], $replay['pid']);
        $this->assertSame([0, 0], [$replay['attribution_inserts'], $replay['receipt_inserts']]);
        $this->assertSame($fingerprints, $this->effectsFingerprint());
        fwrite(STDERR, 'Contact winner/replay fingerprints '.json_encode($fingerprints, JSON_THROW_ON_ERROR)."\n");
    }

    private function narrowWhileBlocked(array $confirmation, \Closure $narrow): array
    {
        $ready = $this->temporaryPath();
        $label = 'task20-grant-'.getmypid();
        $worker = null;
        DB::beginTransaction();
        DB::table('erp_companies')->where('id', $this->company->id)->lockForUpdate()->first();
        try {
            $worker = $this->worker($confirmation + ['label' => $label, 'ready' => $ready, 'company_id' => $this->company->id]);
            $this->waitForCompanyLock($label, $ready, $worker);
            $narrow();
            DB::commit();

            return $this->response($worker);
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    public function test_direct_grant_narrowed_under_company_fence_is_reloaded_after_actual_wait(): void
    {
        $membership = ErpCompanyMembership::where('erp_company_id', $this->company->id)->where('user_id', $this->firstActor['user']->id)->sole();
        $membership->update(['action_permissions' => ['erp.read', 'erp.catalog.import.products.preview', 'erp.catalog.import.products.confirm']]);
        $session = $this->login($this->firstActor);
        $token = $this->preview($session, "name,sku\nFirst,GRANT-A\nSecond,GRANT-B\n");
        $before = $this->effectsFingerprint();
        $result = $this->narrowWhileBlocked($this->confirmation($session, $token), function () use ($membership): void {
            $membership->update(['action_permissions' => ['erp.read']]);
        });
        $this->assertSame(403, $result['status']);
        $this->assertSame(['erp.read'], $membership->fresh()->action_permissions);
        $this->assertSame([0, 0], [$result['attribution_inserts'], $result['receipt_inserts']]);
        $this->assertPendingWithoutEffects();
        $this->assertSame($before, $this->effectsFingerprint());
    }

    public function test_delegated_exact_import_grant_narrowed_under_company_fence_is_reloaded(): void
    {
        $user = User::factory()->create();
        $office = Account::factory()->create();
        AccountMembership::factory()->create(['user_id' => $user->id, 'account_id' => $office->id, 'role' => 'admin']);
        $delegation = ErpOfficeDelegation::factory()->create([
            'erp_company_id' => $this->company->id, 'account_id' => $office->id,
            'authorized_by_user_id' => $this->firstActor['user']->id,
            'permission_allowlist' => ['erp.read', 'erp.catalog.import.products.preview', 'erp.catalog.import.products.confirm'],
        ]);
        $this->assertFalse(ErpCompanyMembership::where('user_id', $user->id)->exists());
        $session = $this->login(['user' => $user, 'company' => $this->company, 'unit' => $this->unit]);
        $token = $this->preview($session, "name,sku\nFirst,DELEGATE-A\nSecond,DELEGATE-B\n");
        $before = $this->effectsFingerprint();
        $result = $this->narrowWhileBlocked($this->confirmation($session, $token), function () use ($delegation): void {
            $delegation->update(['permission_allowlist' => ['erp.read', 'erp.catalog.import.products.preview']]);
        });
        $this->assertSame(403, $result['status']);
        $this->assertSame(['erp.read', 'erp.catalog.import.products.preview'], $delegation->fresh()->permission_allowlist);
        $this->assertSame([0, 0], [$result['attribution_inserts'], $result['receipt_inserts']]);
        $this->assertPendingWithoutEffects();
        $this->assertSame($before, $this->effectsFingerprint());
    }

    private function existingProduct(): ErpProduct
    {
        return ErpProduct::factory()->create(['erp_company_id' => $this->company->id, 'erp_unit_id' => $this->unit->id, 'name' => 'Original persisted product', 'sku' => 'ORIGINAL']);
    }

    private function updateRequest(array $session, ErpProduct $product): array
    {
        return $session + ['method' => 'PATCH', 'path' => '/api/v1/erp/products/'.$product->id, 'body' => ['name' => 'Updated product']];
    }

    public function test_failure_after_actual_update_attribution_insert_restores_existing_product(): void
    {
        $product = $this->existingProduct();
        $session = $this->login($this->firstActor);
        $before = $this->effectsFingerprint();
        $result = $this->request($this->updateRequest($session, $product) + ['fail_after_attribution' => 1]);
        $this->assertSame(503, $result['status']);
        $this->assertSame(1, $result['failure_after_insert']);
        $this->assertSame([1, 0], [$result['attribution_inserts'], $result['receipt_inserts']]);
        $this->assertSame($before, $this->effectsFingerprint());
    }

    public function test_failure_after_actual_second_import_attribution_rolls_back_entire_unit(): void
    {
        $session = $this->login($this->firstActor);
        $token = $this->preview($session, "name,sku\nFirst,FAIL-A\nSecond,FAIL-B\n");
        $before = $this->effectsFingerprint();
        $result = $this->request($this->confirmation($session, $token) + ['fail_after_attribution' => 2]);
        $this->assertSame(503, $result['status']);
        $this->assertSame(2, $result['failure_after_insert']);
        $this->assertSame([2, 0], [$result['attribution_inserts'], $result['receipt_inserts']]);
        $this->assertPendingWithoutEffects();
        $this->assertSame($before, $this->effectsFingerprint());
    }

    private function replaceRealLockAtOuterFence(array $input, int $events, int $receipts, int $products, int $variants, int $completed): array
    {
        $ready = $this->temporaryPath();
        $resume = $this->temporaryPath();
        $worker = null;
        $successor = null;
        $original = null;
        try {
            $worker = $this->worker($input + ['pause_after_controller' => ['ready' => $ready, 'resume' => $resume, 'company_id' => $this->company->id]]);
            $deadline = microtime(true) + 8;
            do {
                $contents = $this->readPrivateTemporaryFile($ready);
                if ($contents !== '') {
                    break;
                }
                $this->assertTrue($worker->isRunning(), $worker->getErrorOutput());
                usleep(10000);
            } while (microtime(true) < $deadline);
            $this->assertNotSame('', $contents, 'An empty reserved file must not signal controller completion.');
            $facts = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($worker->getPid(), $facts['pid']);
            $this->assertSame('ResolveCompany post-next AccessSession::assertOwnership', $facts['fence']);
            $this->assertSame(1, $facts['transaction_level'], 'The catalog wrapper has returned; outer Company transaction remains open.');
            $this->assertSame(200, $facts['controller_status']);
            $this->assertSame($events, $facts['events']);
            $this->assertSame($receipts, $facts['receipts']);
            $this->assertSame($products, $facts['products']);
            $this->assertSame($variants, $facts['variants']);
            $this->assertSame($completed, $facts['completed']);
            $this->assertNotSame($this->effectsFingerprint()['erp_products']['sha256'], $facts['products_sha256'], 'Actual changed resource is visible only inside the open transaction.');
            $this->assertSame('Illuminate\\Cache\\PhpRedisLock', $facts['lock_class']);
            $this->assertMatchesRegularExpression('/^access-session:[a-f0-9]{64}$/', $facts['lock_name']);
            $original = Cache::restoreLock($facts['lock_name'], $facts['lock_owner']);
            $this->assertTrue($original->isOwnedByCurrentProcess());
            $this->assertTrue($original->release(), 'Owner-safe release of only the owned synthetic original lock.');
            $successor = Cache::lock($facts['lock_name'], 30);
            $this->assertTrue($successor->get());
            $this->assertTrue($successor->isOwnedByCurrentProcess());
            $this->assertFalse($original->isOwnedByCurrentProcess());
            $this->assertFalse($original->release(), 'Old owner cannot release the real successor.');
            $this->assertTrue($successor->isOwnedByCurrentProcess());
            $this->assertSame(0, $this->events()->count(), 'Effects visible inside the paused transaction must remain invisible outside.');
            $this->assertSame(0, DB::table('erp_operation_receipts')->where('erp_company_id', $this->company->id)->count());
            $this->privateTemporaryContents($resume, 'resume');
            $result = $this->response($worker);
            $this->assertSame(503, $result['status']);
            $this->assertTrue($successor->isOwnedByCurrentProcess(), 'Stale worker finally must not release the successor through rollback/termination.');
            $this->assertFalse($original->release());
            $this->assertTrue($successor->isOwnedByCurrentProcess());
            $this->assertSame($events, $result['attribution_inserts']);
            $this->assertSame($receipts, $result['receipt_inserts']);
            $this->assertSame($facts['fence'], $result['outer_fence']);
            fwrite(STDERR, 'Redis outer fence '.json_encode(array_diff_key($facts, array_flip(['lock_name', 'lock_owner'])), JSON_THROW_ON_ERROR)."; original_release=true; stale_release=false; successor_held_after_worker=true\n");

            return $result;
        } finally {
            if ($worker instanceof Process && $worker->isRunning()) {
                $worker->stop(1);
            }
            if ($successor !== null) {
                $this->assertTrue($successor->isOwnedByCurrentProcess());
                $this->assertTrue($successor->release());
                $this->assertFalse($successor->isOwnedByCurrentProcess());
            }
        }
    }

    public function test_real_redis_successor_survives_import_outer_fence_rollback_and_old_release(): void
    {
        $session = $this->login($this->firstActor);
        $token = $this->preview($session, "name,sku\nFirst,FENCE-A\nSecond,FENCE-B\n");
        $before = $this->effectsFingerprint();
        $this->replaceRealLockAtOuterFence($this->confirmation($session, $token), 4, 1, 2, 2, 1);
        $this->assertPendingWithoutEffects();
        $this->assertSame($before, $this->effectsFingerprint());
    }

    public function test_real_redis_successor_survives_existing_update_outer_fence_and_old_release(): void
    {
        $product = $this->existingProduct();
        $session = $this->login($this->firstActor);
        $before = $this->effectsFingerprint();
        $this->replaceRealLockAtOuterFence($this->updateRequest($session, $product), 1, 0, 1, 0, 0);
        $this->assertSame($before, $this->effectsFingerprint());
    }
}
