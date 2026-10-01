<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreClientSavedFilterRequest;
use App\Http\Resources\ClientSavedFilterResource;
use App\Models\ClientSavedFilter;
use App\Services\SupportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ClientSavedFilterController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ClientSavedFilter::class);

        return ClientSavedFilterResource::collection(
            ClientSavedFilter::query()->orderBy('name')->get()
        );
    }

    public function store(StoreClientSavedFilterRequest $request): JsonResponse
    {
        $filter = ClientSavedFilter::query()->create([
            ...$request->safe()->only(['name', 'q', 'filters']),
            'user_id' => $request->user()->getKey(),
        ]);

        SupportAudit::logWrite($request, 'client_saved_filters', 'create', $filter->getKey());

        return (new ClientSavedFilterResource($filter))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, ClientSavedFilter $savedFilter): Response
    {
        Gate::authorize('delete', $savedFilter);
        $savedFilter->delete();
        SupportAudit::logWrite($request, 'client_saved_filters', 'delete', $savedFilter->getKey());

        return response()->noContent();
    }
}
