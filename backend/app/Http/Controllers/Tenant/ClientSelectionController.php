<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreClientSelectionRequest;
use App\Services\ClientPortfolio;
use App\Services\ClientSelectionStore;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientSelectionController extends Controller
{
    public function store(
        StoreClientSelectionRequest $request,
        ClientPortfolio $portfolio,
        ClientSelectionStore $store,
    ): JsonResponse {
        $filters = $request->safe()->only(['q', 'status', 'tax_regime', 'deadline_status', 'certificate_status', 'poa_status', 'tag_id', 'view']);
        $query = $portfolio->filtered($filters)->orderBy('id');

        if ((clone $query)->count() > 10000) {
            return response()->json([
                'message' => 'Seleção excede 10000 itens; refine os filtros ou aguarde processamento assíncrono.',
                'operation' => 'async-required',
            ], 422);
        }

        $ids = $query->pluck('id')->map(fn ($id) => (int) $id)->all();

        $selectionId = $store->put(
            (int) resolve(CurrentTenant::class)->accountId,
            (int) $request->user()->getKey(),
            $filters,
            $ids,
        );

        SupportAudit::logWrite($request, 'client_selections', 'create', null, ['count' => count($ids)]);

        return response()->json(['data' => [
            'id' => $selectionId,
            'count' => count($ids),
            'ids' => $ids,
        ]]);
    }

    public function presence(Request $request, string $selection, ClientSelectionStore $store): JsonResponse
    {
        $payload = $store->getFor(
            $selection,
            (int) resolve(CurrentTenant::class)->accountId,
            (int) $request->user()->getKey(),
        );

        abort_if($payload === null, 404);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'max:100'],
            'ids.*' => ['integer', 'distinct'],
        ]);

        $snapshot = array_flip($payload['ids']);
        $present = array_values(array_filter(
            array_map(intval(...), $validated['ids']),
            fn (int $id) => isset($snapshot[$id]),
        ));

        return response()->json(['data' => ['ids' => $present]]);
    }
}
