<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreClientMonitoringModulesRequest;
use App\Models\Client;
use App\Models\SerproMonitoring;
use App\Services\SerproMonitoringReader;
use App\Services\SerproObligationCatalog;
use App\Services\SupportAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A etapa de módulos do cadastro: o GET devolve o catálogo servido com a
 * sugestão do regime e a associação atual; o POST grava os vínculos e nada
 * mais — nenhum job, nenhuma execução, nenhuma chamada ao provedor.
 */
class ClientMonitoringModuleController extends Controller
{
    public function __construct(private SerproMonitoringReader $reader) {}

    public function index(Client $client): JsonResponse
    {
        Gate::authorize('view', $client);

        $catalogo = resolve(SerproObligationCatalog::class);
        $sugeridas = array_flip($catalogo->suggestedFor($client->tax_regime));

        // Os vínculos vivos do cliente, numa leitura só: o `associated` de cada
        // obrigação sai do mapa, e não de uma consulta por slug.
        $associadas = SerproMonitoring::query()
            ->where('client_id', $client->getKey())
            ->pluck('obligation')
            ->flip();

        $obrigacoes = [];

        foreach ($catalogo->all() as $slug => $obrigacao) {
            if (! in_array($obrigacao['category'], ['direct', 'derived'], true)) {
                continue;
            }

            $obrigacoes[] = [
                'slug' => $slug,
                'label' => $obrigacao['label'] ?? $slug,
                'category' => $obrigacao['category'],
                'suggested' => isset($sugeridas[$slug]),
                'associated' => isset($associadas[$slug]),
            ];
        }

        return response()->json([
            'data' => [
                'regime' => $client->tax_regime?->value,
                'obligations' => $obrigacoes,
            ],
        ]);
    }

    public function store(StoreClientMonitoringModulesRequest $request, Client $client): JsonResponse
    {
        $result = ['associated' => 0, 'already' => 0];

        foreach ($request->validated('obligations') as $slug) {
            $parcial = $this->reader->associate(
                (int) $client->account_id,
                (string) $slug,
                [(int) $client->getKey()],
            );

            $result['associated'] += $parcial['associated'];
            $result['already'] += $parcial['already'];
        }

        SupportAudit::logWrite($request, 'clients', 'monitoring-modules', $client->getKey(), [
            'obligations' => $request->validated('obligations'),
        ]);

        return response()->json(['data' => $result]);
    }
}
