<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

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
config(['erp.operating_writes_enabled' => true, 'app.name' => 'Opmoni', 'session.cookie' => 'opmoni-session']);
$label = $input['label'] ?? 'erp-catalog-sku-'.getmypid();
DB::select("select set_config('application_name', ?, false)", [$label]);
if (isset($input['ready'])) {
    DB::connection()->beforeExecuting(function (string $query) use ($input): void {
        if (str_contains($query, 'erp_companies') && str_contains($query, 'for update')) {
            file_put_contents($input['ready'], (string) getmypid());
        }
    });
}
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key', 'HTTP_X_OPMONI_CLIENT_IP' => '127.0.0.1', 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http'];
foreach ($input['headers'] ?? [] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$files = [];
$csvPath = null;
if (isset($input['csv'])) {
    $csvPath = tempnam(sys_get_temp_dir(), 'erp-import-worker-');
    file_put_contents($csvPath, $input['csv']);
    $files['file'] = new UploadedFile($csvPath, 'fixture.csv', 'text/csv', null, true);
    $server['CONTENT_TYPE'] = 'multipart/form-data';
}
$request = Request::create('http://localhost:3000'.$input['path'], $input['method'] ?? 'GET', [], $input['cookies'] ?? [], $files, $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'cookies' => $cookies, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
if ($csvPath !== null) {
    unlink($csvPath);
}
DB::disconnect();
