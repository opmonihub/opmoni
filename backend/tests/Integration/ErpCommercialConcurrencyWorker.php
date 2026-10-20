<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

if (getenv('ERP_COMMERCIAL_ISOLATED') !== 'task9-20261020') {
    throw new RuntimeException('Dedicated commercial marker required before bootstrap.');
}
require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'opmoni_test'
    || DB::selectOne('select current_database() as name')->name !== 'opmoni_test'
    || config('database.redis.options.prefix') !== 'opmoni-access-test-'
    || ! str_starts_with(DB::selectOne('show server_version')->server_version, '18.')
    || ! str_starts_with(Redis::connection('cache')->info('server')['redis_version'], '8.')) {
    throw new RuntimeException('Dedicated commercial stores and versions required.');
}
foreach (['default' => '13', 'cache' => '14', 'sessions' => '15'] as $connection => $database) {
    if ((string) config('database.redis.'.$connection.'.database') !== $database || (string) Redis::connection($connection)->client()->getDBNum() !== $database) {
        throw new RuntimeException('Dedicated Redis database required.');
    }
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$path = $input['path'] ?? '';
$method = $input['method'] ?? 'GET';
if (! is_string($path) || ! preg_match('#^/api/v1/(csrf|login|erp/(sales|quotes|orders|sale-returns|purchase-returns|purchases|cash-registers|cash-sessions)(/([1-9][0-9]*|xml-preview|xml-confirm))?(/(post|convert|close))?)$#D', $path)
    || ! in_array($method, ['GET', 'POST'], true)
    || ! is_array($input['body'] ?? []) || ! is_array($input['headers'] ?? []) || ! is_array($input['cookies'] ?? [])) {
    throw new RuntimeException('Unsupported commercial worker request.');
}
if (! is_string($input['client_ip'] ?? null) || ! filter_var($input['client_ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! str_starts_with($input['client_ip'], '10.89.')) {
    throw new RuntimeException('Dedicated synthetic client IP required.');
}
config(['erp.operating_writes_enabled' => true, 'app.name' => 'Opmoni', 'session.cookie' => 'opmoni-session']);
$label = $input['label'] ?? 'erp-commercial-'.getmypid();
if (! is_string($label) || ! preg_match('/^erp-commercial-[a-z0-9-]{1,40}$/D', $label)) {
    throw new RuntimeException('Invalid worker label.');
}
DB::select("select set_config('application_name', ?, false)", [$label]);
$backendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
if (isset($input['ready'])) {
    $ready = $input['ready'];
    if (! is_string($ready) || dirname($ready) !== sys_get_temp_dir() || ! str_starts_with(basename($ready), 'erp-commercial-') || file_exists($ready)) {
        throw new RuntimeException('Invalid ready marker.');
    }
    DB::connection()->beforeExecuting(function (string $query) use ($ready, $backendPid): void {
        if (str_contains($query, 'erp_companies') && str_contains($query, 'for update')) {
            file_put_contents($ready, (string) $backendPid);
        }
    });
}
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key', 'HTTP_X_OPMONI_CLIENT_IP' => $input['client_ip'], 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http'];
foreach ($input['headers'] ?? [] as $name => $value) {
    if (! in_array($name, ['X-CSRF-Token', 'X-Company-Id', 'Idempotency-Key'], true) || ! is_string($value)) {
        throw new RuntimeException('Unsupported worker header.');
    }
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create('http://localhost:3000'.$path, $method, [], $input['cookies'] ?? [], [], $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
echo json_encode(['pid' => getmypid(), 'backend_pid' => $backendPid, 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'cookies' => $cookies, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
DB::disconnect();
