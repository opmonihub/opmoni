<?php

namespace App\Services;

/**
 * A tradução entre o que um serviço do Integra Contador pede e devolve e o
 * que a linha do monitoramento guarda.
 *
 * `payload()` monta o `dados` do pedido; `project()` devolve a projeção que
 * `SerproMonitoringWriter` grava. Cada serviço habilitado tem as duas metades
 * aqui, na mesma classe, porque o par "o que se pergunta" e "o que se lê da
 * resposta" muda junto — a versão do serviço é a fronteira dos dois.
 *
 * O que nunca sai daqui: `dados` bruto, documento em base64
 * (`demonstrativoPdf`, `textoResolucao`) e o corpo de mensagem da caixa
 * postal — o stub carrega assunto e datas, e abrir a mensagem continua sendo
 * ato jurídico que este sistema não pratica na sincronização.
 */
final class SerproMonitoringMapper
{
    /**
     * O `dados` do pedido, na forma que a documentação publicada do serviço
     * declara. Serviço sem entrada aqui sai com `dados` vazio.
     *
     * @return array<string, mixed>
     */
    public function payload(string $idServico): array
    {
        return match ($idServico) {
            // Ano-calendário da opção vigente, como `Number` — a leitura é a
            // do exercício corrente.
            'CONSULTAROPCAOREGIME103' => ['anoCalendario' => (int) now()->year],
            // Aqui o ano é `String(4)` — o provedor tipa o mesmo campo de
            // duas formas nos dois sistemas, e cada um leva a sua.
            'CONSDECLARACAO13' => ['anoCalendario' => (string) now()->year],
            // Primeira página, sem recorte de leitura, categoria ou favorito:
            // a lista é a caixa inteira até o limite da página.
            'MSGCONTRIBUINTE61' => [
                'categoria' => '0',
                'statusLeitura' => '0',
                'indicadorPagina' => '0',
            ],
            default => [],
        };
    }

    /**
     * As colunas que a resposta justifica, no vocabulário da linha. Serviço
     * sem leitura mapeada devolve `[]` — e o writer grava só o carimbo.
     *
     * @return array{fields?: array<string, string|int|null>, periods?: list<array<string, mixed>>, messages?: list<array<string, mixed>>, cause?: ?string, due_on?: ?string}
     */
    public function project(string $idServico, SerproResult $result): array
    {
        return match ($idServico) {
            'CONSULTAROPCAOREGIME103' => $this->regime($result->dados()),
            'CONSDECLARACAO13' => $this->pgdas($result->dados()),
            'MSGCONTRIBUINTE61' => $this->caixaPostal($result->dados()),
            default => [],
        };
    }

    /**
     * A mensagem que `MSGDETALHAMENTO62` devolveu, na forma que a tela mostra.
     *
     * O corpo sai como texto puro: o provedor manda HTML de modelo com
     * marcadores `++n++` que `variaveis` preenche, e a tela o mostra com
     * `whitespace-pre-wrap`. Tirar as tags aqui é o que impede o HTML do
     * provedor de chegar a um `v-html` no dia em que alguém o trocar.
     *
     * @return array{id: int, codigo: ?string, assunto: string, corpo: string, lida_em: ?string, ciencia_em: ?string, prazo_limite: ?string}
     */
    public function detalheMensagem(int $isn, mixed $dados): array
    {
        $dados = is_array($dados) ? $dados : [];
        $mensagem = $this->primeiroObjeto($dados['conteudo'] ?? $dados);

        $assunto = str_replace(
            '++VARIAVEL++',
            trim((string) ($mensagem['valorParametroAssunto'] ?? '')),
            trim((string) ($mensagem['assuntoModelo'] ?? '')),
        );

        $controle = trim((string) ($mensagem['numeroControle'] ?? ''));

        return [
            'id' => $isn,
            'codigo' => $controle === '' ? null : $controle,
            'assunto' => $assunto,
            'corpo' => $this->corpo(
                (string) ($mensagem['corpoModelo'] ?? ''),
                is_array($mensagem['variaveis'] ?? null) ? $mensagem['variaveis'] : [],
            ),
            'lida_em' => $this->instante($mensagem['dataLeitura'] ?? null, $mensagem['horaLeitura'] ?? null),
            'ciencia_em' => $this->data($mensagem['dataCiencia'] ?? null),
            'prazo_limite' => $this->data($mensagem['dataValidade'] ?? null)
                ?? $this->data($mensagem['dataExpiracao'] ?? null),
        ];
    }

