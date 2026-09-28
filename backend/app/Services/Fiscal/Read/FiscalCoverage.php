<?php

namespace App\Services\Fiscal\Read;

use App\Models\Client;
use App\Models\FiscalCursor;
use App\Models\FiscalDocument;

/**
 * A leitura da captura por conta: quanto da carteira é consultável e o que
 * precisa de uma mão humana.
 *
 * Duas medidas que não se misturam. `coverage` conta certificado, e é o que
 * responde "dá para capturar esse cliente hoje"; `attention` conta operação, e é
 * o que responde "isso vai ficar assim sozinho". Um cliente com certificado
 * perfeito e a consulta parada entra nos dois — contável e em atenção — porque
 * uma medida que escondesse a outra diria que a carteira está saudável quando
 * não está, e que está doente quando a única notícia é um cliente sem A1 no
 * primeiro dia de uso.
 *
 * A leitura nunca abre a senha do certificado. `certificatePassword()` é do
 * caminho de captura, e a marca `certificate_reupload` que ela deixa no cursor é
 * o que transforma uma cifra que não abre em algo visível. Aqui, certificado com
 * coluna preenchida é certificado capturável, ponto.
 */
class FiscalCoverage
{
    /**
     * Resumo da captura da conta.
     *
     * @param  int  $accountId  A conta, sempre explícita: `CurrentTenant` é um
     *                          singleton mutável e o escopo global do modelo é
     *                          condicional, então nenhum dos dois serve de
     *                          garantia aqui.
     * @return array{
     *     coverage: array{total: int, capturable: int, not_capturable: int},
     *     attention: list<array{client_id: int, client_name: string, reason: string, blocked_until: ?string}>,
     *     documents: array{total: int, models: array<string, int>, over_time: list<array{month: string, total: int}>},
     *     last_capture: ?array{source: string, ran_at: string, error: ?string}
     * }
     */
    public function summary(int $accountId): array
    {
        $carteira = $this->carteira($accountId);

        return [
            'coverage' => [
                'total' => $carteira['total'],
                'capturable' => $carteira['capturable'],
                'not_capturable' => $carteira['total'] - $carteira['capturable'],
            ],
            'attention' => $carteira['attention'],
            'documents' => $this->documentos($accountId),
            'last_capture' => $this->ultimaCaptura($accountId),
        ];
    }

