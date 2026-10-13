<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Tenant\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $accountId = $user?->current_account_id;

        if ($accountId === null) {
            abort(403, 'Conta atual não definida.');
        }

        $account = Account::find($accountId);

        if ($account === null) {
            abort(403, 'Conta atual inválida.');
        }

        if ($account->status === 'suspended') {
            abort(403, 'Conta suspensa.');
        }

        if (! $user->isSuperAdmin() && $user->accountRole($account) === null) {
            abort(403, 'Você não pertence a esta conta.');
        }

        if (! $request->isMethodSafe() && $account->subscription?->status !== 'active') {
            abort(403, 'Assinatura inativa.');
        }

        resolve(CurrentTenant::class)->accountId = $account->getKey();

        return $next($request);
    }
}
