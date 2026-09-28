<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UpsertClientEcacPowerOfAttorneyRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class ClientEcacPowerOfAttorneyController extends Controller
{
    public function update(UpsertClientEcacPowerOfAttorneyRequest $request, Client $client): ClientResource
    {
        $validated = $request->validated();

        // `validated()` traz `serpro_code => null` mesmo quando o corpo não
        // mandou a chave, e gravar esse `null` apagaria o código que já
        // estava registrado. Só quem envia a chave muda o código.
        if (! $request->exists('serpro_code')) {
            unset($validated['serpro_code']);
        }

        $client->ecacPowerOfAttorney()->updateOrCreate(
            ['client_id' => $client->getKey()],
            $validated
        );

        return new ClientResource($client->fresh(['currentCertificate', 'ecacPowerOfAttorney']));
    }

    public function destroy(Client $client): Response
    {
        Gate::authorize('update', $client);

        $client->ecacPowerOfAttorney()->delete();

        return response()->noContent();
    }
}
