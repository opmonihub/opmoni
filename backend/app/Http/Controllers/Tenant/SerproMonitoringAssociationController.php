<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AssociateSerproClientsRequest;
use App\Services\SerproMonitoringReader;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * O POST que cria o vínculo `cliente × obrigação` — e só ele. Não há job nem
 * execução aqui: a cota do provedor é gasta pela execução, e um POST que
 * despachasse a integração seria o botão "Adicionar clientes" cobrando sem
 * pedir. A linha nasce com `source_at` nulo e entra na próxima execução.
 */
class SerproMonitoringAssociationController extends Controller
{
    public function store(string $obligation, AssociateSerproClientsRequest $request, SerproMonitoringReader $reader): JsonResponse
    {
        $result = $reader->associate(
            (int) resolve(CurrentTenant::class)->accountId,
            $obligation,
            $request->validated('client_ids'),
        );

        SupportAudit::logWrite($request, 'serpro_monitorings', 'associate', null, [
            'obligation' => $obligation,
            'client_ids' => $request->validated('client_ids'),
        ]);

        return response()->json(['data' => $result]);
    }
}
