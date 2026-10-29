<?php

use App\Erp\Commercial\OfficeErpAccessDeliveries;
use App\Erp\Commercial\OfficeErpAccessDeliveryTransport;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpMemberAuthority;
use App\Jobs\DeliverOfficeErpAccessDelivery;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing')
    || app()->configurationIsCached()
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.host') !== 'postgres'
    || config('database.connections.pgsql.database') !== 'opmoni_test'
    || config('database.redis.options.prefix') !== 'opmoni-access-test-'
    || (int) config('database.redis.default.database') !== 13
    || (int) config('database.redis.cache.database') !== 14
    || (int) config('database.redis.sessions.database') !== 15) {
    throw new RuntimeException('Access delivery worker requires guarded private PostgreSQL and Redis stores.');
}
$database = DB::selectOne("select current_database() as name, current_setting('server_version_num')::integer as version");
if ($database->name !== 'opmoni_test' || $database->version < 180000 || $database->version >= 190000) {
    throw new RuntimeException('Access delivery worker requires PostgreSQL 18 opmoni_test.');
}
foreach (['default' => 13, 'cache' => 14, 'sessions' => 15] as $connection => $number) {
    $client = Redis::connection($connection)->client();
    if ((int) $client->getDBNum() !== $number || $client->getOption(\Redis::OPT_PREFIX) !== 'opmoni-access-test-') {
        throw new RuntimeException('Access delivery worker rejected an unsafe Redis connection.');
    }
}

$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$base = $input['base'] ?? null;
if (! is_string($base) || preg_match('/\A\/tmp\/opmoni-access-delivery-[a-f0-9]+\/worker-[a-f0-9]+\z/', $base) !== 1) {
    throw new RuntimeException('Invalid private worker IPC path.');
}

DB::select("select set_config('application_name', ?, false)", ['access-delivery-'.getmypid()]);
$request = Request::create('http://localhost:3000');
app()->instance('request', $request);

if (($input['action'] ?? null) === 'deliver') {
    app()->instance(OfficeErpAccessDeliveryTransport::class, new class($base, ($input['uncertain'] ?? false) === true) implements OfficeErpAccessDeliveryTransport
    {
        public function __construct(private readonly string $base, private readonly bool $uncertain) {}

        public function assertAvailable(): void {}

        public function send(string $recipient, string $subject, string $url): void
        {
            $countPath = $this->base.'.send-count';
            $counter = fopen($countPath, 'c+');
            if (! is_resource($counter) || ! flock($counter, LOCK_EX)) {
                throw new RuntimeException('Unable to update private send counter.');
            }
            $count = (int) stream_get_contents($counter);
            rewind($counter);
            ftruncate($counter, 0);
            fwrite($counter, (string) ($count + 1));
            fflush($counter);
            flock($counter, LOCK_UN);
            fclose($counter);
            file_put_contents($this->base.'.sending', (string) DB::selectOne('select pg_backend_pid() as pid')->pid, LOCK_EX);

            if ($this->uncertain) {
                throw new RuntimeException('Controlled uncertain transport outcome.');
            }

            await($this->base.'.release-send');
        }
    });

    (new DeliverOfficeErpAccessDelivery((int) $input['delivery_id']))->handle(app(OfficeErpAccessDeliveries::class));
    result($base, ['ok' => true]);
    exit;
}

if (in_array($input['action'] ?? null, ['revoke_membership', 'revoke_issuer'], true)) {
    $account = Account::query()->findOrFail((int) $input['account_id']);
    $actor = User::query()->findOrFail((int) $input['actor_id']);
    $adminMembership = AccountMembership::query()->whereKey((int) $input['admin_membership_id'])
        ->where('account_id', $account->id)->where('user_id', $actor->id)->firstOrFail();
    $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $adminMembership));
    $ctx = OfficeErpExecutionContext::officeAdmin($account, $actor, 'delivery-revocation-worker');

    DB::transaction(function () use ($input, $base, $ctx): void {
        app(OfficeErpMemberAuthority::class)->resolve($ctx, (int) $input['company_id'], (int) $input['membership_id'], true);
        file_put_contents($base.'.authority-held', (string) DB::selectOne('select pg_backend_pid() as pid')->pid, LOCK_EX);
        await($base.'.release-revocation');

        if ($input['action'] === 'revoke_membership') {
            app(OfficeErpAccessDeliveries::class)->invalidateRecoveryForMembership($ctx, (int) $input['membership_id']);
            ErpCompanyMembership::query()->whereKey((int) $input['membership_id'])->delete();
        } else {
            AccountMembership::query()->whereKey((int) $input['admin_membership_id'])->update(['role' => 'operador']);
        }
    }, 3);
    result($base, ['ok' => true]);
    exit;
}

throw new RuntimeException('Unsupported access delivery worker action.');
function await(string $path): void
{
    $deadline = microtime(true) + 20;
    while (! file_exists($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Private access delivery worker barrier timed out.');
        }
        usleep(10000);
    }
}

function result(string $base, array $value): void
{
    $value['database_pid'] = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
    $value['transaction_level'] = DB::transactionLevel();
    file_put_contents($base.'.result', json_encode($value, JSON_THROW_ON_ERROR), LOCK_EX);
}
