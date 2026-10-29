<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Support\AccessSession;
use App\Support\AccessTransaction;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 401);
        $id = $request->session()->get('current_account_id');
        if (! $id) {
            abort(response()->json(['code' => 'account_required', 'message' => 'Selecione um escritório'], 403));
        }

        $releaseBeforeMemberMutation = $request->routeIs('members.update', 'members.destroy');
        if ($releaseBeforeMemberMutation) {
            [$account, $membership] = DB::transaction(fn (): array => $this->resolveCurrentAccount($request, $id));

            return $this->runWithAccount($request, $next, $account, $membership);
        }

        return DB::transaction(function () use ($request, $next, $id): Response {
            [$account, $membership] = $this->resolveCurrentAccount($request, $id);

            return $this->runWithAccount($request, $next, $account, $membership);
        });
    }

    /** @return array{Account,AccountMembership} */
    private function resolveCurrentAccount(Request $request, int $id): array
    {
        AccessTransaction::boundLockWaits();
        $account = Account::lockForUpdate()->find($id);
        $membership = AccountMembership::where('account_id', $id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
        if (! $account) {
            abort(response()->json(['code' => 'account_required', 'message' => 'Selecione um escritório'], 403));
        }
        if (! $membership) {
            abort(response()->json(['code' => 'membership_required', 'message' => 'Acesso ao escritório negado'], 403));
        }
        if ($account->status !== 'active') {
            abort(response()->json(['code' => 'account_suspended', 'message' => 'Escritório suspenso'], 403));
        }

        if (! $request->isMethodSafe() && $account->subscription?->status !== 'active') {
            abort(response()->json(['code' => 'subscription_inactive', 'message' => 'Assinatura inativa'], 403));
        }

        $assertion = $request->header('X-Account-Id');
        if (! is_string($assertion) || ! ctype_digit($assertion) || strlen($assertion) > 16 || (int) $assertion < 1 || (int) $assertion > 9007199254740991) {
            abort(response()->json(['code' => 'validation_error', 'message' => 'Informe o escritório exibido', 'errors' => ['account_id' => ['Informe o escritório exibido']]], 422));
        } if ((int) $assertion !== (int) $id) {
            abort(response()->json(['code' => 'account_context_changed', 'message' => 'O escritório selecionado mudou. Atualize a página.'], 409));
        } if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS']) && $request->exists('account_id')) {
            abort(response()->json(['code' => 'validation_error', 'message' => 'Escritório não pode ser informado no conteúdo', 'errors' => ['account_id' => ['Campo não permitido']]], 422));
        }

        return [$account, $membership];
    }

    /** @param Closure(Request):Response $next */
    private function runWithAccount(Request $request, Closure $next, Account $account, AccountMembership $membership): Response
    {
        $tenant = resolve(CurrentTenant::class);
        $previousTenantId = $tenant->accountId;
        $tenant->accountId = (int) $account->id;
        $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));
        try {
            AccessSession::assertOwnership($request);
            $response = $next($request);
            AccessSession::assertOwnership($request);
            $response->headers->set('Cache-Control', 'no-store');

            return $response;
        } finally {
            $tenant->accountId = $previousTenantId;
            $request->attributes->remove(CurrentAccount::class);
        }
    }
}
