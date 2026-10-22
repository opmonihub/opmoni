<?php

use Illuminate\Cache\RedisLock;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

final class Task20AttributionPrivateFiles
{
    public int $checks = 0;

    public int $writes = 0;

    public function __construct(
        private ?string $directory,
        private array $directoryIdentity,
        private array $fileIdentities,
    ) {}

    private function assertIdentity(array|false $stat, array $identity, int $mode, int $type): void
    {
        if ($stat === false
            || array_intersect_key($stat, array_flip(['dev', 'ino', 'uid'])) !== $identity
            || ($stat['mode'] & 07777) !== $mode
            || ($stat['mode'] & 0170000) !== $type
            || $stat['uid'] !== $this->directoryIdentity['uid']) {
            throw new RuntimeException('Reserved temporary path must keep its private permissions, type and original identity.');
        }
        $this->checks++;
    }

    public function contents(string $path, ?string $contents = null): string
    {
        if (! isset($this->fileIdentities[$path]) || dirname($path) !== $this->directory) {
            throw new RuntimeException('Only exclusively reserved parent-owned temporary paths are allowed.');
        }
        clearstatcache(true, $this->directory);
        $this->assertIdentity(lstat($this->directory), $this->directoryIdentity, 0700, 0040000);
        clearstatcache(true, $path);
        $this->assertIdentity(lstat($path), $this->fileIdentities[$path], 0600, 0100000);
        $handle = fopen($path, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Reserved temporary file cannot be opened without recreating it.');
        }
        try {
            if (! flock($handle, $contents === null ? LOCK_SH : LOCK_EX)) {
                throw new RuntimeException('Reserved temporary file lock unavailable.');
            }
            $this->assertIdentity(fstat($handle), $this->fileIdentities[$path], 0600, 0100000);
            if ($contents !== null) {
                if (! ftruncate($handle, 0) || fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle)) {
                    throw new RuntimeException('Reserved temporary file write incomplete.');
                }
                $this->assertIdentity(fstat($handle), $this->fileIdentities[$path], 0600, 0100000);
                $this->writes++;

                return $contents;
            }
            $result = stream_get_contents($handle);
            if (! is_string($result)) {
                throw new RuntimeException('Reserved temporary file read incomplete.');
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

final class Task20AttributionOuterFence
{
    public function __construct(private array $barrier, private Task20AttributionPrivateFiles $privateFiles) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $companyId = $this->barrier['company_id'];
        abort_unless((int) $request->session()->get('current_company_id') === $companyId, 503);
        $lock = $request->attributes->get('access.session_lock');
        abort_unless($lock instanceof RedisLock && $lock->isOwnedByCurrentProcess(), 503);
        $name = 'access-session:'.hash('sha256', $request->session()->getId());
        abort_unless(Cache::restoreLock($name, $lock->owner())->isOwnedByCurrentProcess(), 503);
        $products = DB::table('erp_products')->where('erp_company_id', $companyId);
        $facts = [
            'fence' => 'ResolveCompany post-next AccessSession::assertOwnership',
            'pid' => getmypid(),
            'pg_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid,
            'transaction_level' => DB::transactionLevel(),
            'controller_status' => $response->getStatusCode(),
            'events' => DB::table('erp_catalog_attributions')->where('erp_company_id', $companyId)->count(),
            'receipts' => DB::table('erp_operation_receipts')->where('erp_company_id', $companyId)->count(),
            'products' => (clone $products)->count(),
            'products_sha256' => hash('sha256', (clone $products)->orderBy('id')->get()->toJson()),
            'variants' => DB::table('erp_product_variants')->whereIn('erp_product_id', (clone $products)->select('id'))->count(),
            'completed' => DB::table('erp_catalog_imports')->where('erp_company_id', $companyId)->whereNotNull('completed_at')->count(),
            'lock_class' => $lock::class,
            'lock_name' => $name,
            'lock_owner' => $lock->owner(),
        ];
        $request->attributes->set('task20.outer_fence', $facts['fence']);
        $this->privateFiles->contents($this->barrier['ready'], json_encode($facts, JSON_THROW_ON_ERROR));
        $deadline = microtime(true) + 12;
        while ($this->privateFiles->contents($this->barrier['resume']) !== 'resume') {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Owned outer-fence barrier timed out.');
            }
            usleep(10000);
        }

        return $response;
    }
}

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (
    getenv('ERP_IMPORT_ISOLATED') !== 'task6-20261020'
    || ! $app->environment('testing')
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'opmoni_test'
    || DB::selectOne('select current_database() as name')->name !== 'opmoni_test'
    || config('database.redis.default.database') !== '13'
    || config('database.redis.cache.database') !== '14'
    || config('database.redis.sessions.database') !== '15'
    || config('database.redis.options.prefix') !== 'opmoni-access-test-'
    || (string) Redis::connection('default')->client()->getDBNum() !== '13'
    || (string) Redis::connection('cache')->client()->getDBNum() !== '14'
    || (string) Redis::connection('sessions')->client()->getDBNum() !== '15'
) {
    throw new RuntimeException('Dedicated ERP catalog stores required.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
if (getenv('ERP_CATALOG_ATTRIBUTION_ISOLATED') !== 'task20-20261022') {
    throw new RuntimeException('Dedicated attribution process marker required.');
}
$privateFiles = new Task20AttributionPrivateFiles($input['private_directory'], $input['private_directory_identity'], $input['private_file_identities']);
config(['erp.operating_writes_enabled' => true, 'app.name' => 'Opmoni', 'session.cookie' => 'opmoni-session']);
$label = $input['label'] ?? 'erp-catalog-sku-'.getmypid();
DB::select("select set_config('application_name', ?, false)", [$label]);
$pgPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
$attributionInserts = $receiptInserts = $failureAfterInsert = 0;
DB::listen(function (QueryExecuted $query) use ($input, &$attributionInserts, &$receiptInserts, &$failureAfterInsert): void {
    if (preg_match('/^\s*insert into "erp_catalog_attributions"/i', $query->sql)) {
        $attributionInserts++;
        if (($input['fail_after_attribution'] ?? null) === $attributionInserts) {
            $failureAfterInsert = $attributionInserts;
            abort(503, 'Owned test failure after real attribution insertion.');
        }
    }
    if (preg_match('/^\s*insert into "erp_operation_receipts"/i', $query->sql)) {
        $receiptInserts++;
    }
});
if (isset($input['pause_after_controller'])) {
    $app->instance(Task20AttributionOuterFence::class, new Task20AttributionOuterFence($input['pause_after_controller'], $privateFiles));
    $app['events']->listen(RouteMatched::class, function (RouteMatched $event): void {
        $event->route->middleware(Task20AttributionOuterFence::class);
    });
}
if (isset($input['ready'])) {
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($input, $label, $pgPid, $privateFiles): void {
        if (str_contains($query, 'erp_companies') && str_contains($query, 'for update')) {
            if ((int) ($bindings[0] ?? 0) !== $input['company_id']) {
                throw new RuntimeException('Company barrier cannot target an unowned Company.');
            }
            $privateFiles->contents($input['ready'], json_encode(['pid' => getmypid(), 'pg_pid' => $pgPid, 'application_name' => $label, 'barrier' => 'company_for_update', 'company_id' => $input['company_id']], JSON_THROW_ON_ERROR));
        }
    });
}
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key', 'HTTP_X_OPMONI_CLIENT_IP' => $input['client_ip'], 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http'];
foreach ($input['headers'] ?? [] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$files = [];
$csvPath = null;
try {
    if (isset($input['csv'])) {
        $csvPath = $input['csv_path'];
        $privateFiles->contents($csvPath, $input['csv']);
        $files['file'] = new UploadedFile($csvPath, 'fixture.csv', 'text/csv', null, true);
        $server['CONTENT_TYPE'] = 'multipart/form-data';
    }
    $request = Request::create('http://localhost:3000'.$input['path'], $input['method'] ?? 'GET', [], $input['cookies'] ?? [], $files, $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $cookies = [];
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }
    $kernel->terminate($request, $response);
    echo json_encode([
        'pid' => getmypid(), 'pg_pid' => $pgPid, 'application_name' => $label,
        'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true),
        'cookies' => $cookies, 'transaction_level' => DB::transactionLevel(),
        'attribution_inserts' => $attributionInserts, 'receipt_inserts' => $receiptInserts,
        'failure_after_insert' => $failureAfterInsert,
        'outer_fence' => $request->attributes->get('task20.outer_fence'),
        'private_file_checks' => $privateFiles->checks, 'private_file_writes' => $privateFiles->writes,
        'temporary_umask' => sprintf('%04o', umask()),
    ], JSON_THROW_ON_ERROR);
} finally {
    DB::disconnect();
}
