<?php

use App\Actions\AccessAccounts;
use App\Erp\Commercial\OfficeErpCompanyMembers;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpMemberInvitationContinuation;
use App\Erp\Commercial\OfficeErpMemberInvitations;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Support\CurrentAccount;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Integration\OfficeErpCommercialProcessTestCase;
use Tests\Integration\OfficeErpMemberConcurrencyTest;

require __DIR__.'/bootstrap.php';
require __DIR__.'/OfficeErpMemberConcurrencyTest.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$identity = OfficeErpCommercialProcessTestCase::assertIsolatedStores();
if (($input['source_hashes'] ?? null) !== OfficeErpMemberConcurrencyTest::sourceHashes()) {
    throw new RuntimeException('Member race test and worker sources differ.');
}
Http::preventStrayRequests();
$base = $input['base'];

function writeMemberRaceSignal(string $path, string $contents): void
{
    $temporary = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
    if (file_put_contents($temporary, $contents, LOCK_EX) === false || ! rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to write member race synchronization signal.');
    }
}

function officeContext(array $input): OfficeErpExecutionContext
{
    $account = Account::query()->findOrFail($input['account_id']);
    $actor = User::query()->findOrFail($input['actor_id']);
    $membership = AccountMembership::query()->where('account_id', $account->id)->where('user_id', $actor->id)->firstOrFail();
    $request = Request::create('http://localhost:3000');
    $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));
    app()->instance('request', $request);

    return OfficeErpExecutionContext::officeAdmin($account, $actor, 'member-race:'.$input['action']);
}

function recipientRequest(int $recipientId, string $token): array
{
    $request = Request::create('http://localhost:3000');
    $session = app('session')->driver();
    $session->start();
    $request->setLaravelSession($session);
    app()->instance('request', $request);
    $continuation = app(OfficeErpMemberInvitationContinuation::class)->exchange($request, $token);
    $user = User::query()->findOrFail($recipientId);
    Auth::guard('web')->login($user);
    $session->put([
        Auth::guard('web')->getName() => (int) $user->id,
        'access_deadline' => time() + 3600,
        'access_authenticated' => true,
        'access_auth_revision' => (int) $user->access_auth_revision,
        'current_account_id' => null,
    ]);
    $request->setUserResolver(static fn (): User => $user);

    return [$request, (string) $continuation['operation_key']];
}

$acceptance = null;
$preparationError = null;
try {
    $acceptance = $input['action'] === 'accept'
        ? recipientRequest((int) $input['recipient_id'], (string) $input['token'])
        : null;
} catch (Throwable $exception) {
    $preparationError = $exception;
}
writeMemberRaceSignal($base.'.ready', json_encode([
    'pid' => getmypid(), 'database_pid' => $identity['database_pid'], 'source_hashes' => $input['source_hashes'],
    'prepared' => $preparationError === null,
], JSON_THROW_ON_ERROR));
if ($preparationError !== null) {
    $status = $preparationError instanceof HttpExceptionInterface ? $preparationError->getStatusCode() : 500;
    echo json_encode(['status' => $status, 'result' => ['code' => 'preparation_failed']], JSON_THROW_ON_ERROR);
    DB::disconnect();
    exit;
}
if ($input['action'] === 'hold-relationship-revoke') {
    DB::transaction(function () use ($input, $base): void {
        DB::table('erp_companies')->where('id', (int) $input['company_id'])->lockForUpdate()->firstOrFail();
        writeMemberRaceSignal($base.'.held', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
        $deadline = microtime(true) + 20;
        while (! file_exists($base.'.release')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Relationship revocation barrier timed out.');
            }
            usleep(5000);
        }
        DB::table('office_erp_contracts')->where('id', (int) $input['contract_id'])->where('erp_company_id', (int) $input['company_id'])
            ->update(['agreement_status' => 'canceled', 'canceled_at' => now(), 'updated_at' => now()]);
    });
    echo json_encode(['status' => 200, 'result' => ['status' => 'relationship_revoked']], JSON_THROW_ON_ERROR);
    DB::disconnect();
    exit;
}
$deadline = microtime(true) + 20;
while (! file_exists((string) $input['barrier'])) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Member race barrier timed out.');
    }
    usleep(5000);
}
if ($input['action'] === 'accept') {
    writeMemberRaceSignal($base.'.attempting', (string) DB::selectOne('select pg_backend_pid() as pid')->pid);
}

