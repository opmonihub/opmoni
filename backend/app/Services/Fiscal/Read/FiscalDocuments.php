<?php

namespace App\Services\Fiscal\Read;

use App\Enums\FiscalStage;
use App\Models\FiscalDocument;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * A tabela de documentos capturados: a consulta filtrada, a página e os
 * modelos que a própria consulta devolveu.
 *
 * A conta vem por parâmetro e não do singleton. `CurrentTenant` é mutável e
 * sobrevive entre requisições no worker, e o escopo global do modelo só
 * filtra quando há conta corrente — no console e na fila não há. Uma leitura
 * que muda de significado conforme quem chamou não é leitura.
 *
 * Filtro, paginação e a lista de modelos saem da mesma consulta: a lista de
 * modelos é o distinct da consulta filtrada antes de paginar, e não a carteira
 * inteira. É a opção que o operador tem dentro do que já está vendo, e não o
 * catálogo do produto.
 */
class FiscalDocuments
{
    public const ORDENS = ['emissao_at', 'valor_total', 'captured_at'];

    public const PAGINAS = [25, 50, 100];

    public const PAGINA_PADRAO = 25;

    /**
     * Página da consulta filtrada, com os modelos que a própria consulta
     * filtrada devolveu.
     *
     * @param  array<string, mixed>  $filters  Só as chaves validadas pelo
     *                                         `IndexFiscalDocumentRequest`.
     * @return array{rows: LengthAwarePaginator<FiscalDocument>, available_models: list<string>}
     */
    public function page(int $accountId, array $filters): array
    {
        $filtrada = $this->filtered($accountId, $filters);
        $modelos = $this->modelosDisponiveis($filtrada, $filters);

        return [
            // `client` já vem com `withTrashed()` na relação do modelo, que é
            // onde essa regra mora: repetir o `withTrashed()` aqui criaria uma
            // segunda cópia que a próxima pessoa poderia remover sem ver que
            // desliga a linha histórica. O `currentCertificate` junto é a
            // leitura em lote do `client_certificate_status` — uma consulta
            // para a página, e não uma por linha.
            'rows' => $this->preencheASituacao($accountId, $this->preencheOsEventos($accountId, $this->sorted($filtrada, $filters)
                ->with(['client.currentCertificate'])
                ->paginate($this->porPagina($filters))
                ->withQueryString())),
            'available_models' => $modelos,
        ];
    }

    /**
     * A consulta da conta com os filtros aplicados, sem ordem e sem página.
     *
     * `emissao_at`, `valor_total` e o cliente são filtros de valor exato;
     * `issuer` e `recipient` entram por prefixo de CNPJ, que é como o
     * operador digita o número de quem ele não tem o papel na mão. O prefixo é
     * só dígito — validado no Request — para que o `%` do `LIKE` não possa ser
     * digitado por quem filtra.
     *
     * `q` é a busca por texto livre, e o valor manda no formato: exatamente 44
     * ou 50 dígitos é a chave de acesso inteira e casa por igualdade — 44 é a
     * chave da NF-e, NFC-e e CT-e, e 50 é a da NFS-e nacional; um fragmento de
     * chave não casa, porque o que se copia da tela é a chave completa;
     * fora disso, igualdade no `numero` da linha (a linha de evento não
     * carrega o número da nota, e expandir a busca para a chave inteira seria
     * trocar o significado de `q` conforme a etapa) ou substring no nome do
     * cliente, em `lower()` dos dois lados. O valor entra trimmed, e vazio não
     * é filtro. Curingas de `LIKE` (`%`, `_`, `\`) viram literal antes do
     * padrão, com `ESCAPE` explícito — o SQLite não tem caractere de escape
     * por padrão, e um `?q=%` que devolvesse a carteira inteira seria um
     * filtro que mente.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<FiscalDocument>
     */
    private function filtered(int $accountId, array $filters): Builder
    {
        return FiscalDocument::query()
            ->where('account_id', $accountId)
            ->when(
                filled($busca = trim((string) ($filters['q'] ?? ''))),
                fn (Builder $query): Builder => $query->where(function (Builder $buscaOu) use ($busca): void {
                    if (preg_match('/^(?:\d{44}|\d{50})$/', $busca) === 1) {
                        $buscaOu->where('chave_acesso', $busca);

                        return;
                    }

                    $padrao = '%'.addcslashes(mb_strtolower($busca), '\\%_').'%';
                    $buscaOu
                        ->where('numero', $busca)
                        ->orWhereHas('client', fn (Builder $cliente): Builder => $cliente->whereRaw(
                            "lower(clients.name) like ? escape '\\'",
                            [$padrao]
                        ));
                })
            )
            ->when(
                $filters['model'] ?? null,
                fn (Builder $query, array $models): Builder => $query->whereIn('model', $models)
            )
            ->when(
                isset($filters['client_id']),
                fn (Builder $query): Builder => $query->where('client_id', (int) $filters['client_id'])
            )
            ->when(
                filled($filters['issuer'] ?? null),
                fn (Builder $query): Builder => $query->where('emitente_cnpj', 'like', (string) $filters['issuer'].'%')
            )
            ->when(
                filled($filters['recipient'] ?? null),
                fn (Builder $query): Builder => $query->where('destinatario_cnpj', 'like', (string) $filters['recipient'].'%')
            )
            ->when(
                isset($filters['kind']),
                fn (Builder $query): Builder => $query->where('kind', $filters['kind'])
            )
            ->when(
                isset($filters['issued_from']),
                fn (Builder $query): Builder => $query->whereDate('emissao_at', '>=', $filters['issued_from'])
            )
            ->when(
                isset($filters['issued_to']),
                fn (Builder $query): Builder => $query->whereDate('emissao_at', '<=', $filters['issued_to'])
            )
            ->when(
                isset($filters['amount_min']),
                fn (Builder $query): Builder => $query->where('valor_total', '>=', $filters['amount_min'])
            )
            ->when(
                isset($filters['amount_max']),
                fn (Builder $query): Builder => $query->where('valor_total', '<=', $filters['amount_max'])
            );
    }

