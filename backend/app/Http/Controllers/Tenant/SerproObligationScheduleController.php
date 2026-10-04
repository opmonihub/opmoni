<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpdateSerproObligationSchedulesRequest;
use App\Models\Account;
use App\Services\SerproObligationScheduleManager;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A agenda por documento do escritório. As duas rotas endereçam a conta
 * corrente, nunca uma linha — o mesmo arranjo do flag de habilitação: a
 * agenda é uma por Account, e o `404` de conta inexistente já é o do
 * middleware `tenant`. Ler é de qualquer Membro; escrever é do par que
 * dispara a rotina (`admin`/`operador`), e é o Form Request quem decide.
 */
class SerproObligationScheduleController extends Controller
{
    public function index(SerproObligationScheduleManager $manager): JsonResponse
    {
        Gate::authorize('view', $this->account());

        return response()->json(['data' => $manager->for($this->account()->getKey())]);
    }

    public function update(UpdateSerproObligationSchedulesRequest $request, SerproObligationScheduleManager $manager): JsonResponse
    {
        $manager->replace($this->account()->getKey(), $request->validated('schedules'));
        SupportAudit::logWrite($request, 'serpro_obligation_schedules', 'update', null, [
            'schedules' => $request->validated('schedules'),
        ]);

        return response()->json(['data' => $manager->for($this->account()->getKey())]);
    }

    private function account(): Account
    {
        return Account::query()->findOrFail(resolve(CurrentTenant::class)->accountId);
    }
}
