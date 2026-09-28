<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\LookupClientCnpjRequest;
use App\Services\CnpjLookupException;
use App\Services\CnpjWsLookup;
use Illuminate\Http\JsonResponse;

class ClientCnpjLookupController extends Controller
{
    public function __construct(private CnpjWsLookup $lookup) {}

    public function __invoke(LookupClientCnpjRequest $request): JsonResponse
    {
        try {
            // `lookupOrFail()` e não `lookup()`: a validação desta request aceita
            // o CNPJ alfanumérico, e a fonte pública não tem como responder sobre
            // ele. Sem o atalho, um documento que ela não conhece gastava uma das
            // três consultas por minuto da conta para receber um `404` certo e
            // previsto — e a tela não ser oferecer essa consulta não é defesa
            // para um endpoint.
            $result = $this->lookup->lookupOrFail((string) $request->validated()['tax_id']);
        } catch (CnpjLookupException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json(['data' => $result]);
    }
}
