<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpLicenceAllocator;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\User;
use App\Support\CurrentAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OfficeErpCommercialConcurrencyWorker
{
    /** @param array<string, mixed> $input */
    public static function run(array $input): void
    {
        $identity = OfficeErpCommercialProcessTestCase::assertIsolatedStores();
        if (($input['source_hashes'] ?? null) !== $identity['source_hashes']) {
            throw new RuntimeException('Parent and worker commercial race sources differ.');
        }
        Http::preventStrayRequests();
        $base = $input['base'];
        self::signal($base.'.ready', $identity);

        $request = Request::create('http://localhost:3000');
        app()->instance('request', $request);
        $account = Account::query()->findOrFail($input['account_id']);
        $actor = User::query()->findOrFail($input['actor_id']);
        $membership = AccountMembership::query()
            ->whereKey($input['membership_id'])
            ->where('account_id', $input['account_id'])
            ->where('user_id', $input['actor_id'])
            ->where('role', 'admin')
            ->firstOrFail();
        $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));
        $context = OfficeErpExecutionContext::officeAdmin($account, $actor, 'commercial-race-worker:'.$input['contract_id']);

        if ($input['action'] !== 'reserve') {
            throw new RuntimeException('Unsupported action in the accepted allocation subset: '.$input['action']);
        }

        if ($input['hold_account_lock'] ?? false) {
            $paused = false;
            DB::listen(function (QueryExecuted $query) use ($base, &$paused): void {
                $sql = strtolower($query->sql);
                if ($paused || ! str_contains($sql, 'from "accounts"') || ! str_contains($sql, 'for update')) {
                    return;
                }
                if (DB::transactionLevel() < 1) {
                    throw new RuntimeException('Account lock barrier must remain inside the allocator transaction.');
                }
                $paused = true;
                self::signal($base.'.held', [
                    'database_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid,
                    'transaction_level' => DB::transactionLevel(),
                    'lock_query' => $query->sql,
                ]);
                self::await($base.'.release');
            });
        }

        try {
            $contract = OfficeErpRecords::query($context, OfficeErpContract::class)
                ->whereKey($input['contract_id'])
                ->firstOrFail();
            $allocation = $context->run(fn () => app(OfficeErpLicenceAllocator::class)->reserve(
                $context,
                $contract,
                CarbonImmutable::parse($input['expires_at']),
            ));
            $result = ['status' => 201, 'allocation_id' => (int) $allocation->id];
        } catch (HttpExceptionInterface $exception) {
            $result = ['status' => $exception->getStatusCode()];
        }

        echo json_encode([
            ...$identity,
            ...$result,
            'transaction_level' => DB::transactionLevel(),
        ], JSON_THROW_ON_ERROR);
        DB::disconnect();
    }

    /** @param array<string, mixed> $payload */
    private static function signal(string $path, array $payload): void
    {
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR), LOCK_EX) === false
            || ! rename($temporary, $path)) {
            throw new RuntimeException('Unable to publish commercial race worker signal.');
        }
        chmod($path, 0600);
    }

    private static function await(string $path): void
    {
        $deadline = hrtime(true) + 15_000_000_000;
        do {
            clearstatcache(true, $path);
            if (file_exists($path)) {
                return;
            }
            usleep(5000);
        } while (hrtime(true) < $deadline);

        throw new RuntimeException('Commercial race worker barrier expired without release.');
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    require __DIR__.'/bootstrap.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    OfficeErpCommercialConcurrencyWorker::run($input);
}