    /**
     * Clientes da conta, com a contagem de cobertura e a lista de atenção.
     *
     * O cliente removido por logicamente não entra: ele não é capturado, então
     * contá-lo faria a cobertura da carteira descer por um cliente que ninguém
     * mais espera ver documento. `Client::query()` já exclui os apagados, e a
     * coluna `account_id` é repetida na consulta porque o escopo global do
     * modelo só filtra quando há conta corrente — no console e na fila não há,
     * e um relatório que muda de significado conforme o chamador não é
     * relatório.
     *
     * A atenção sai em ordem alfabética de nome e, no empate, de id. A ordem
     * não agrupa por motivo — quem agrupa é o painel, que sabe o que é
     * urgente — mas ela precisa ser estável entre duas chamadas, e é por isso
     * que os dois Criteria estão ali.
     *
     * @return array{attention: list<array{client_id: int, client_name: string, reason: string, blocked_until: ?string}>, capturable: int, total: int}
     */
    private function carteira(int $accountId): array
    {
        $cursores = $this->cursoresPorCliente($accountId);

        $atencao = [];
        $capturable = 0;
        $total = 0;

        $clientes = Client::query()
            ->with(['currentCertificate'])
            ->where('account_id', $accountId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        foreach ($clientes as $cliente) {
            $total++;
            $doCliente = $cursores[$cliente->getKey()] ?? [];

            // A cobertura conta certificado, e só ele: um cliente com A1 válido
            // e a consulta parada continua sendo capturável, e é o que a
            // carteira dele diz. A atenção é outro eixo e sai logo abaixo.
            if ($this->motivoDoCertificado($cliente, $doCliente) === null) {
                $capturable++;
            }

            $motivo = $this->motivo($cliente, $doCliente);

            if ($motivo === null) {
                continue;
            }

            $atencao[] = [
                'client_id' => $cliente->getKey(),
                'client_name' => $cliente->name,
                'reason' => $motivo,
                'blocked_until' => $this->fimDoBloqueio($motivo, $doCliente),
            ];
        }

        return ['attention' => $atencao, 'capturable' => $capturable, 'total' => $total];
    }

    /**
     * Cursores da conta, agrupados por cliente.
     *
     * Agrupar é o que garante que cobertura e atenção falem do mesmo objeto: as
     * duas contas saem deste mapa, e uma segunda consulta por cliente poderia
     * discordar da primeira no instante entre as duas.
     *
     * Só as quatro colunas que a classificação olha vêm junto. A posição não
     * entra: nenhuma delas diz se a captura está atrás, e carregar a carteira
     * inteira para não usar `last_nsu` seria pagar por um número que este
     * relatório não tem o que dizer.
     *
     * @return array<int, list<FiscalCursor>>
     */
    private function cursoresPorCliente(int $accountId): array
    {
        return FiscalCursor::query()
            ->where('account_id', $accountId)
            ->select(['client_id', 'last_error', 'blocked_until', 'last_seen_at'])
            ->orderBy('id')
            ->get()
            ->groupBy(fn (FiscalCursor $cursor): int => (int) $cursor->client_id)
            ->map(fn ($cursores): array => $cursores->all())
            ->all();
    }

    /**
     * O motivo pelo qual este cliente precisa de alguém, ou `null` quando nada
     * pede ação.
     *
     * A lista é uma precedência, não um catálogo: `reason` é uma palavra só, e
     * o que ela nomeia é o próximo passo, não o maior número de defeitos. A
     * ordem vai do que impede a captura de existir ao que se resolve sozinho,
     * e dentro dela o que não volta por conta própria vem antes do que volta:
     *
     * 1. `certificate_absent` — não há A1; nada mais sobre este cliente é
     *    observável.
     * 2. `certificate_expired` — antes da senha, porque reenviar o certificado
     *    conserta os dois de uma vez e a validade vencida é a que não expira
     *    sozinha.
     * 3. `certificate_password_missing` — certificado válido sem senha
     *    guardada.
     * 4. `certificate_reupload` — a captura tentou e a senha guardada não
     *    abriu. Só ela sabe; aqui a coluna é conferida, não aberta.
     * 5. `gap_abandoned` — posição que o fisco entregou e ninguém mais vai
     *    buscar, então o documento não existe e nenhuma execução o traz.
     * 6. `history_interrupted` — o fisco não retroage, e continuar capturando
     *    não devolve o período.
     * 7. `capture_blocked` — parada que ainda não passou e por isso tem hora
     *    para terminar.
     * 8. `continuity_warning` — a faixa entre o alerta e a interrupção: ainda
     *    recuperável, ainda quieta demais.
     * 9. `capture_failed` — qualquer outra mensagem, que a próxima execução
     *    tenta de novo.
     *
     * @param  list<FiscalCursor>  $cursores
     */
    private function motivo(Client $cliente, array $cursores): ?string
    {
        return $this->motivoDoCertificado($cliente, $cursores)
            ?? $this->motivoDaCaptura($cursores);
    }

    /**
     * O que impede a consulta de existir, e o que por isso tira o cliente da
     * cobertura.
     *
     * São os quatro primeiros motivos da precedência, e eles existem porque
     * nenhuma consulta ao fisco acontece sem A1 no cliente. Um `blocked_until`
     * que o fisco gravou ou um `last_seen_at` velho não entram aqui: a
     * consulta aconteceu, o certificado está de pé e o cliente é capturável —
     * o que muda é o resultado, e resultado é o outro eixo.
     *
     * A validade vem antes da senha guardada porque reenviar o certificado
     * conserta os dois de uma vez, e a validade vencida é a que não se resolve
     * sozinha. Já a marca `certificate_reupload` vem depois dos dois, e é ela
     * que fecha a cobertura de um certificado que parece bom: a coluna foi
     * escrita pela captura depois de ela tentar abrir a senha e falhar, e é a
     * única prova disponível sem abrir a cifra de novo.
     *
     * @param  list<FiscalCursor>  $cursores
     */
    private function motivoDoCertificado(Client $cliente, array $cursores): ?string
    {
        $certificado = $cliente->currentCertificate;

        if ($certificado === null) {
            return 'certificate_absent';
        }

        if ($certificado->valid_until->isPast()) {
            return 'certificate_expired';
        }

        if ($certificado->password_encrypted === null || $certificado->password_encrypted === '') {
            return 'certificate_password_missing';
        }

        if ($this->algum($cursores, fn (FiscalCursor $c): bool => $c->last_error === 'certificate_reupload')) {
            return 'certificate_reupload';
        }

        return null;
    }

    /**
     * O que a consulta fez, ou deixou de fazer, com um cliente que tem A1.
     *
     * Nenhum destes motivos desconta o cliente da cobertura: eles descrevem a
     * operação, e a operação é medida à parte. Confundir as duas coisas faria
     * um cliente bloqueado parecer não capturável — e o conserto que o painel
     * ofereceria, "reenvie o certificado", não resolveria nada.
     *
     * @param  list<FiscalCursor>  $cursores
     */
    private function motivoDaCaptura(array $cursores): ?string
    {
        if ($this->algum($cursores, fn (FiscalCursor $c): bool => $c->last_error === 'gap_abandoned')) {
            return 'gap_abandoned';
        }

        if ($this->algum($cursores, fn (FiscalCursor $c): bool => $c->historyIsInterrupted())) {
            return 'history_interrupted';
        }

        if ($this->algum($cursores, fn (FiscalCursor $c): bool => $this->estaBloqueado($c))) {
            return 'capture_blocked';
        }

        if ($this->algum($cursores, fn (FiscalCursor $c): bool => $this->continuidadeEmRisco($c))) {
            return 'continuity_warning';
        }

        // Os tokens que sobram aqui são reconhecidos e não viram
        // `capture_failed`: `blocked_consumption` cuja janela já passou é
        // história, e `certificate_reupload` e `gap_abandoned` já ganharam nome
        // acima.
        if ($this->algum($cursores, fn (FiscalCursor $c): bool => ! in_array(
            $c->last_error,
            [null, '', 'blocked_consumption'],
            true
        ))) {
            return 'capture_failed';
        }

        return null;
    }

    /**
     * A parada que o fisco mandou valer é temporal — `isBlocked()` é só isso.
     *
     * O alerta exige as duas metades: pausa no tempo **e** a marca de consumo
     * indevido. O `137` esfria a consulta de um cliente saudável por uma hora a
     * cada consulta, e ele vem com pausa e sem marca; tratá-lo como problema
     * ensinaria o escritório a ignorar a lista.
     */
    private function estaBloqueado(FiscalCursor $cursor): bool
    {
        return $cursor->isBlocked() && $cursor->last_error === 'blocked_consumption';
    }

    /**
     * A faixa de aviso da continuidade: o cliente ainda não tem histórico
     * interrompido, mas já está perto.
     *
     * O limite duro é o do modelo (`historyIsInterrupted()`, que já foi
     * consultado acima), e o mole vem do mesmo `config('fiscal')` que o
     * reconciliador usa. O serviço de captura pergunta "interrompido? sim/não";
     * o painel precisa perguntar antes, e a janela é a mesma para os dois.
     */
    private function continuidadeEmRisco(FiscalCursor $cursor): bool
    {
        if ($cursor->last_seen_at === null) {
            return false;
        }

        return $cursor->last_seen_at->lt(now()->subDays((int) config('fiscal.continuity_alert_days', 45)));
    }

    /**
     * Até quando a consulta está parada, e só quando é a parada que nomeou o
     * motivo. Nos outros motivos a resposta é `null` de propósito: um campo com
     * valor fora do seu motivo é um campo que o painel aprende a ignorar.
     *
     * @param  list<FiscalCursor>  $cursores
     */
    private function fimDoBloqueio(string $motivo, array $cursores): ?string
    {
        if ($motivo !== 'capture_blocked') {
            return null;
        }

        foreach ($cursores as $cursor) {
            if ($this->estaBloqueado($cursor)) {
                return $cursor->blocked_until?->toISOString();
            }
        }

        return null;
    }

    /**
     * @param  list<FiscalCursor>  $cursores
     */
    private function algum(array $cursores, callable $condicao): bool
    {
        foreach ($cursores as $cursor) {
            if ($condicao($cursor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Os três agregados de documento, todos contados por `account_id`.
     *
     * Série só com mês que teve documento: um mês zerado seria um dado que o
     * fisco não produziu, e a tela que desenha a série precisa poder dizer
     * "ainda não há o que mostrar" em vez de mostrar um zero bonito.
     *
     * @return array{total: int, models: array<string, int>, over_time: list<array{month: string, total: int}>}
     */
    private function documentos(int $accountId): array
    {
        return [
            'total' => FiscalDocument::query()->where('account_id', $accountId)->count(),
            'models' => $this->documentosPorModelo($accountId),
            'over_time' => $this->documentosPorMes($accountId),
        ];
    }

    /**
     * Volume por modelo, só com o modelo que tem documento.
     *
     * Um modelo ausente do mapa é "nada capturado desse modelo ainda", e é assim
     * que o painel tem que poder dizer. Encher o mapa com `nfse: 0` para cada
     * caso do enum seria um número que ninguém emitiu.
     *
     * @return array<string, int>
     */
    private function documentosPorModelo(int $accountId): array
    {
        $base = FiscalDocument::query()->where('account_id', $accountId);
        $grammar = $base->getQuery()->getGrammar();
        $modelo = $grammar->wrap('modelo');
        $total = $grammar->wrap('total');

        $linhas = $base
            ->selectRaw("model as {$modelo}, count(*) as {$total}")
            ->groupBy('modelo')
            ->orderBy('modelo')
            ->toBase()
            ->get();

        $porModelo = [];

        foreach ($linhas as $linha) {
            $porModelo[(string) $linha->modelo] = (int) $linha->total;
        }

        return $porModelo;
    }

    /**
     * A série de emissão, em ordem cronológica e só com o mês que teve
     * documento.
     *
     * A expressão do mês vem por driver, como a de `ClientPortfolio`: o mesmo
     * `selectRaw` rodando em SQLite no teste e em Postgres no deploy precisa de
     * função diferente em cada um. O mês entra já formatado pela função do
     * banco, e o agrupamento é pelo alias — agrupar pela expressão repetida
     * funciona, mas o alias é o que `orderBy` ordena e os dois precisam
     * apontar para a mesma conta.
     *
     * @return list<array{month: string, total: int}>
     */
    private function documentosPorMes(int $accountId): array
    {
        $base = FiscalDocument::query()->where('account_id', $accountId);
        $grammar = $base->getQuery()->getGrammar();
        $mes = $grammar->wrap('mes');
        $total = $grammar->wrap('total');
        $expressao = match ($base->getConnection()->getDriverName()) {
            'pgsql' => "to_char(emissao_at, 'YYYY-MM')",
            'mysql' => "date_format(emissao_at, '%Y-%m')",
            default => "strftime('%Y-%m', emissao_at)",
        };

        $linhas = $base
            ->whereNotNull('emissao_at')
            ->selectRaw("{$expressao} as {$mes}, count(*) as {$total}")
            ->groupBy('mes')
            ->orderBy('mes')
            ->toBase()
            ->get();

        $serie = [];

        foreach ($linhas as $linha) {
            $serie[] = [
                'month' => (string) $linha->mes,
                'total' => (int) $linha->total,
            ];
        }

        return $serie;
    }

    /**
     * A última consulta que a conta fez, e o que ela deixou na coluna.
     *
     * `last_error` é lida como está porque a coluna foi escrita para isso: a
     * captura grava nela nome de classe, classificação do conector e frase
     * fixa, ou uma das três palavras fixas — nunca a mensagem do serviço, nunca
     * XML, nunca a senha do certificado. `null` quando a conta nunca consultou,
     * e não um cursor zerado: a distinção entre "nunca rodou" e "rodou e não
     * achou nada" é a primeira coisa que o painel precisa dizer.
     *
     * @return ?array{source: string, ran_at: string, error: ?string}
     */
    private function ultimaCaptura(int $accountId): ?array
    {
        $cursor = FiscalCursor::query()
            ->where('account_id', $accountId)
            ->whereNotNull('last_run_at')
            ->orderByDesc('last_run_at')
            ->orderByDesc('id')
            ->first();

        if ($cursor === null) {
            return null;
        }

        return [
            'source' => $cursor->source?->value,
            'ran_at' => $cursor->last_run_at->toISOString(),
            'error' => $cursor->last_error === '' ? null : $cursor->last_error,
        ];
    }
}
