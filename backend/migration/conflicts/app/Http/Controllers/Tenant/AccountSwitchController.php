<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\SupportAccessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountSwitchController extends Controller
{
    /**
     * Switch the authenticated user's current account.
     *
     * Regular users may only switch to accounts they belong to;
     * super_admins may target any account. A super_admin moving
     * into or out of a foreign account leaves an audit trail so
     * every support switch stays visible in `support_access_logs`.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
        ]);

        $account = Account::findOrFail($data['account_id']);
        $user = $request->user();

        if (! $user->isSuperAdmin() && $user->accountRole($account) === null) {
            abort(403, 'Você não pertence a esta conta.');
        }

        $previousAccountId = $user->current_account_id;

        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        $this->auditSuperAdminSwitch($request, $previousAccountId, $account->getKey());

        return response()->json($user->currentAccount);
    }

    private function auditSuperAdminSwitch(Request $request, mixed $previousAccountId, int $targetAccountId): void
    {
        $user = $request->user();

        if (! $user->isSuperAdmin()) {
            return;
        }

        if ($previousAccountId !== null
            && (int) $previousAccountId !== $targetAccountId
            && $user->accountRole((int) $previousAccountId) === null
        ) {
            (new SupportAccessLog)->forceFill([
                'super_admin_user_id' => $user->getKey(),
                'account_id' => (int) $previousAccountId,
                'action' => 'exit',
                'metadata' => ['via' => 'account.switch', 'restored_account_id' => $targetAccountId],
                'ip' => $request->ip(),
            ])->save();
        }

        if ($user->accountRole($targetAccountId) === null) {
            (new SupportAccessLog)->forceFill([
                'super_admin_user_id' => $user->getKey(),
                'account_id' => $targetAccountId,
                'action' => 'enter',
                'metadata' => ['via' => 'account.switch'],
                'ip' => $request->ip(),
            ])->save();
        }
    }
}
