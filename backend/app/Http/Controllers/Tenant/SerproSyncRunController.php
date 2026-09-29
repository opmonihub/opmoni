<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\SerproSyncRunState;
use App\Http\Controllers\Controller;
use App\Http\Resources\SerproCallResource;
use App\Http\Resources\SerproSyncRunResource;
use App\Models\SerproSyncRun;
use App\Services\SerproRunStarter;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * As execuções de sincronização do escritório.
 *
 * O `{run}` das rotas passa pelo binding restrito da trait — execução de
 * outra conta é `404` aqui e em qualquer verbo — e a conta da escrita vem
 * do `CurrentTenant`, que o middleware `tenant` já povoou.
 */
class SerproSyncRunController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', SerproSyncRun::class);

        $runs = SerproSyncRun::query()
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 15), 100));

        return SerproSyncRunResource::collection($runs);
    }

    public function store(SerproRunStarter $starter): JsonResponse
    {
        Gate::authorize('create', SerproSyncRun::class);

        $run = $starter->start($this->accountId(), (int) auth()->id());

        return response()->json(['data' => new SerproSyncRunResource($run)], 202);
    }

    public function show(SerproSyncRun $run): SerproSyncRunResource
    {
        Gate::authorize('view', $run);

        $run->load(['items.client']);

        return new SerproSyncRunResource($run);
    }

    public function calls(SerproSyncRun $run): AnonymousResourceCollection
    {
        Gate::authorize('view', $run);

        $calls = $run->calls()->latest('id')->paginate(50);

        return SerproCallResource::collection($calls);
    }

    public function resync(SerproSyncRun $run, SerproRunStarter $starter): JsonResponse
    {
        Gate::authorize('resync', $run);

        // Uma execução em curso já trava o disparo dela mesma; o `409` aqui
        // é o mesmo que a guarda dentro da transação produziria, e o corpo
        // carrega o id para a tela apontar para a execução que corre.
        if (in_array($run->state, [SerproSyncRunState::Queued, SerproSyncRunState::Running], true)) {
            abort(409, 'A execução ainda está em andamento.');
        }

        $nova = $starter->start($run->account_id, (int) auth()->id(), $run->getKey());

        return response()->json(['data' => new SerproSyncRunResource($nova)], 202);
    }

    private function accountId(): int
    {
        return (int) resolve(CurrentTenant::class)->accountId;
    }
}