    /**
     * `++1++` recebe `variaveis[0]`, na ordem que a documentação do provedor
     * define. As tags saem antes da troca, para que um valor com `<` não seja
     * lido como marcação.
     *
     * @param  array<int, mixed>  $variaveis
     */
    private function corpo(string $modelo, array $variaveis): string
    {
        $texto = preg_replace('#<br\s*/?>|</p>\s*#i', "\n", $modelo) ?? '';
        $texto = html_entity_decode(strip_tags($texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $texto = preg_replace_callback(
            '/\+\+(\d+)\+\+/',
            fn (array $m): string => (string) ($variaveis[(int) $m[1] - 1] ?? ''),
            $texto,
        ) ?? '';

        return trim(preg_replace("/[ \t]*\n[ \t]*/", "\n", $texto) ?? '');
    }

    /**
     * `RegimeApuracao` é um objeto só: regime e instante da opção. O
     * `demonstrativoPdf` e o `textoResolucao` que acompanham são documento
     * do contribuinte em base64 e não têm coluna para morar.
     *
     * @return array{fields: array<string, string|null>, cause: null}
     */
    private function regime(mixed $dados): array
    {
        $opcao = $this->primeiroObjeto($dados);

        return [
            'fields' => [
                'regime_escolhido' => isset($opcao['regimeEscolhido']) ? (string) $opcao['regimeEscolhido'] : null,
                'data_da_opcao' => $this->instante($opcao['dataHoraOpcao'] ?? null),
            ],
            'cause' => null,
        ];
    }

    /**
     * Um item por `periodoApuracao`: a transmissão mais recente decide
     * `declared_at` e o número da declaração, a existência de retificadora
     * marca `rectified`, e o DAS mais recente preenche a guia. O prazo não
     * está no payload — a linha e o período ficam com `due_on` nulo em vez
     * de uma data calculada que o provedor não assinou.
     *
     * @return array{fields: array<string, string|null>, due_on: null, periods: list<array{period: string, declared_at: ?string, rectified: bool, slip_number: ?string, slip_issued_at: ?string, due_on: null, slip_paid: ?bool}>, cause: ?string}
     */
    private function pgdas(mixed $dados): array
    {
        $dados = is_array($dados) ? $dados : [];

        $periodos = $dados['periodos'] ?? (isset($dados['periodo']) ? [$dados['periodo']] : []);
        $periodos = is_array($periodos) ? $periodos : [];

        $linhas = [];
        $gi = null;
        $giInstante = null;

        foreach ($periodos as $periodo) {
            if (! is_array($periodo)) {
                continue;
            }

            $operacoes = is_array($periodo['operacoes'] ?? null) ? $periodo['operacoes'] : [];

            $declaracao = null;
            $declaracaoInstante = null;
            $das = null;
            $dasInstante = null;
            $retificada = false;

            foreach ($operacoes as $operacao) {
                if (! is_array($operacao)) {
                    continue;
                }

                $tipo = (string) ($operacao['tipoOperacao'] ?? '');

                if (is_array($operacao['indiceDeclaracao'] ?? null)) {
                    $indice = $operacao['indiceDeclaracao'];
                    $instante = $this->instante($indice['dataHoraTransmissao'] ?? null);

                    if ($declaracao === null || $this->comparaInstante($instante, $declaracaoInstante) > 0) {
                        $declaracao = $indice;
                        $declaracaoInstante = $instante;
                    }

                    if (str_contains($tipo, 'Retificador')) {
                        $retificada = true;
                    }
                }

                if (is_array($operacao['indiceDas'] ?? null)) {
                    $indice = $operacao['indiceDas'];
                    // A fixture e a documentação divergem na caixa da letra:
                    // `datahoraEmissaoDas` no exemplo real, `dataHoraEmissaoDas`
                    // na tabela publicada.
                    $instante = $this->instante($indice['datahoraEmissaoDas'] ?? $indice['dataHoraEmissaoDas'] ?? null);

                    if ($das === null || $this->comparaInstante($instante, $dasInstante) > 0) {
                        $das = $indice;
                        $dasInstante = $instante;
                    }
                }
            }

            $pa = preg_replace('/\D/', '', (string) ($periodo['periodoApuracao'] ?? ''));
            $linhas[] = [
                'period' => strlen($pa) === 6 ? substr($pa, 0, 4).'-'.substr($pa, 4, 2) : (string) $pa,
                'declared_at' => $declaracaoInstante,
                'rectified' => $retificada,
                'slip_number' => isset($das['numeroDas']) ? (string) $das['numeroDas'] : null,
                'slip_issued_at' => $dasInstante,
                'due_on' => null,
                'slip_paid' => $das === null ? null : (bool) ($das['dasPago'] ?? false),
            ];

            if ($declaracaoInstante !== null && $this->comparaInstante($declaracaoInstante, $giInstante) > 0) {
                $gi = isset($declaracao['numeroDeclaracao']) ? (string) $declaracao['numeroDeclaracao'] : null;
                $giInstante = $declaracaoInstante;
            }
        }

        return [
            'fields' => ['gi_declaracao' => $gi],
            'due_on' => null,
            'periods' => $linhas,
            // Ano sem período nenhum é o provedor dizendo "não consta" — a
            // causa `sem_declaracao` é a forma que o painel tem de nomeá-la.
            'cause' => $linhas === [] ? 'sem_declaracao' : null,
        ];
    }

    /**
     * A primeira página da caixa postal: `nao_lidas` conta o que a página
     * trouxe e `mais_paginas` registra que o universo continua além dela —
     * a segunda página é outra chamada cobrada, e a leitura do painel fica
     * honesta sobre o recorte.
     *
     * O exemplo publicado do provedor embrulha a lista em `conteudo`; a
     * tabela do serviço descreve os mesmos campos no topo. Os dois formatos
     * entram pelo mesmo caminho.
     *
     * @return array{fields: array<string, int|string|bool|null>, messages: list<array{id: int, assunto: string, received_at: ?string, lida_em: ?string, ciencia_em: ?string, prazo_limite: ?string, unread: bool}>, cause: null}
     */
    private function caixaPostal(mixed $dados): array
    {
        $dados = is_array($dados) ? $dados : [];
        $bloco = $dados['conteudo'] ?? $dados;
        $bloco = $this->primeiroObjeto($bloco);

        $mensagens = is_array($bloco['listaMensagens'] ?? null) ? $bloco['listaMensagens'] : [];

        $stubs = [];
        $naoLidas = 0;
        $ultima = null;

        foreach ($mensagens as $mensagem) {
            if (! is_array($mensagem)) {
                continue;
            }

            // `indicadorLeitura` é o flag de leitura — a tabela do provedor
            // rotula a coluna vizinha com a mesma descrição, mas o exemplo
            // mostra `dataLeitura` preenchida onde este flag vale `1`.
            $lida = (string) ($mensagem['indicadorLeitura'] ?? '') === '1';

            if (! $lida) {
                $naoLidas++;
            }

            $recebida = $this->data($mensagem['dataEnvio'] ?? null);
            if ($recebida !== null && ($ultima === null || $recebida > $ultima)) {
                $ultima = $recebida;
            }

            $assunto = str_replace(
                '++VARIAVEL++',
                trim((string) ($mensagem['valorParametroAssunto'] ?? '')),
                trim((string) ($mensagem['assuntoModelo'] ?? '')),
            );

            $stubs[] = [
                'id' => (int) ($mensagem['isn'] ?? 0),
                'assunto' => $assunto,
                'received_at' => $recebida,
                'lida_em' => $this->instante($mensagem['dataLeitura'] ?? null, $mensagem['horaLeitura'] ?? null),
                'ciencia_em' => $this->data($mensagem['dataCiencia'] ?? null),
                'prazo_limite' => $this->data($mensagem['dataValidade'] ?? null),
                'unread' => ! $lida,
            ];
        }

        return [
            'fields' => [
                'nao_lidas' => $naoLidas,
                'ultima' => $ultima,
                'mais_paginas' => (string) ($bloco['indicadorUltimaPagina'] ?? '') === 'N',
            ],
            'messages' => $stubs,
            'cause' => null,
        ];
    }

    /**
     * O `dados` que volta como objeto ou como lista de um — os exemplos do
     * provedor usam as duas formas para o mesmo tipo de resposta.
     *
     * @return array<string, mixed>
     */
    private function primeiroObjeto(mixed $dados): array
    {
        if (! is_array($dados)) {
            return [];
        }

        if (array_is_list($dados)) {
            $primeiro = $dados[0] ?? null;

            return is_array($primeiro) ? $primeiro : [];
        }

        return $dados;
    }

    /**
     * `AAAAMMDD` para `Y-m-d`. Qualquer outra forma — vazia, truncada,
     * ausente — devolve `null`, porque uma data inventada é pior que a
     * declaração de que o provedor não mandou.
     */
    private function data(mixed $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) ($valor ?? ''));

        if (strlen($digitos) !== 8) {
            return null;
        }

        return substr($digitos, 0, 4).'-'.substr($digitos, 4, 2).'-'.substr($digitos, 6, 2);
    }

