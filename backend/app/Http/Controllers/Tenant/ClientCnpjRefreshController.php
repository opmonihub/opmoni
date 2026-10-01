<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\ClientManager;
use App\Services\ClientPowerOfAttorneySummary;
use App\Services\CnpjLookupException;
use App\Services\SupportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClientCnpjRefreshController extends Controller
{
    public function __construct(private ClientManager $clients) {}

    public function preview(Client $client): JsonResponse
    {
        Gate::authorize('update', $client);

        try {
            $preview = $this->clients->previewCompany($client);
        } catch (CnpjLookupException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json(['data' => $preview]);
    }

    public function update(Request $request, Client $client): JsonResponse|ClientResource
    {
        Gate::authorize('update', $client);

        try {
            $client = $this->clients->refreshCompany($client);
        } catch (CnpjLookupException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        SupportAudit::logWrite($request, 'clients', 'cnpj_refresh', $client->getKey());
        $client->power_summary = resolve(ClientPowerOfAttorneySummary::class)->for($client);

        return new ClientResource($client->loadMissing(['currentCertificate']));
    }
}
