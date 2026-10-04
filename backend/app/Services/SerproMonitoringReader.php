<?php

namespace App\Services;

use App\Enums\ClientPersonType;
use App\Enums\SerproManualSearchState;
use App\Enums\SerproPowerOfAttorneyState;
use App\Enums\SerproSyncRunState;
use App\Models\SerproClientAuthorization;
use App\Models\SerproManualSearch;
use App\Models\SerproMonitoring;
use App\Models\SerproSyncRun;
use App\Models\SerproSyncRunItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A leitura do monitoramento: quais linhas contam e o que os números dizem.
 *
 * Duas decisões valem o serviço inteiro. A primeira é que só conta o que o
 * provedor respondeu — `source_at` preenchido — mais o que a execução em
 * curso está respondendo agora: uma linha associada e nunca chamada não é
 * dado, e mostrá-la seria fingir resposta. A segunda é que os contadores são
 * agregados **antes** dos filtros de situação, busca e tag: o filtro estreita
 * a visão do operador, e um `atencao` zerado pelo filtro diria ao lado das
 * linhas que ninguém precisa de atenção.
 *
 * Todo `account_id` é explícito e o escopo global é retirado de cada query:
 * este serviço só é chamado de request HTTP hoje, mas a regra que o job
 * segue — o singleton do tenant não é barreira — vale aqui do mesmo jeito.
 */
final class SerproMonitoringReader
{
    /** As linhas por página, do contrato com `MonitoringSheet`. */
    private const POR_PAGINA = 25;

    /** `unavailable` e `extinct` não têm leitura: nenhum número nasce delas. */
    private const CATEGORIAS_SERVIDAS = ['direct', 'derived'];

    /** O vocabulário de `situacao` que a rota aceita. */
    private const SITUACOES = ['em_dia', 'processando', 'pendencias', 'atencao', 'encerrado'];

    /**
     * O rótulo que o backend devolve com cada causa: a tela usa o próprio
     * vocabulário quando conhece o código e este texto quando não conhece —
     * uma causa nova do provedor chega legível sem redeploy do frontend.
     */
    private const CAUSAS = [
        'sem_declaracao' => 'Sem declaração',
        'sem_procuracao' => 'Sem procuração',
        'procuracao_invalida' => 'Procuração inválida',
        'contam_debitos' => 'Contam débitos',
    ];

    public function __construct(
        private readonly SerproObligationCatalog $catalogo,
        private readonly SerproMonitoringProjector $projector,
    ) {}

    /**
     * @return array{portfolio_total: int, attention: array<string, int>}
     */
    public function overview(int $accountId): array
    {
        // Clientes PJ distintos com ao menos um registro respondido — a
        // carteira que a integração viu, e não a carteira inteira.
        $portfolio = SerproMonitoring::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->whereNotNull('source_at')
            ->whereHas('client', fn ($query) => $query
                ->where('account_id', $accountId)
                ->where('person_type', ClientPersonType::Company))
            ->distinct('client_id')
            ->count('client_id');

        // O mesmo agregador da listagem, por obrigação servida — contador e
        // lista só concordam se lerem a mesma regra.
        $attention = [];
        foreach ($this->catalogo->all() as $slug => $obrigacao) {
            if (! in_array($obrigacao['category'], self::CATEGORIAS_SERVIDAS, true)) {
                continue;
            }

            $attention[$slug] = $this->linhas($accountId, $slug, $obrigacao)
                ->where('row.situacao', 'atencao')
                ->count();
        }

        return ['portfolio_total' => $portfolio, 'attention' => $attention];
    }

    /**
     * A listagem de uma obrigação com o resumo dela. O slug desconhecido e a
     * situação fora do vocabulário são `404`: o endereço pede algo que não
     * existe, e a resposta tem que dizer isso em vez de devolver uma lista
     * vazia que pareceria carteira zerada.
     *
     * @param  array{situacao?: string, q?: string, tag_id?: array<int>, page?: int}  $filters
     * @return array{data: array<string, mixed>, data_rows: list<array<string, mixed>>}
     */
    public function list(int $accountId, string $slug, array $filters): array
    {
        $obrigacao = $this->catalogo->get($slug);
        abort_if($obrigacao === null, 404);

        // A obrigação sem fonte responde com a categoria e contadores `null`
        // — a forma que diz "o provedor não a serve" em vez de um zero que
        // afirmaria "nenhum cliente precisa de atenção aqui".
        if (! in_array($obrigacao['category'], self::CATEGORIAS_SERVIDAS, true)) {
            return [
                'data' => [
                    'obligation' => $slug,
                    'category' => $obrigacao['category'],
                    'total' => null,
                    'em_dia' => null,
                    'processando' => null,
                    'pendencias' => null,
                    'atencao' => null,
                    'encerrado' => null,
                    'progress' => null,
                    'current_page' => 1,
                    'attention_reasons' => [],
                ],
                'data_rows' => [],
            ];
        }

        $situacao = (string) ($filters['situacao'] ?? '');
        abort_if($situacao !== '' && ! in_array($situacao, self::SITUACOES, true), 404);

        $linhas = $this->linhas($accountId, $slug, $obrigacao);
        $filtradas = $this->filtradas($linhas, $filters);

        $pagina = max(1, (int) ($filters['page'] ?? 1));

        return [
            'data' => [
                'obligation' => $slug,
                'category' => $obrigacao['category'],
                ...$this->resumo($linhas),
                'progress' => $this->progresso($accountId),
                'current_page' => $pagina,
            ],
            'data_rows' => $filtradas
                ->sortBy('record.client_id')
                ->slice(($pagina - 1) * self::POR_PAGINA, self::POR_PAGINA)
                ->values()
                ->map(fn (array $linha): array => $linha['row'])
                ->all(),
        ];
    }

