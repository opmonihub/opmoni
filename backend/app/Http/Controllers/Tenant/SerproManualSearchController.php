<?php

namespace App\Http\Controllers\Tenant;

use App\Enums\ClientPersonType;
use App\Enums\SerproManualSearchMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SearchSerproClientsRequest;
use App\Models\Client;
use App\Models\SerproManualSearch;
use App\Services\SerproManualSearchQuota;
use App\Services\SerproManualSearchStarter;
use App\Services\SerproObligationCatalog;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;

/**
 * A busca manual sob demanda de uma obrigação: o POST cria um pedido por
 * cliente e despacha o job; o GET de cota alimenta a barra "X de 10" do
 * modal, no mês corrente e por par `cliente × obrigação`.
 *
 * As duas rotas endereçam a conta corrente e a obrigação pelo slug — o
 * `where` da rota deixa a barra do slug entrar no parâmetro. O slug
 * desconhecido é `404` e o sem leitura servida é `422`, aqui e não no
 * serviço: é o mesmo desenho do `SerproMonitoringReader`, a decisão é do
 * catálogo e o controller não a repete no job.
 */
class SerproManualSearchController extends Controller
{
    public function store(string $obligation, SearchSerproClientsRequest $request, SerproManualSearchStarter $starter, SerproObligationCatalog $catalogo): JsonResponse
    {
        $this->resolverObrigacao($obligation, $catalogo);

        $criadas = $starter->start(
            (int) resolve(CurrentTenant::class)->accountId,
            (int) auth()->id(),
            $obligation,
            $request->validated('client_ids'),
            SerproManualSearchMode::from($request->validated('mode', SerproManualSearchMode::Full->value)),
            $request->validated('recalculate_date'),
        );

        SupportAudit::logWrite($request, 'serpro_manual_searches', 'create', null, [
            'obligation' => $obligation,
            'client_ids' => $request->validated('client_ids'),
        ]);

        return response()->json(['data' => ['requested' => count($criadas)]], 202);
    }

    /**
     * A cota do mês por cliente PJ da conta, no formato que a barra do modal
     * lê: quem não pediu nada no mês entra com `used` zero — ausência de
     * pedido não é ausência de cliente.
     */
    public function quota(string $obligation, SerproManualSearchQuota $quota, SerproObligationCatalog $catalogo): JsonResponse
    {
        $this->resolverObrigacao($obligation, $catalogo);

        $accountId = (int) resolve(CurrentTenant::class)->accountId;

        $usadas = SerproManualSearch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('obligation', $obligation)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->selectRaw('client_id, count(*) as n')
            ->groupBy('client_id')
            ->pluck('n', 'client_id');

        $dados = Client::query()
            ->where('account_id', $accountId)
            ->where('person_type', ClientPersonType::Company)
            ->orderBy('id')
            ->get()
            ->map(fn (Client $client): array => [
                'client_id' => $client->getKey(),
                'used' => (int) ($usadas[$client->getKey()] ?? 0),
                'limit' => $quota->limit(),
            ])
            ->all();

        return response()->json(['data' => $dados]);
    }

    /**
     * O slug tem de existir e ter leitura servida — `sync_enabled` com par
     * `SISTEMA/IDSERVICO` verificado. Uma busca por obrigação que o catálogo
     * ainda não serve seria fila nascida para falhar.
     */
    private function resolverObrigacao(string $obligation, SerproObligationCatalog $catalogo): void
    {
        abort_if($catalogo->get($obligation) === null, 404);
        abort_unless(
            collect($catalogo->syncables())->contains(fn (array $unidade): bool => $unidade['slug'] === $obligation),
            422,
            'Esta obrigação não tem leitura servida pelo provedor.',
        );
    }
}
