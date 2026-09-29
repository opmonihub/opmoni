<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateSerproEnablementRequest;
use App\Models\Account;
use App\Services\SerproAccountEnablement;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * O flag de habilitação do escritório. As duas rotas endereçam a conta
 * corrente, nunca uma linha: a integração é uma por Account, e o `404` de
 * conta inexistente já é o do middleware `tenant`.
 */
class SerproAccountEnablementController extends Controller
{
    public function show(SerproAccountEnablement $enablement): JsonResponse
    {
        $account = $this->account();
        Gate::authorize('view', $account);

        return response()->json(['data' => ['enabled' => $enablement->enabled($account->getKey())]]);
    }

    public function update(UpdateSerproEnablementRequest $request, SerproAccountEnablement $enablement): JsonResponse
    {
        $enablement->set($this->account()->getKey(), (bool) $request->validated('enabled'));

        return response()->json(['data' => ['enabled' => $enablement->enabled($this->account()->getKey())]]);
    }

    private function account(): Account
    {
        return Account::query()->findOrFail(resolve(CurrentTenant::class)->accountId);
    }
}
