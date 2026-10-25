<?php

namespace App\Http\Middleware;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Support\AccessSession;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ResolveOfficeErpCommercial
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user(), 401);
        AccessSession::assertOwnership($request);

        $sessionAccountId = $request->session()->get('current_account_id');
        abort_unless(is_int($sessionAccountId) || (is_string($sessionAccountId) && ctype_digit($sessionAccountId)), 403);
        $sessionAccountId = (int) $sessionAccountId;

        return DB::transaction(function () use ($request, $next, $sessionAccountId): Response {
            $account = Account::query()->lockForUpdate()->find($sessionAccountId);
            abort_unless($account && $account->status === 'active', 403);

            $membership = AccountMembership::query()
                ->where('account_id', $account->id)
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->lockForUpdate()
                ->first();
            abort_unless($membership && $membership->role === 'admin', 403);

            $assertion = $request->header('X-Account-Id');
            if (! is_string($assertion) || ! ctype_digit($assertion) || strlen($assertion) > 16 || (int) $assertion < 1 || (int) $assertion > 9007199254740991) {
                abort(response()->json(['code' => 'validation_error', 'message' => 'Informe o escritório exibido', 'errors' => ['account_id' => ['Informe o escritório exibido']]], 422));
            }
            if ((int) $assertion !== $sessionAccountId) {
                abort(response()->json(['code' => 'account_context_changed', 'message' => 'O escritório selecionado mudou. Atualize a página.'], 409));
            }
            if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $request->exists('account_id')) {
                abort(response()->json(['code' => 'validation_error', 'message' => 'Escritório não pode ser informado no conteúdo', 'errors' => ['account_id' => ['Campo não permitido']]], 422));
            }

            $tenant = resolve(CurrentTenant::class);
            $previousTenantId = $tenant->accountId;
            $tenant->accountId = $sessionAccountId;
            try {
                $request->attributes->set(CurrentAccount::class, new CurrentAccount($account, $membership));
                $request->attributes->set(
                    OfficeErpExecutionContext::class,
                    OfficeErpExecutionContext::officeAdmin($account, $request->user(), 'http:'.$request->route()->getName()),
                );
                AccessSession::assertOwnership($request);
                $response = $next($request);
                AccessSession::assertOwnership($request);
                $response->headers->set('Cache-Control', 'no-store');

                return $response;
            } finally {
                $tenant->accountId = $previousTenantId;
                $request->attributes->remove(CurrentAccount::class);
                $request->attributes->remove(OfficeErpExecutionContext::class);
            }
        });
    }
}
