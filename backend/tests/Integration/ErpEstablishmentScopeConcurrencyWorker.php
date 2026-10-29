<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (getenv('ERP_ESTABLISHMENT_SCOPE_ISOLATED') !== 'task22-20261018'
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'opmoni_test'
    || config('database.redis.cache.database') !== '14'
    || config('database.redis.sessions.database') !== '15') {
    throw new RuntimeException('Dedicated Task22 PostgreSQL and Redis stores required.');
}

config([
    'erp.operating_writes_enabled' => true,
    'erp.release_enabled' => true,
    'app.name' => 'Opmoni',
    'session.cookie' => 'opmoni-session',
]);

DB::select("select set_config('application_name', ?, false)", [$input['label'] ?? 'erp-scope-'.getmypid()]);
if (isset($input['storage_root'])) {
    config(['filesystems.disks.local.root' => $input['storage_root']]);
}
if (isset($input['ready'])) {
    DB::connection()->beforeExecuting(function (string $query) use ($input): void {
        if (str_contains(strtolower($query), 'erp_companies') && str_contains(strtolower($query), 'for update')) {
            file_put_contents($input['ready'], (string) getmypid());
        }
    });
}
if (isset($input['preboot_ready'], $input['preboot_release'])) {
    file_put_contents($input['preboot_ready'], json_encode([
        'pid' => getmypid(),
        'backend_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid,
        'label' => $input['label'] ?? 'erp-scope-'.getmypid(),
    ], JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 20;
    while (! file_exists($input['preboot_release'])) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Application-bootstrap barrier timed out.');
        }
        usleep(10000);
    }
}

$injectedFailure = false;
if (isset($input['fault'])) {
    DB::listen(function (QueryExecuted $query) use ($input, &$injectedFailure): void {
        $sql = strtolower(ltrim($query->sql));
        if ($injectedFailure || ! str_starts_with($sql, 'insert') || ! str_contains($sql, 'erp_company_membership_establishments')) {
            return;
        }
        if ($input['fault'] === 'scope-pivot-validation') {
            $injectedFailure = true;
            throw ValidationException::withMessages(['establishment_ids' => 'Injected Task22 pivot insert failure']);
        }
        if ($input['fault'] === 'scope-pivot-lease-loss') {
            $injectedFailure = true;
            request()->attributes->get('access.session_lock')->forceRelease();
        }
    });
}

$server = [
    'HTTP_ACCEPT' => 'application/json',
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ORIGIN' => 'http://localhost:3000',
    'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key',
    'HTTP_X_OPMONI_CLIENT_IP' => '127.0.0.1',
    'HTTP_X_OPMONI_CLIENT_PROTO' => 'http',
];
foreach ($input['headers'] ?? [] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create(
    'http://localhost:3000'.$input['path'],
    $input['method'] ?? 'GET',
    [],
    $input['cookies'] ?? [],
    [],
    $server,
    json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR),
);
$exceptionTypes = [];
if ($input['diagnose_errors'] ?? false) {
    $app->make(ExceptionHandler::class)->reportable(function (Throwable $error) use (&$exceptionTypes): void {
        $exceptionTypes[] = $error::class;
    });
}
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
echo json_encode([
    'pid' => getmypid(),
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true),
    'cookies' => $cookies,
    'exception_types' => $exceptionTypes,
    'injected_failure' => $injectedFailure,
    'transaction_level' => DB::transactionLevel(),
], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
DB::disconnect();