    /**
     * A associação de cliente a obrigação: grava o vínculo e nada mais —
     * nenhum job, nenhuma execução, nenhuma chamada ao provedor. A linha
     * nasce `sem_dados` e com `source_at` nulo, o que a mantém fora dos
     * números até a primeira resposta: associar é pedir, não é dado.
     *
     * O `404` do slug desconhecido e o `422` da obrigação sem fonte moram
     * aqui pelo mesmo motivo que no `list` — a decisão é do catálogo, e
     * o controller não a repete. `account_id` e `client_id` vão por
     * `forceFill`: fora do `#[Fillable]` de propósito, e a conta nunca
     * vem do `CurrentTenant`. A repetição é idempotente pela unique
     * `(account_id, client_id, obligation)`.
     *
     * @param  array<int>  $clientIds
     * @return array{associated: int, already: int}
     */
    public function associate(int $accountId, string $slug, array $clientIds): array
    {
        $obrigacao = $this->catalogo->get($slug);
        abort_if($obrigacao === null, 404);
        abort_unless(in_array($obrigacao['category'], self::CATEGORIAS_SERVIDAS, true), 422);

        return DB::transaction(function () use ($accountId, $slug, $clientIds): array {
            $associated = 0;
            $already = 0;

            foreach ($clientIds as $clientId) {
                $record = SerproMonitoring::query()
                    ->withoutGlobalScope('account')
                    ->where('account_id', $accountId)
                    ->where('client_id', $clientId)
                    ->where('obligation', $slug)
                    ->first();

                if ($record !== null) {
                    $already++;

                    continue;
                }

                (new SerproMonitoring)
                    ->forceFill([
                        'account_id' => $accountId,
                        'client_id' => $clientId,
                        'obligation' => $slug,
                        'state' => 'sem_dados',
                        'source_at' => null,
                    ])
                    ->save();

                $associated++;
            }

            return ['associated' => $associated, 'already' => $already];
        });
    }

    /**
     * As linhas que entram na conta da obrigação: registro respondido, cliente
     * com item ativo nesta obrigação ou cliente com busca manual em curso —
     * e o projeto de cada uma já projetado, porque situação é derivada e não
     * coluna.
     *
     * @param  array{category: string, service: ?string, procuracao: ?string, derived_from: ?string, sync_enabled: bool}  $obrigacao
     * @return Collection<int, array{record: SerproMonitoring, row: array<string, mixed>, tags: list<int>}>
     */
    private function linhas(int $accountId, string $slug, array $obrigacao): Collection
    {
        $ativos = SerproSyncRunItem::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('current_obligation', $slug)
            ->whereHas('run', fn ($query) => $query
                ->withoutGlobalScope('account')
                ->where('account_id', $accountId)
                ->whereIn('state', [SerproSyncRunState::Queued, SerproSyncRunState::Running]))
            ->get()
            ->keyBy('client_id');

        // A busca manual em curso vale o que o item de execução vale: o
        // operador pediu, a fila ainda não respondeu, e a linha que some do
        // painel durante o trabalho seria a promessa que não se cumpre.
        $buscas = SerproManualSearch::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('obligation', $slug)
            ->whereIn('state', [SerproManualSearchState::Queued, SerproManualSearchState::Running])
            ->get()
            ->keyBy('client_id');

        $registros = SerproMonitoring::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->where('obligation', $slug)
            ->where(fn ($query) => $query
                ->whereNotNull('source_at')
                ->orWhereIn('client_id', $ativos->keys())
                ->orWhereIn('client_id', $buscas->keys()))
            ->whereHas('client', fn ($query) => $query
                ->where('account_id', $accountId)
                ->where('person_type', ClientPersonType::Company))
            ->with(['client.tags', 'client.serproAuthorizations'])
            ->orderBy('client_id')
            ->get();

        return $registros->map(fn (SerproMonitoring $record): array => [
            'record' => $record,
            'row' => $this->projector->row(
                $record->client,
                $record,
                $this->concessao($record, $obrigacao),
                $ativos->get($record->client_id),
                $buscas->has($record->client_id),
            ),
            'tags' => $record->client->tags->pluck('id')->all(),
        ]);
    }