    /**
     * Ordem da página, com desempate por id e sem data por último.
     *
     * Sem campo de ordenação, a emissão mais recente vem primeiro — a leitura
     * de quem abre a tabela é "o que aconteceu por último". O desempate é o id
     * na mesma direção, porque duas notas emitidas no mesmo segundo não podem
     * trocar de lugar entre a primeira e a segunda página.
     *
     * A coluna de emissão é nullable, e a linha sem data de emissão é real: o
     * resumo da conta já precisa excluí-la da série por não conseguir
     * colocá-la em um mês. Onde ela cai no `ORDER BY` não pode ser o padrão do
     * banco, e os padrões divergem — o Postgres põe `NULL` **primeiro** no
     * `DESC` (e primeiro no `ASC` do SQLite, que põe por último no `DESC`).
     *
     * Por isso a ausência é resolvida por uma expressão explícita antes da
     * direção, e não pela opção óbvia: `nulls last` é sintaxe do Postgres e
     * quebraria a suíte, que roda em SQLite e é a suíte que precisa continuar
     * provando isto. `(coluna IS NULL)` vale `0`/`1` nos três dialetos e `0` vem
     * antes de `1` em todos, então o `asc` põe a linha com valor primeiro em
     * qualquer direção escolhida. A alternativa portátil seria `CASE WHEN`,
     * que é a mesma coisa com mais SQL.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<FiscalDocument>
     */
    private function sorted(Builder $documents, array $filters): Builder
    {
        // A coluna e a direção são reescritas para a lista fechada, e não só
        // validadas no Request: este serviço é público e a direção vai para o
        // SQL como texto puro.
        $sorteio = $filters['sort'] ?? null;
        $coluna = $documents->getModel()->qualifyColumn(
            in_array($sorteio, self::ORDENS, true) ? $sorteio : 'emissao_at'
        );
        $invertida = strtolower((string) ($filters['direction'] ?? 'desc'));
        $direcao = in_array($invertida, ['asc', 'desc'], true) ? $invertida : 'desc';

        return $documents
            ->orderByRaw("({$coluna} IS NULL) asc")
            ->orderBy($coluna, $direcao)
            ->orderBy($documents->getModel()->qualifyColumn('id'), $direcao);
    }

