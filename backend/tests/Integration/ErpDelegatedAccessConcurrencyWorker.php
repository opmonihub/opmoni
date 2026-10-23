<?php

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpBrand;
use App\Support\ErpCompanyContext;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (getenv('DELEGATED_ACCESS_ISOLATED') !== 'task21-20261018' || config('database.connections.pgsql.database') !== 'opmoni_test') {
    throw new RuntimeException('Dedicated delegated access stores required.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config(['erp.operating_writes_enabled' => true, 'app.name' => 'Opmoni', 'session.cookie' => 'opmoni-session']);
DB::select("select set_config('application_name', ?, false)", [$input['label'] ?? 'delegated-access-'.getmypid()]);
if (($input['mode'] ?? null) === 'demote') {
    DB::transaction(function () use ($input): void {
        file_put_contents($input['ready'], (string) getmypid());
        Account::query()->whereKey($input['account_id'])->lockForUpdate()->firstOrFail();
        AccountMembership::query()->whereKey($input['membership_id'])->update(['role' => 'user']);
    });
    echo json_encode(['pid' => getmypid(), 'status' => 200, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit;
}
if (isset($input['before_effect'])) {
    ErpBrand::creating(function () use ($input): void {
        file_put_contents($input['before_effect'], (string) getmypid());
        $deadline = microtime(true) + 12;
        while (! file_exists($input['release'])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Delegated effect barrier timed out.');
            }
            usleep(10000);
        }
    });
}
if ($input['lose_lease'] ?? false) {
    ErpBrand::created(fn () => request()->attributes->get('access.session_lock')->forceRelease());
}
$server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost:3000', 'HTTP_X_OPMONI_INGRESS_KEY' => 'integration-test-only-key', 'HTTP_X_OPMONI_CLIENT_IP' => '127.0.0.1', 'HTTP_X_OPMONI_CLIENT_PROTO' => 'http'];
foreach ($input['headers'] ?? [] as $name => $value) {
    $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
}
$request = Request::create('http://localhost:3000'.$input['path'], $input['method'] ?? 'GET', [], $input['cookies'] ?? [], [], $server, json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
$fenceQuery = null;
$fenceAccountId = null;
DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$fenceQuery, &$fenceAccountId): void {
    if (str_contains($query, '"accounts"') && str_contains($query, 'FOR UPDATE NOWAIT')) {
        $fenceQuery = $query;
        $fenceAccountId = (int) $bindings[0];
    }
});
$started = microtime(true);
$response = $kernel->handle($request);
$cookies = [];
foreach ($response->headers->getCookies() as $cookie) {
    $cookies[$cookie->getName()] = $cookie->getValue();
}
echo json_encode(['pid' => getmypid(), 'status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'cookies' => $cookies, 'retry_after' => $response->headers->get('Retry-After'), 'seconds' => microtime(true) - $started, 'fence_query' => $fenceQuery, 'fence_account_id' => $fenceAccountId, 'transaction_level' => DB::transactionLevel(), 'rollback_probe' => (int) DB::selectOne('select 1 as ready')->ready, 'company_context' => app(ErpCompanyContext::class)->companyId, 'tenant_context' => app(CurrentTenant::class)->accountId], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
DB::disconnect();