$status = 200;
ob_start();
try {
    $companyId = (int) $input['company_id'];
    $key = (string) ($input['operation_key'] ?? ('member-race-'.bin2hex(random_bytes(8))));
    if ($input['action'] === 'issue') {
        $result = app(OfficeErpMemberInvitations::class)->issue(officeContext($input), $companyId, [
            'name' => 'Concurrent invite', 'email' => (string) $input['email'], 'role' => 'viewer',
            'permissions_mode' => 'defaults', 'permissions' => [], 'establishment_scope' => 'all', 'establishment_ids' => [],
        ], $key);
    } elseif ($input['action'] === 'accept') {
        [$request, $acceptKey] = $acceptance;
        $result = app(OfficeErpMemberInvitations::class)->accept($request, [], $acceptKey);
    } elseif ($input['action'] === 'reissue') {
        $result = app(OfficeErpMemberInvitations::class)->reissue(officeContext($input), $companyId, (int) $input['invitation_id'], $key);
    } elseif ($input['action'] === 'revoke') {
        $result = app(OfficeErpMemberInvitations::class)->revoke(officeContext($input), $companyId, (int) $input['invitation_id'], $key);
    } elseif ($input['action'] === 'update') {
        $result = app(OfficeErpCompanyMembers::class)->update(officeContext($input), $companyId, (int) $input['membership_id'], ['role' => 'viewer'], $key);
    } elseif ($input['action'] === 'remove') {
        $result = app(OfficeErpCompanyMembers::class)->remove(officeContext($input), $companyId, (int) $input['membership_id'], ['confirmed' => true], $key);
    } elseif ($input['action'] === 'promote-recipient' || $input['action'] === 'demote-issuer') {
        $targetId = (int) ($input['action'] === 'promote-recipient' ? $input['recipient_id'] : $input['actor_id']);
        DB::transaction(function () use ($input, $targetId, $companyId): void {
            $target = User::query()->findOrFail($targetId);
            app(AccessAccounts::class)->advisory('email:'.mb_strtolower(trim($target->email)));
            $target = User::query()->whereKey($targetId)->lockForUpdate()->firstOrFail();
            $account = Account::query()->whereKey($input['account_id'])->lockForUpdate()->firstOrFail();
            $membership = AccountMembership::query()->where('account_id', $account->id)->where('user_id', $target->id)->lockForUpdate()->first();
            if ($input['action'] === 'promote-recipient') {
                AccountMembership::query()->firstOrCreate(
                    ['account_id' => $account->id, 'user_id' => $target->id], ['role' => 'admin'],
                );
            } elseif ($membership !== null) {
                $membership->forceFill(['role' => 'user'])->save();
            }
            DB::table('erp_companies')->where('id', $companyId)->first();
        });
        $result = ['status' => $input['action']];
    } else {
        throw new RuntimeException('Unsupported member concurrency action.');
    }
} catch (ValidationException) {
    $status = 422;
    $result = ['code' => 'validation_failed'];
} catch (ModelNotFoundException) {
    $status = 404;
    $result = ['code' => 'not_found'];
} catch (HttpExceptionInterface $exception) {
    $status = $exception->getStatusCode();
    $result = ['code' => 'http_error'];
} catch (Throwable $exception) {
    $status = 500;
    $result = ['code' => 'worker_failure', 'type' => $exception::class];
}

$capturedOutput = ob_get_clean();
echo json_encode([
    'status' => $status,
    'result' => $result ?? [],
    'diagnostic' => ['captured_output_bytes' => strlen($capturedOutput), 'captured_output_sha256' => hash('sha256', $capturedOutput)],
], JSON_THROW_ON_ERROR);
DB::disconnect();