    /**
     * Os modelos que a consulta filtrada devolve, mais os que o operador já
     * tinha selecionado, em ordem alfabética.
     *
     * O distinct vem do mesmo clone que alimenta a página, para que a lista de
     * opções não possa discordar do que está na tela. O filtro de modelo entra
     * nele como qualquer outro filtro, e por isso a lista descreve o
     * resultado, não a carteira.
     *
     * O `reorder()` é o que mantém essa promessa mesmo que a chamada venha
     * depois da ordenação: `SELECT DISTINCT` com `ORDER BY` de coluna que não
     * está no `SELECT` é erro no Postgres, e o clone é a única coisa que impede
     * que a ordenação da página vaze para cá.
     *
     * O modelo selecionado e sem linha atrás de si continua na lista. É o caso
     * de filtro que esvaziou a tabela, e é justamente quando o operador mais
     * precisa do chip para poder tirá-lo: sem ele, a única forma de sair do
     * zero é recarregar a página.
     *
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function modelosDisponiveis(Builder $filtrada, array $filters): array
    {
        // O `distinct` é do banco e o `array_unique` é do PHP, e os dois
        // existem: o banco resolve o volume — a carteira tem uma linha por
        // documento, e `distinct` evita trazer todas elas para a memória só
        // para descartar quase todas. O `array_unique` resolve o texto, que é
        // a junção com os selecionados: ele não sabe que o `nfe` que veio do
        // banco e o `nfe` que veio da query são a mesma palavra.
        $modelos = (clone $filtrada)
            ->reorder()
            ->distinct()
            ->toBase()
            ->pluck('model')
            ->map(fn (mixed $model): string => (string) $model)
            ->all();

        // `strval` nos selecionados porque o `pluck` acima devolve string e o
        // Request entrega os mesmos valores como string de query: os dois
        // lados precisam virar a mesma palavra para o `unique` juntar.
        $modelos = array_unique(array_merge($modelos, array_map(strval(...), $filters['model'] ?? [])));

        // `sort` reindexa, e é ele que dá a ordem estável entre duas chamadas
        // — a lista de opções não pode mudar de ordem sozinha.
        sort($modelos);

        return $modelos;
    }

    /**
     * Preenche `event_count` nas linhas da página, com uma consulta só.
     *
     * O par que define a linha do tempo é cliente e chave de acesso, com a
     * etapa `event` — nunca a posição. O mesmo documento chega em NSUs
     * diferentes conforme a entrega, e uma contagem por NSU contaria cada
     * entrega como um documento diferente.
     *
     * O número é o mesmo em toda linha da chave, inclusive na linha que é um
     * desses eventos: o campo pergunta "quantos eventos esta chave tem", e a
     * linha de evento é parte da resposta, não uma exceção a ela. Subtrair a
     * si mesma colocaria dois números diferentes na mesma página para a mesma
     * chave, e o operador não teria como saber qual ler.
     *
     * @param  LengthAwarePaginator<FiscalDocument>  $pagina
     * @return LengthAwarePaginator<FiscalDocument>
     */
    private function preencheOsEventos(int $accountId, LengthAwarePaginator $pagina): LengthAwarePaginator
    {
        $linhas = $pagina->getCollection();

        if ($linhas->isEmpty()) {
            return $pagina;
        }

        // O par exato cliente/chave é o que define a linha do tempo, e ele é
        // uma tupla: `whereIn` recusa coluna dupla e `whereRowValues` compara
        // o conjunto inteiro, não o par. Cada par vira um `orWhere` aninhado.
        // São no máximo `per_page` grupos — 100 na prática — sobre um índice
        // que já começa por `client_id`, e a alternativa (uma consulta por
        // linha) seria N vezes pior.
        $consulta = FiscalDocument::query()
            ->where('account_id', $accountId)
            ->where('stage', FiscalStage::Event->value)
            ->where(function (Builder $query) use ($linhas): void {
                foreach ($linhas as $document) {
                    $query->orWhere(function (Builder $par) use ($document): void {
                        $par->where('client_id', (int) $document->client_id)
                            ->where('chave_acesso', (string) $document->chave_acesso);
                    });
                }
            })
            ->selectRaw('client_id, chave_acesso, count(*) as total')
            ->groupBy('client_id', 'chave_acesso')
            ->toBase()
            ->get();

        $eventos = [];

        foreach ($consulta as $total) {
            $eventos[$total->client_id.'|'.$total->chave_acesso] = (int) $total->total;
        }

        foreach ($linhas as $document) {
            $document->event_count = $eventos[(int) $document->client_id.'|'.$document->chave_acesso] ?? 0;
        }

        return $pagina;
    }

