<?php

namespace Tests\Integration;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpMemberCredentials;
use App\Erp\Commercial\OfficeErpPasswordRecovery;
use App\Http\Controllers\Api\V1\Tenant\AccountMemberController;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Support\AccessRecoveryBudget;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

require __DIR__.'/bootstrap.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$storeIdentity = OfficeErpCommercialProcessTestCase::assertIsolatedStores();
$actualSources = [];
foreach (array_keys($input['source_hashes'] ?? []) as $source) {
    $actualSources[$source] = hash_file('sha256', base_path($source));
}
if ($actualSources !== ($input['source_hashes'] ?? null)) {
    throw new \RuntimeException('Credential concurrency source identity changed between parent and worker.');
}

$request = Request::create(
    'http://localhost:3000/api/v1/account/members/'.((int) ($input['target_user_id'] ?? 0)),
    ($input['action'] ?? null) === 'promote' ? 'PATCH' : 'POST',
    ($input['action'] ?? null) === 'promote' ? ['role' => 'admin'] : [],
    server: ['REMOTE_ADDR' => (string) ($input['ip'] ?? '192.0.2.250')],
);
app()->instance('request', $request);
$recovery = null;
$initialRecoveryState = null;
$initialCurrentState = null;
if (($input['action'] ?? null) === 'public_recovery') {
    $session = app('session')->driver();
    $session->setId(Str::random(40));
    $session->start();
    $request->setLaravelSession($session);
    $recovery = app(OfficeErpPasswordRecovery::class);
    $initialRecoveryState = $recovery->exchange($request, (string) $input['token'], (int) $input['delivery_id'])['state'];
    $initialCurrentState = $recovery->current($request)['state'];
}
$identity = ['pid' => getmypid(), 'database_pid' => (int) DB::selectOne('select pg_backend_pid() as pid')->pid];
$readyPayload = json_encode([
    ...$identity,
    'initial_recovery_state' => $initialRecoveryState,
    'initial_current_state' => $initialCurrentState,
    'source_hashes' => $storeIdentity['source_hashes'],
], JSON_THROW_ON_ERROR);
$readyTemporary = $input['ready'].'.'.getmypid().'.tmp';
if (file_put_contents($readyTemporary, $readyPayload, LOCK_EX) === false || ! rename($readyTemporary, $input['ready'])) {
    throw new \RuntimeException('Unable to publish credential worker readiness atomically.');
}
$deadline = microtime(true) + 20;
while (! file_exists($input['release'])) {
    if (microtime(true) >= $deadline) {
        throw new \RuntimeException('Credential worker release barrier timed out.');
    }
    usleep(1000);
}

$status = ($input['action'] ?? null) === 'budget' ? 204 : 200;
$code = null;
$exceptionClass = null;
$completionState = null;
$currentBeforeState = null;
$currentAfterState = null;
try {
    if ($input['action'] === 'budget') {
        app(AccessRecoveryBudget::class)->sensitive($request, (int) $input['actor_id'], (string) $input['email']);
    } elseif ($input['action'] === 'public_recovery') {
        $currentBeforeState = $recovery->current($request)['state'];
        $completionState = $recovery->complete($request, (string) $input['password'])['state'];
        $currentAfterState = $recovery->current($request)['state'];
        $status = $completionState === 'completed' ? 200 : 410;
    } else {
        $account = Account::query()->findOrFail((int) $input['account_id']);
        $actor = User::query()->findOrFail((int) $input['actor_id']);
        $actorMembership = AccountMembership::query()->where('account_id', $account->id)
            ->where('user_id', $actor->id)->where('role', 'admin')->firstOrFail();
        $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $actorMembership));
        resolve(CurrentTenant::class)->accountId = (int) $account->id;

        if ($input['action'] === 'reset') {
            $context = OfficeErpExecutionContext::officeAdmin($account, $actor, 'credential-race:'.$input['idempotency_key']);
            app(OfficeErpMemberCredentials::class)->reset(
                $request,
                $context,
                (int) $input['company_id'],
                (int) $input['erp_membership_id'],
                (int) $input['expected_revision'],
                (string) $input['password'],
                (string) $input['idempotency_key'],
            );
        } elseif ($input['action'] === 'promote') {
            Auth::guard('web')->setUser($actor);
            $request->setUserResolver(static fn (): User => $actor);
            $target = User::query()->findOrFail((int) $input['target_user_id']);
            app(AccountMemberController::class)->update($request, $target);
        } elseif ($input['action'] === 'issue_recovery') {
            Queue::fake();
            $context = OfficeErpExecutionContext::officeAdmin($account, $actor, 'credential-race:'.$input['idempotency_key']);
            app(OfficeErpMemberCredentials::class)->issueRecovery(
                $request,
                $context,
                (int) $input['company_id'],
                (int) $input['erp_membership_id'],
                (string) $input['idempotency_key'],
            );
        } else {
            throw new \RuntimeException('Unsupported credential concurrency action.');
        }
        resolve(CurrentTenant::class)->accountId = null;
    }
} catch (HttpResponseException $exception) {
    $status = $exception->getResponse()->getStatusCode();
    $exceptionClass = $exception::class;
} catch (HttpExceptionInterface $exception) {
    $status = $exception->getStatusCode();
    $code = preg_match('/\A[a-z][a-z0-9_]{2,80}\z/', $exception->getMessage()) === 1 ? $exception->getMessage() : null;
    $exceptionClass = $exception::class;
} catch (QueryException $exception) {
    $status = 503;
    $code = 'credential_store_unavailable';
    $exceptionClass = $exception::class;
} catch (Throwable $exception) {
    $status = 503;
    $code = 'credential_worker_failed';
    $exceptionClass = $exception::class;
}

echo json_encode([
    'status' => $status,
    'code' => $code,
    'exception_class' => $exceptionClass,
    'completion_state' => $completionState,
    'current_before_state' => $currentBeforeState,
    'current_after_state' => $currentAfterState,
    'transaction_level' => DB::transactionLevel(),
], JSON_THROW_ON_ERROR);
