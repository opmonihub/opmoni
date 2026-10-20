<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (
    getenv('ERP_FINANCE_ISOLATED') !== 'task8-20261020'
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
    throw new RuntimeException('Dedicated retained ERP finance stores required.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['erp.operating_writes_enabled' => true, 'app.name' => 'Opmoni', 'session.cookie' => 'opmoni-session']);
$label = $input['label'] ?? 'erp-finance-'.getmypid();
DB::select("select set_config('application_name', ?, false)", [$label]);
$databaseIdentity = DB::selectOne('select pg_backend_pid() as pid, current_database() as database');
if (isset($input['ready'])) {
    DB::connection()->beforeExecuting(function (string $query) use ($input, $databaseIdentity): void {
        if (str_contains($query, 'erp_companies') && str_contains($query, 'for update')) {
            file_put_contents($input['ready'], json_encode(['pid' => getmypid(), 'pg_pid' => $databaseIdentity->pid, 'database' => $databaseIdentity->database], JSON_THROW_ON_ERROR));
        }
    });
}
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key', 'HTTP_X_OPMONI_CLIENT_IP' => $input['client_ip'] ?? '127.0.0.1', 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http'];
foreach ($input['headers'] ?? [] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create('http://localhost:3000'.$input['path'], $input['method'] ?? 'GET', [], $input['cookies'] ?? [], [], $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
echo json_encode(['pid' => getmypid(), 'pg_pid' => $databaseIdentity->pid, 'database' => $databaseIdentity->database, 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'cookies' => $cookies, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
DB::disconnect();