    /**
     * A linha do tempo de uma chave de acesso: os eventos do mesmo cliente, na
     * ordem em que aconteceram.
     *
     * O par cliente + chave é o mesmo que a contagem da lista usa, e pelo mesmo
     * motivo: o mesmo documento chega em NSUs diferentes conforme a entrega, e
     * a etapa `event` é o que separa a linha de evento das outras duas. Sem o
     * cliente na consulta, uma nota emitida a um cliente e recebida por outro
     * traria os eventos do vizinho para a linha do tempo.
     *
     * A ausência de instante é resolvida por expressão explícita, pelo mesmo
     * motivo da página: os padrões de `NULL` divergem entre SQLite e Postgres,
     * e a linha do tempo é ordenada no banco.
     *
     * @return Collection<int, FiscalDocument>
     */
    public function eventsOf(int $accountId, FiscalDocument $document): Collection
    {
        $ocorrencia = (new FiscalDocument)->qualifyColumn('evento_ocorrido_em_at');

        return FiscalDocument::query()
            ->where('account_id', $accountId)
            ->where('client_id', (int) $document->client_id)
            ->where('chave_acesso', (string) $document->chave_acesso)
            ->where('stage', FiscalStage::Event->value)
            ->orderByRaw("({$ocorrencia} IS NULL) asc")
            ->orderBy($ocorrencia)
            ->orderBy((new FiscalDocument)->qualifyColumn('id'))
            ->get();
    }

    /**
     * A situação de cada linha da página: `cancelada` quando a linha do tempo
     * da chave tem um evento `110111` (o cancelamento da NF-e e do CT-e, que
     * usam o mesmo `tpEvento`), `autorizada` quando chegou o documento
     * completo, `resumo` quando só o resumo chegou. A linha de evento não é
     * linha de documento, e por isso fica nula.
     *
     * A consulta é uma só para a página, como a de `preencheOsEventos`: o par
     * que define a linha do tempo é o mesmo — cliente e chave de acesso. O
     * `LIKE '110111%'` casa o `nSeqEvento` junto (`110111-1`), que é a forma
     * que o `event_id` guarda; o prefixo é texto fixo do fisco, e não entrada
     * do operador.
     *
     * @param  LengthAwarePaginator<FiscalDocument>  $pagina
     * @return LengthAwarePaginator<FiscalDocument>
     */
    public function preencheASituacao(int $accountId, LengthAwarePaginator $pagina): LengthAwarePaginator
    {
        $linhas = $pagina->getCollection();

        if ($linhas->isEmpty()) {
            return $pagina;
        }

        $canceladas = FiscalDocument::query()
            ->where('account_id', $accountId)
            ->where('stage', FiscalStage::Event->value)
            ->where('event_id', 'like', '110111%')
            ->where(function (Builder $query) use ($linhas): void {
                foreach ($linhas as $document) {
                    $query->orWhere(function (Builder $par) use ($document): void {
                        $par->where('client_id', (int) $document->client_id)
                            ->where('chave_acesso', (string) $document->chave_acesso);
                    });
                }
            })
            ->selectRaw('client_id, chave_acesso')
            ->toBase()
            ->get()
            ->map(fn (mixed $linha): string => $linha->client_id.'|'.$linha->chave_acesso)
            ->all();

        $canceladas = array_fill_keys($canceladas, true);

        foreach ($linhas as $document) {
            $document->situacao = $this->situacaoDe($accountId, $document, null, $canceladas);
        }

        return $pagina;
    }

    /**
     * A situação de uma linha: `cancelada` quando a linha do tempo da chave
     * tem um evento `110111`, `autorizada` quando chegou o documento completo,
     * `resumo` quando só o resumo chegou. A linha de evento não é linha de
     * documento, e por isso é nula.
     *
     * O detalhe passa a linha do tempo que já carregou, e a lista passa o mapa
     * que já consultou: nenhum dos dois reconsulta o que já tem, e os dois
     * respondem a mesma pergunta com a mesma regra.
     *
     * @param  Collection<int, FiscalDocument>|null  $eventos
     * @param  array<string, true>|null  $canceladas
     */
    public function situacaoDe(int $accountId, FiscalDocument $document, ?Collection $eventos = null, ?array $canceladas = null): ?string
    {
        if ($document->stage === FiscalStage::Event) {
            return null;
        }

        if ($canceladas === null) {
            $eventos ??= $this->eventsOf($accountId, $document);

            $canceladas = [];

            foreach ($eventos as $evento) {
                if (str_starts_with((string) $evento->event_id, '110111')) {
                    $canceladas[(int) $document->client_id.'|'.$document->chave_acesso] = true;

                    break;
                }
            }
        }

        return match (true) {
            isset($canceladas[(int) $document->client_id.'|'.$document->chave_acesso]) => 'cancelada',
            $document->stage === FiscalStage::Document => 'autorizada',
            default => 'resumo',
        };
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function porPagina(array $filters): int
    {
        $porPagina = (int) ($filters['per_page'] ?? self::PAGINA_PADRAO);

        return in_array($porPagina, self::PAGINAS, true) ? $porPagina : self::PAGINA_PADRAO;
    }
}
