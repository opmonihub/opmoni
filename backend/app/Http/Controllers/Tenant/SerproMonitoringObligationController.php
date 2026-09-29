<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\SerproMonitoring;
use App\Services\SerproMonitoringReader;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A listagem de uma obrigação com o resumo dela no mesmo envelope — o `data`
 * responde "quantos são" e o `data_rows` responde "quem são", e a tela lê os
 * dois de uma só vez para os números nunca discordarem das linhas.
 *
 * O `{obligation}` é o slug do registro (`declaracoes/pgdas` com barra e
 * tudo): o `where` da rota é quem deixa a barra entrar no parâmetro, e o
 * `404` para slug desconhecido é do `SerproMonitoringReader`.
 */
class SerproMonitoringObligationController extends Controller
{
    public function show(string $obligation, Request $request, SerproMonitoringReader $reader): JsonResponse
    {
        Gate::authorize('viewAny', SerproMonitoring::class);

        $resposta = $reader->list(
            (int) resolve(CurrentTenant::class)->accountId,
            $obligation,
            [
                'situacao' => (string) $request->query('situacao', ''),
                'q' => (string) $request->query('q', ''),
                'tag_id' => (array) $request->query('tag_id', []),
                'page' => $request->integer('page', 1),
            ],
        );

        return response()->json($resposta);
    }
}