    /**
     * `AAAAMMDD` sozinho ou com `HHMMSS` ao lado, e `AAAAMMDDHHMMSS` num
     * campo só — o provedor usa os dois formatos, `dataHoraOpcao` na forma
     * longa e os pares `dataX`/`horaX` na caixa postal.
     */
    private function instante(mixed $data, mixed $hora = null): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) ($data ?? ''));

        if (strlen($digitos) === 14) {
            return substr($digitos, 0, 4).'-'.substr($digitos, 4, 2).'-'.substr($digitos, 6, 2)
                .' '.substr($digitos, 8, 2).':'.substr($digitos, 10, 2).':'.substr($digitos, 12, 2);
        }

        $dia = strlen($digitos) === 8
            ? substr($digitos, 0, 4).'-'.substr($digitos, 4, 2).'-'.substr($digitos, 6, 2)
            : null;

        if ($dia === null) {
            return null;
        }

        $h = preg_replace('/\D/', '', (string) ($hora ?? ''));

        if (strlen($h) !== 6) {
            return $dia;
        }

        return $dia.' '.substr($h, 0, 2).':'.substr($h, 2, 2).':'.substr($h, 4, 2);
    }

    /**
     * A ordem dos instantes no formato `Y-m-d H:i:s` é a ordem lexicográfica
     * das strings — `null` vale "veio antes de qualquer data".
     */
    private function comparaInstante(?string $a, ?string $b): int
    {
        return ($a ?? '') <=> ($b ?? '');
    }
}