    /**
     * Os filtros do operador sobre as linhas já projetadas. `situacao`
     * compara o valor derivado; `q` casa nome ou documento; `tag_id` pede
     * qualquer interseção.
     *
     * @param  Collection<int, array{record: SerproMonitoring, row: array<string, mixed>, tags: list<int>}>  $linhas
     * @param  array{situacao?: string, q?: string, tag_id?: array<int>}  $filters
     * @return Collection<int, array{record: SerproMonitoring, row: array<string, mixed>, tags: list<int>}>
     */
    private function filtradas(Collection $linhas, array $filters): Collection
    {
        $situacao = (string) ($filters['situacao'] ?? '');
        $busca = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $digitos = preg_replace('/\D+/', '', (string) ($filters['q'] ?? ''));
        $tags = array_map('intval', (array) ($filters['tag_id'] ?? []));

        return $linhas->filter(function (array $linha) use ($situacao, $busca, $digitos, $tags): bool {
            $row = $linha['row'];

            if ($situacao !== '' && $row['situacao'] !== $situacao) {
                return false;
            }

            if ($busca !== ''
                && ! str_contains(mb_strtolower($row['name']), $busca)
                && ! str_contains((string) $row['tax_id'], $busca)
                && ($digitos === '' || ! str_contains((string) $row['tax_id'], $digitos))) {
                return false;
            }

            if ($tags !== [] && array_intersect($tags, $linha['tags']) === []) {
                return false;
            }

            return true;
        });
    }

    /**
     * Os cinco contadores e as causas, contados sobre todas as linhas — os
     * filtros vêm depois. `total` é a soma dos quatro; `encerrado` fica
     * fora dela de propósito.
     *
     * @param  Collection<int, array{record: SerproMonitoring, row: array<string, mixed>, tags: list<int>}>  $linhas
     * @return array<string, mixed>
     */
    private function resumo(Collection $linhas): array
    {
        $contadores = array_fill_keys(self::SITUACOES, 0);
        $causas = [];

        foreach ($linhas as $linha) {
            $row = $linha['row'];
            $contadores[$row['situacao']]++;
            if ($row['situacao'] === 'atencao' && $row['cause'] !== null) {
                $causas[$row['cause']] = ($causas[$row['cause']] ?? 0) + 1;
            }
        }

        $reasons = collect($causas)
            ->map(fn (int $count, string $code): array => [
                'code' => $code,
                'count' => $count,
                'label' => self::CAUSAS[$code] ?? $code,
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        return [
            'total' => $contadores['em_dia'] + $contadores['processando']
                + $contadores['pendencias'] + $contadores['atencao'],
            'em_dia' => $contadores['em_dia'],
            'processando' => $contadores['processando'],
            'pendencias' => $contadores['pendencias'],
            'atencao' => $contadores['atencao'],
            'encerrado' => $contadores['encerrado'],
            'attention_reasons' => $reasons,
        ];
    }

    /**
     * A concessão que a linha consulta para o `stale`, entre as famílias que
     * a obrigação aceita. A vigente é a leitura honesta; sem ela, a mais
     * recente é a que explica o vencimento — e `null` é "nenhuma observada",
     * que o projetor lê como ausência de outorga.
     */
    private function concessao(SerproMonitoring $record, array $obrigacao): ?SerproClientAuthorization
    {
        if (($obrigacao['procuracao'] ?? null) === null) {
            return null;
        }

        $familias = collect($this->catalogo->procuracaoAlternatives($obrigacao['procuracao']))
            ->flatten()
            ->unique()
            ->all();

        $candidatas = $record->client->serproAuthorizations->whereIn('family', $familias);

        if ($candidatas->isEmpty()) {
            return null;
        }

        $hoje = today()->toDateString();

        return $candidatas->first(
            fn (SerproClientAuthorization $concessao): bool => $concessao->state === SerproPowerOfAttorneyState::Established
                && ($concessao->expires_on === null || $concessao->expires_on->toDateString() >= $hoje),
        ) ?? $candidatas->sortByDesc('expires_on')->first();
    }

    /**
     * Quantos transmitidos de quantos pedidos, na execução mais recente —
     * leitura do eixo da sincronização, ao lado e nunca dentro dos
     * contadores. Sem execução não há o que medir: `null`, e não zero.
     *
     * @return array{transmitted: int, requested: int}|null
     */
    private function progresso(int $accountId): ?array
    {
        $execucao = SerproSyncRun::query()
            ->withoutGlobalScope('account')
            ->where('account_id', $accountId)
            ->latest('id')
            ->first();

        if ($execucao === null || $execucao->total === 0) {
            return null;
        }

        return ['transmitted' => $execucao->synchronized, 'requested' => $execucao->total];
    }
}
