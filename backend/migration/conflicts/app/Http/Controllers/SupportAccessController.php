<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\SupportAccessLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportAccessController extends Controller
{
    public function enter(Request $request, Account $account): JsonResponse
    {
        $user = $request->user();

        $isForeignEntry = $user->accountRole($account) === null
            && (int) $user->current_account_id !== (int) $account->getKey();

        if ($isForeignEntry) {
            (new SupportAccessLog)->forceFill([
                'super_admin_user_id' => $user->getKey(),
                'account_id' => $account->getKey(),
                'action' => 'enter',
                'ip' => $request->ip(),
            ])->save();
        }

        $user->forceFill(['current_account_id' => $account->getKey()])->save();

        return response()->json($account->refresh());
    }

    public function exit(Request $request): JsonResponse
    {
        $user = $request->user();

        $homeAccountId = $user->accountLinks()->orderBy('id')->value('account_id');

        if ($homeAccountId === null) {
            abort(422, 'Nenhuma conta própria para retornar.');
        }

        $currentAccountId = $user->current_account_id;

        if ($currentAccountId !== null && $user->accountRole((int) $currentAccountId) !== null) {
            abort(422, 'Você não está em modo suporte.');
        }

        if ($currentAccountId !== null) {
            (new SupportAccessLog)->forceFill([
                'super_admin_user_id' => $user->getKey(),
                'account_id' => $currentAccountId,
                'action' => 'exit',
                'metadata' => ['restored_account_id' => (int) $homeAccountId],
                'ip' => $request->ip(),
            ])->save();
        }

        $user->forceFill(['current_account_id' => (int) $homeAccountId])->save();

        return response()->json($user->currentAccount);
    }
}
