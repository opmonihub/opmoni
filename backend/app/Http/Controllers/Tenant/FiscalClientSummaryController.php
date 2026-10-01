<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\IndexFiscalClientsRequest;
use App\Http\Resources\FiscalClientSummaryResource;
use App\Services\Fiscal\Read\FiscalClients;
use App\Tenant\CurrentTenant;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A visão fiscal por cliente: um cartão por cliente com os totais que a
 * carteira capturou.
 *
 * A autorização mora no `IndexFiscalClientsRequest`, que é o que dá entrada na
 * ação — ler o agregado é leitura de carteira, aberta a qualquer membro da
 * conta (`FiscalDocumentPolicy::viewAny`). As linhas saem do `FiscalClients`
 * já no formato do cartão, e o Resource é quem decide a forma da linha.
 */
class FiscalClientSummaryController extends Controller
{
    public function index(IndexFiscalClientsRequest $request, FiscalClients $clients): AnonymousResourceCollection
    {
        $linhas = $clients->summary(
            (int) resolve(CurrentTenant::class)->accountId,
            $request->filters(),
        );

        return FiscalClientSummaryResource::collection($linhas);
    }
}
