<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
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
if (isset($input['pause'])) {
    $phase = $input['pause']['phase'] ?? null;
    $reportPause = $phase === 'report-before-payments' && $input['path'] === '/api/v1/erp/reports/financial' && ($input['method'] ?? 'GET') === 'GET';
    $reversalPause = $phase === 'reversal-before-payment-insert' && preg_match('#^/api/v1/erp/payments/[1-9][0-9]*/reverse$#D', $input['path']) && ($input['method'] ?? 'GET') === 'POST';
    if (! $reportPause && ! $reversalPause) {
        throw new RuntimeException('Unsupported finance test-worker pause.');
    }
    $observed = ['locked_company_ids' => [], 'completed_obligation_reads' => 0, 'completed_allocation_compensations' => 0, 'completed_obligation_updates' => 0];
    DB::listen(function (QueryExecuted $event) use (&$observed): void {
        if (str_contains($event->sql, 'from "erp_companies"') && str_contains($event->sql, 'for update')) {
            $observed['locked_company_ids'] = array_values(array_unique([...$observed['locked_company_ids'], ...array_map(intval(...), $event->bindings)]));
        }
        if (str_starts_with($event->sql, 'select ') && str_contains($event->sql, 'from "erp_obligations"')) {
            $observed['completed_obligation_reads']++;
        }
        if (str_starts_with($event->sql, 'insert into "erp_payment_allocations"')) {
            $observed['completed_allocation_compensations']++;
        }
        if (str_starts_with($event->sql, 'update "erp_obligations"')) {
            $observed['completed_obligation_updates']++;
        }
    });
    $didPause = false;
    DB::connection()->beforeExecuting(function (string $query) use ($input, $databaseIdentity, $label, $phase, $reportPause, &$observed, &$didPause): void {
        $atPause = $reportPause
            ? str_contains($query, '"source_type", "source_id", "reversal_of_id", "posted_at" from "erp_payments"')
            : str_starts_with($query, 'insert into "erp_payments"');
        if ($didPause || ! $atPause) {
            return;
        }
        if (DB::transactionLevel() < ($reportPause ? 1 : 2) || $observed['locked_company_ids'] === [] || ($reportPause && $observed['completed_obligation_reads'] !== 2)) {
            throw new RuntimeException('Finance worker pause was not inside the expected Company-fenced transaction.');
        }
        $didPause = true;
        $identity = ['pid' => getmypid(), 'pg_pid' => $databaseIdentity->pid, 'database' => $databaseIdentity->database, 'label' => $label,
            'phase' => $phase, 'transaction_level' => DB::transactionLevel(), 'query' => $query] + $observed;
        $paused = $input['pause']['paused'];
        file_put_contents($paused.'.tmp', json_encode($identity, JSON_THROW_ON_ERROR));
        rename($paused.'.tmp', $paused);
        $deadline = hrtime(true) + 8_000_000_000;
        do {
            clearstatcache(true, $input['pause']['release']);
            if (file_exists($input['pause']['release'])) {
                return;
            }
            usleep(5000);
        } while (hrtime(true) < $deadline);

        throw new RuntimeException('Bounded finance test-worker pause expired without release.');
    });
}
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
