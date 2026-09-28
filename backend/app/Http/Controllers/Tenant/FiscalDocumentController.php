<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\FiscalDocument;
use App\Services\Fiscal\Read\FiscalCoverage;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class FiscalDocumentController extends Controller
{
    /**
     * Como a captura da conta está indo: cobertura da carteira, o que precisa de
     * alguém e o que o último lote deixou.
     *
     * A resposta sai do serviço de leitura já no formato do painel. Não há
     * Resource aqui de propósito nesta primeira entrega: o resumo não é uma
     * linha de tabela com allowlist de colunas, e os campos que ele declara
     * são uma lista fechada de agregados — número, palavra de motivo e instante
     * — onde o segredo não está em esquecer de tirar um campo, e sim em não
     * haver campo de credencial para tirar.
     */
    public function summary(FiscalCoverage $coverage): JsonResponse
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        return response()->json([
            'data' => $coverage->summary((int) resolve(CurrentTenant::class)->accountId),
        ]);
    }
}
