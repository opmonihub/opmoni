<?php

use App\Erp\Accounting\ErpAccountingDispatchOutbox;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (
    getenv('ERP_ACCOUNTING_OUTBOX_ISOLATED') !== 'task16-20261021'
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
    throw new RuntimeException('Dedicated retained Task16 PostgreSQL and Redis stores are required.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
config([
    'app.name' => 'Opmoni',
    'queue.default' => 'database',
    'queue.connections.database.queue' => $input['queue'],
    'filesystems.disks.local.root' => $input['storageRoot'] ?? storage_path('app/private'),
    'filesystems.disks.fiscal.root' => $input['fiscalRoot'] ?? (($input['storageRoot'] ?? storage_path('app/private')).'/fiscal'),
]);
$label = $input['label'];
DB::select('select set_config(\'application_name\', ?, false)', [$label]);
$identity = DB::selectOne('select pg_backend_pid() as pid, current_database() as database');
DB::connection()->beforeExecuting(function (string $query) use ($input, $identity): void {
    $target = ($input['mode'] ?? 'publish') === 'work' ? 'erp_companies' : 'erp_accounting_delivery_dispatches';
    if (str_contains($query, $target) && str_contains(strtolower($query), 'for update')) {
        file_put_contents($input['ready'], json_encode([
            'pid' => getmypid(),
            'pg_pid' => $identity->pid,
            'database' => $identity->database,
        ], JSON_THROW_ON_ERROR));
    }
});
$queued = ($input['mode'] ?? 'publish') === 'work'
    ? Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => $input['queue'],
        '--once' => true,
        '--sleep' => 0,
        '--timeout' => 30,
        '--tries' => 10,
    ]) === 0
    : app(ErpAccountingDispatchOutbox::class)->publish((int) $input['dispatchId']);
echo json_encode([
    'pid' => getmypid(),
    'pg_pid' => $identity->pid,
    'database' => $identity->database,
    'transaction_level' => DB::transactionLevel(),
    'queued' => $queued,
    'console_output' => ($input['mode'] ?? 'publish') === 'work' ? Artisan::output() : null,
], JSON_THROW_ON_ERROR);
DB::disconnect();
