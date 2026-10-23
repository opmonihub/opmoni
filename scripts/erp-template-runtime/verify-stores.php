<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

// Read-only runtime assertions; do not migrate, seed, flush, or expose secrets.
require '/workspace/backend/vendor/autoload.php';
$app = require '/workspace/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$check = static function (bool $condition, string $description): void {
    if (! $condition) {
        throw new RuntimeException($description);
    }
};
$check(config('database.default') === 'pgsql', 'Expected PostgreSQL');
$check(config('database.connections.pgsql.host') === 'postgres', 'Unexpected PostgreSQL host');
$check(config('database.connections.pgsql.database') === 'opmoni_test', 'Unsafe database');
$database = DB::selectOne('select current_database() as name, current_setting(\'server_version_num\')::integer as version');
$check($database->name === 'opmoni_test' && $database->version >= 180000 && $database->version < 190000, 'Expected PostgreSQL 18 test database');
foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $number) {
    $check(config('database.redis.'.$connection.'.host') === 'redis', 'Unexpected Redis host');
    $client = Redis::connection($connection)->client();
    $check($client->getDBNum() === $number, 'Unsafe Redis database');
    $check($client->getOption(\Redis::OPT_PREFIX) === 'opmoni-erp-template-', 'Unexpected Redis prefix');
    $check(str_starts_with($client->info('server')['redis_version'], '8.'), 'Expected Redis 8');
}
foreach (['registration_enabled', 'operating_writes_enabled', 'release_enabled', 'fiscal_production_enabled'] as $gate) {
    $check(config('erp.'.$gate) === false, 'ERP gate must remain disabled');
}
$check(config('erp.fiscal_provider') === 'fake', 'External ERP fiscal provider forbidden');
$check(config('whatsapp.enabled') === false, 'External WhatsApp dispatch forbidden');
foreach (['cte_enabled', 'nfse_enabled', 'manifestacao_enabled'] as $gate) {
    $check(config('fiscal.'.$gate) === false, 'Office fiscal gate must remain disabled');
}
$check(config('fiscal.manifestacao_rollout_mode') === 'disabled', 'Fiscal manifestation rollout forbidden');
echo "PASS: PostgreSQL 18 opmoni_test, Redis 8 databases 13/14/15, disabled ERP gates and external dispatch\n";
