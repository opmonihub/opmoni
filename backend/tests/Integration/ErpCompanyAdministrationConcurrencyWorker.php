<?php

use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (getenv('COMPANY_ADMIN_ISOLATED') !== 'task2-20261018' || config('database.connections.pgsql.database') !== 'opmoni_test') {
    throw new RuntimeException('Dedicated company administration stores required.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['erp.operating_writes_enabled' => true, 'app.name' => 'Opmoni', 'session.cookie' => 'opmoni-session']);
$label = $input['label'] ?? 'company-admin-'.getmypid();
DB::select("select set_config('application_name', ?, false)", [$label]);
$backendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
if (isset($input['ready'])) {
    DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($input, $backendPid): void {
        if (str_contains($query, 'erp_companies') && str_contains($query, 'for update')) {
            if ($input['ready_facts'] ?? false) {
                if ((int) ($bindings[0] ?? 0) !== (int) ($input['headers']['X-Company-Id'] ?? 0)) {
                    throw new RuntimeException('Company barrier cannot target a different company.');
                }
                $contents = json_encode([
                    'pid' => getmypid(), 'backend_pid' => $backendPid, 'label' => $input['label'],
                    'company_id' => (int) $input['headers']['X-Company-Id'], 'barrier' => 'company_for_update',
                ], JSON_THROW_ON_ERROR);
            } else {
                $contents = (string) getmypid();
            }
            file_put_contents($input['ready'], $contents);
        }
    });
}
if (isset($input['preboot_ready'], $input['preboot_release'])) {
    $prebootReady = $input['preboot_ready'];
    $prebootRelease = $input['preboot_release'];
    foreach ([$prebootReady, $prebootRelease] as $path) {
        if (! is_string($path) || dirname($path) !== sys_get_temp_dir()
            || ! str_starts_with(basename($path), 'company-admin-')) {
            throw new RuntimeException('Invalid application-bootstrap barrier.');
        }
    }
    if (file_exists($prebootReady) || file_exists($prebootRelease)) {
        throw new RuntimeException('Application-bootstrap barrier must be exclusively reserved.');
    }
    file_put_contents($prebootReady, json_encode([
        'pid' => getmypid(), 'backend_pid' => $backendPid, 'label' => $label,
    ], JSON_THROW_ON_ERROR));
    $deadline = microtime(true) + 20;
    while (! file_exists($prebootRelease)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Application-bootstrap barrier timed out.');
        }
        usleep(10000);
    }
}
if (isset($input['fault'])) {
    $model = ($input['model'] ?? 'member') === 'establishment' ? ErpEstablishment::class : ErpCompanyMembership::class;
    $event = $input['event'] ?? 'created';
    $model::$event(function () use ($input): void {
        if ($input['fault'] === 'lease') {
            request()->attributes->get('access.session_lock')->forceRelease();
        } else {
            throw ValidationException::withMessages(['role' => 'Injected transaction failure']);
        }
    });
}
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key', 'HTTP_X_OPMONI_CLIENT_IP' => '127.0.0.1', 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http'];
foreach ($input['headers'] ?? [] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create('http://localhost:3000'.$input['path'], $input['method'] ?? 'GET', [], $input['cookies'] ?? [], [], $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'cookies' => $cookies, 'cache_control' => $response->headers->get('Cache-Control'), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
DB::disconnect();
