<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreClientCertificateRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Services\ClientCertificateVault;
use App\Services\ClientPowerOfAttorneySummary;
use App\Services\Fiscal\Capture\FiscalCaptureDispatcher;
use App\Services\SupportAudit;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ClientCertificateController extends Controller
{
    public function __construct(private ClientCertificateVault $vault) {}

    public function store(StoreClientCertificateRequest $request, Client $client, FiscalCaptureDispatcher $dispatcher): ClientResource
    {
        Gate::authorize('update', $client);

        $validated = $request->validated();

        try {
            $this->vault->replace($client, $validated['certificate'], (string) $validated['password']);
        } finally {
            $validated['password'] = '';
            unset($validated);
        }

        $fresh = $client->fresh(['currentCertificate']);
        $fresh->power_summary = resolve(ClientPowerOfAttorneySummary::class)->for($fresh);
        $capture = $dispatcher->capturar($fresh);

        SupportAudit::logWrite($request, 'clients', 'certificate', $client->getKey(), [
            'sources' => $capture['sources'],
        ]);

        return (new ClientResource($fresh))->additional(['meta' => ['capture' => $capture]]);
    }

    public function destroy(Request $request, Client $client): Response
    {
        Gate::authorize('update', $client);

        $certificateId = $client->currentCertificate?->getKey();
        $this->vault->remove($client);

        SupportAudit::logWrite($request, 'clients', 'certificate-remove', $client->getKey(), [
            'certificate_id' => $certificateId,
        ]);

        return response()->noContent();
    }
}
