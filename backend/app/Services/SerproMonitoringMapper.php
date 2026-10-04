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
    public function __construct(private SerproSitfisPdfText $sitfisPdfText = new SerproSitfisPdfText) {}

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
            // Mesmo campo que no PGDASD, mas no PGMEI a doc publica `String(4)`.
            'DIVIDAATIVA24' => ['anoCalendario' => (string) now()->year],
            /*
             * PJ no regime normal usa `GERAL_MENSAL` (40). O serviço responde
             * por um período de apuração — uma chamada, um mês — e o sync
             * pergunta o mês corrente até haver recorte configurável.
             */
            'CONSXMLDECLARACAO38' => [
                'categoria' => 'GERAL_MENSAL',
                'anoPA' => (string) now()->year,
                'mesPA' => now()->format('m'),
            ],
            // A documentação manda `dados` vazio: a lista é toda a decadência.
            'CONSDECLARACAO142' => [],
            // Primeira página, sem recorte de leitura, categoria ou favorito:
            // a lista é a caixa inteira até o limite da página.
            'MSGCONTRIBUINTE61' => [
                'categoria' => '0',
                'statusLeitura' => '0',
                'indicadorPagina' => '0',
            ],
            // Primeira página do último ano civil: filtros extras são opcionais
            // na documentação, mas o recorte evita varrer o histórico inteiro.
            'PAGAMENTOS71' => [
                'tamanhoDaPagina' => 100,
                'primeiroDaPagina' => 0,
                'intervaloDataArrecadacao' => [
                    'dataInicial' => now()->subYear()->toDateString(),
                    'dataFinal' => now()->toDateString(),
                ],
            ],
            'PEDIDOSPARC163', 'PEDIDOSPARC173', 'PEDIDOSPARC183', 'PEDIDOSPARC193' => [],
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
            'DIVIDAATIVA24' => $this->dividaAtiva($result->dados()),
            'CONSXMLDECLARACAO38' => $this->dctfweb($result->dados()),
            'CONSDECLARACAO142' => $this->defis($result->dados()),
            'MSGCONTRIBUINTE61' => $this->caixaPostal($result->dados()),
            'PAGAMENTOS71' => $this->pagtoweb($result->dados()),
            'PEDIDOSPARC163', 'PEDIDOSPARC173', 'PEDIDOSPARC183', 'PEDIDOSPARC193' => $this->pedidosParcelamento(
                $result->dados(),
                $idServico,
            ),
            'RELATORIOSITFIS92' => $this->sitfis($result->dados()),
            default => [],
        };
    }

    /**
     * Une duas projeções de `PEDIDOSPARC*` na linha `parcelamentos/receita-federal`.
     *
     * @param  array{fields?: array<string, mixed>, periods?: list<array<string, mixed>>, cause?: ?string}  $existente
     * @param  array{fields?: array<string, mixed>, periods?: list<array<string, mixed>>, cause?: ?string}  $nova
     * @return array{fields: array<string, string|int|float|null>, due_on: null, periods: list<array<string, mixed>>, cause: ?string}
     */
    public function mesclarPedidosParcelamento(array $existente, array $nova): array
    {
        $periodosExistentes = is_array($existente['periods'] ?? null) ? $existente['periods'] : [];
        $periodosNova = is_array($nova['periods'] ?? null) ? $nova['periods'] : [];

        $modalidadesNova = [];
        foreach ($periodosNova as $periodo) {
            if (! is_array($periodo)) {
                continue;
            }

            $prefixo = $this->prefixoModalidadePeriodo($periodo);
            if ($prefixo !== null) {
                $modalidadesNova[$prefixo] = true;
            }
        }

        $periodos = $periodosExistentes;
        $inserir = array_values(array_filter($periodosNova, 'is_array'));

        if ($modalidadesNova !== []) {
            $ondeInserir = null;
            $periodos = [];

            foreach ($periodosExistentes as $periodo) {
                if (! is_array($periodo)) {
                    continue;
                }

                $prefixo = $this->prefixoModalidadePeriodo($periodo);
                if ($prefixo !== null && isset($modalidadesNova[$prefixo])) {
                    if ($ondeInserir === null) {
                        $ondeInserir = count($periodos);
                    }

                    continue;
                }

                $periodos[] = $periodo;
            }

            array_splice($periodos, $ondeInserir ?? count($periodos), 0, $inserir);
        } else {
            $periodos = [...$periodos, ...$inserir];
        }

        $modalidades = array_values(array_unique(array_filter([
            $existente['fields']['modalidade'] ?? null,
            $nova['fields']['modalidade'] ?? null,
        ], fn ($valor): bool => $valor !== null && $valor !== '')));

        $consolidacao = $nova['fields']['consolidacao'] ?? null;
        if ($consolidacao === null) {
            $consolidacao = $existente['fields']['consolidacao'] ?? null;
        }

        return [
            'fields' => [
                'quantidade_parcelamentos' => count($periodos),
                'modalidade' => $modalidades === [] ? null : implode('; ', $modalidades),
                'consolidacao' => $consolidacao,
            ],
            'due_on' => null,
            'periods' => $periodos,
            'cause' => $periodos === [] ? 'sem_parcelamento' : null,
        ];
    }

    /**
     * Projeções derivadas que nascem da mesma resposta de um serviço direct,
     * sem segunda chamada — hoje só a caixa postal e-CAC alimenta caixas
     * filtradas por assunto.
     *
     * @param  array<string, mixed>  $directProjection
     * @return array<string, array<string, mixed>>
     */
    public function derived(string $idServico, array $directProjection): array
    {
        if ($idServico === 'MSGCONTRIBUINTE61') {
            return [
                'caixas-postais/fgts-digital' => $this->caixaPostalFiltrada($directProjection, 'caixas-postais/fgts-digital'),
                'caixas-postais/det' => $this->caixaPostalFiltrada($directProjection, 'caixas-postais/det'),
            ];
        }

        if ($idServico === 'RELATORIOSITFIS92') {
            $fields = is_array($directProjection['fields'] ?? null) ? $directProjection['fields'] : [];

            return [
                'situacao-fiscal/certidoes' => [
                    'fields' => [
                        'certidao' => $fields['certidao'] ?? null,
                        'emissao' => $fields['emissao'] ?? null,
                        'validade' => $fields['validade'] ?? null,
                    ],
                    'due_on' => $directProjection['due_on'] ?? null,
                    'cause' => $directProjection['cause'] ?? null,
                ],
            ];
        }

        return [];
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
     * A resposta é uma lista de `Debito` — período, tributo, valor, ente e
     * situação — por inscrição em dívida ativa no ano pedido. O painel guarda
     * a contagem em `divida_ativa` e usa `contam_debitos` quando há ao menos
     * um item; lista vazia é contribuinte sem inscrição no ano, não erro.
     *
     * @return array{fields: array<string, int>, due_on: null, periods: list<array{period: string, declared_at: null, rectified: false, slip_number: null, slip_issued_at: null, due_on: null, slip_paid: null}>, cause: ?string}
     */
    private function dividaAtiva(mixed $dados): array
    {
        $debitos = is_array($dados) ? $dados : [];

        if ($debitos !== [] && ! array_is_list($debitos)) {
            $debitos = [$debitos];
        }

        $linhas = [];

        foreach ($debitos as $debito) {
            if (! is_array($debito)) {
                continue;
            }

            $pa = preg_replace('/\D/', '', (string) ($debito['periodoApuracao'] ?? ''));

            $linhas[] = [
                'period' => strlen($pa) === 6 ? substr($pa, 0, 4).'-'.substr($pa, 4, 2) : (string) $pa,
                'declared_at' => null,
                'rectified' => false,
                'slip_number' => null,
                'slip_issued_at' => null,
                'due_on' => null,
                'slip_paid' => null,
            ];
        }

        $quantidade = count($linhas);

        return [
            'fields' => ['divida_ativa' => $quantidade],
            'due_on' => null,
            'periods' => $linhas,
            'cause' => $quantidade > 0 ? 'contam_debitos' : null,
        ];
    }

    /**
     * O `CONSXMLDECLARACAO38` devolve o XML da declaração em
     * `XMLStringBase64`. A projeção decodifica só o suficiente para preencher
     * as colunas do painel — recibo, receitas somadas, FGTS 1718 — e descarta
     * o documento. O prazo legal não vem no XML de saída publicado; `due_on`
     * fica nulo como no PGDAS, em vez de uma data calculada.
     *
     * @return array{fields: array<string, string|float|null>, due_on: null, periods: list<array{period: string, declared_at: ?string, rectified: bool, slip_number: null, slip_issued_at: null, due_on: null, slip_paid: null}>, cause: ?string}
     */
    private function dctfweb(mixed $dados): array
    {
        $xml = $this->xmlDctfDecodificado($dados);

        if ($xml === null) {
            return [
                'fields' => [
                    'gi_declaracao' => null,
                    'receitas' => null,
                    'valor_apurado_1718' => null,
                ],
                'due_on' => null,
                'periods' => [],
                'cause' => 'sem_declaracao',
            ];
        }

        $dom = new \DOMDocument;
        if (@$dom->loadXML($xml) === false) {
            return [
                'fields' => [
                    'gi_declaracao' => null,
                    'receitas' => null,
                    'valor_apurado_1718' => null,
                ],
                'due_on' => null,
                'periods' => [],
                'cause' => 'sem_declaracao',
            ];
        }

        $xpath = new \DOMXPath($dom);
        $recibo = $this->textoXml($xpath, 'numRecibo');
        $perApuracao = preg_replace('/\D/', '', $this->textoXml($xpath, 'perApuracao'));
        $retificacao = (int) ($this->textoXml($xpath, 'indRetificacao') ?: '0');

        $periodo = match (strlen($perApuracao)) {
            6 => substr($perApuracao, 2, 4).'-'.substr($perApuracao, 0, 2),
            4 => substr($perApuracao, 0, 4),
            default => $perApuracao !== '' ? $perApuracao : null,
        };

        $receitas = 0.0;
        $fgts1718 = null;

        foreach ($xpath->query('//*[local-name()="CreditoTributarioApurado"]') ?: [] as $credito) {
            if (! $credito instanceof \DOMElement) {
                continue;
            }

            $codigo = $this->textoFilho($credito, 'codReceita');
            $valorCredito = $this->decimalXml($this->textoFilho($credito, 'vlTotalCred'));

            if ($valorCredito !== null) {
                $receitas += $valorCredito;
            }

            if ($codigo === '1718' || str_ends_with($codigo, '1718')) {
                $valorDebito = $this->decimalXml($this->textoFilho($credito, 'vlTotalDeb'))
                    ?? $valorCredito
                    ?? $this->decimalXml($this->textoFilho($credito, 'ctValor'));

                if ($valorDebito !== null) {
                    $fgts1718 = $valorDebito;
                }
            }
        }

        foreach ($xpath->query('//*[local-name()="DebitoTributarioApurado"]') ?: [] as $debito) {
            if (! $debito instanceof \DOMElement) {
                continue;
            }

            $codigo = $this->textoFilho($debito, 'codReceita');

            if ($codigo !== '1718' && ! str_ends_with($codigo, '1718')) {
                continue;
            }

            $valor = $this->decimalXml($this->textoFilho($debito, 'vlTotalDeb'))
                ?? $this->decimalXml($this->textoFilho($debito, 'ctValor'));

            if ($valor !== null) {
                $fgts1718 = $valor;
            }
        }

        $linhas = $periodo === null ? [] : [[
            'period' => $periodo,
            'declared_at' => null,
            'rectified' => $retificacao > 1,
            'slip_number' => null,
            'slip_issued_at' => null,
            'due_on' => null,
            'slip_paid' => null,
        ]];

        return [
            'fields' => [
                'gi_declaracao' => $recibo === '' ? null : $recibo,
                'receitas' => $receitas > 0 ? round($receitas, 2) : null,
                'valor_apurado_1718' => $fgts1718,
            ],
            'due_on' => null,
            'periods' => $linhas,
            'cause' => $linhas === [] ? 'sem_declaracao' : null,
        ];
    }

    /**
     * A lista de DEFIS transmitidas no período não decadente. Cada
     * `anoCalendario` vira um período; quando há mais de uma transmissão no
     * mesmo ano, prevalece a `dataHora` mais recente — o mesmo critério do
     * PGDAS para várias operações no mesmo PA.
     *
     * @return array{fields: array<string, string|null>, due_on: null, periods: list<array{period: string, declared_at: ?string, rectified: bool, slip_number: null, slip_issued_at: null, due_on: null, slip_paid: null}>, cause: ?string}
     */
    private function defis(mixed $dados): array
    {
        $dados = is_array($dados) ? $dados : [];
        $itens = array_is_list($dados)
            ? $dados
            : (is_array($dados['declaracoes'] ?? null) ? $dados['declaracoes'] : []);

        $porAno = [];
        $gi = null;
        $giInstante = null;

        foreach ($itens as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ano = (string) ($item['anoCalendario'] ?? '');
            $instante = $this->instante($item['dataHora'] ?? null);
            $tipo = (string) ($item['tipo'] ?? '');
            $id = isset($item['idDefis']) ? (string) $item['idDefis'] : null;

            if ($ano === '') {
                continue;
            }

            $retificada = in_array($tipo, ['2', '4'], true);

            if (! isset($porAno[$ano]) || $this->comparaInstante($instante, $porAno[$ano]['declared_at']) > 0) {
                $porAno[$ano] = [
                    'period' => $ano,
                    'declared_at' => $instante,
                    'rectified' => $retificada,
                    'slip_number' => null,
                    'slip_issued_at' => null,
                    'due_on' => null,
                    'slip_paid' => null,
                ];
            } elseif ($retificada) {
                $porAno[$ano]['rectified'] = true;
            }

            if ($id !== null && $this->comparaInstante($instante, $giInstante) > 0) {
                $gi = $id;
                $giInstante = $instante;
            }
        }

        krsort($porAno, SORT_NUMERIC);
        $linhas = array_values($porAno);

        return [
            'fields' => ['gi_declaracao' => $gi],
            'due_on' => null,
            'periods' => $linhas,
            'cause' => $linhas === [] ? 'sem_declaracao' : null,
        ];
    }

    /**
     * O XML bruto a partir do envelope `XMLStringBase64`, sem persistir o
     * base64 na projeção.
     */
    private function xmlDctfDecodificado(mixed $dados): ?string
    {
        if (is_string($dados)) {
            $base64 = $dados;
        } elseif (is_array($dados)) {
            $base64 = (string) ($dados['XMLStringBase64'] ?? $dados['xmlStringBase64'] ?? '');
        } else {
            return null;
        }

        $base64 = preg_replace('/\s+/', '', $base64) ?? '';

        if ($base64 === '') {
            return null;
        }

        $xml = base64_decode($base64, true);

        return $xml === false ? null : $xml;
    }

    private function textoXml(\DOMXPath $xpath, string $localName): string
    {
        $nos = $xpath->query('//*[local-name()="'.$localName.'"]');

        if ($nos === false || $nos->length === 0) {
            return '';
        }

        return trim($nos->item(0)?->textContent ?? '');
    }

    private function textoFilho(\DOMElement $elemento, string $localName): string
    {
        foreach ($elemento->childNodes as $filho) {
            if ($filho instanceof \DOMElement && $filho->localName === $localName) {
                return trim($filho->textContent);
            }
        }

        return '';
    }

    private function decimalXml(string $texto): ?float
    {
        $texto = trim(str_replace(',', '.', $texto));

        if ($texto === '' || ! is_numeric($texto)) {
            return null;
        }

        return (float) $texto;
    }

    /**
     * Lista de pedidos (`PEDIDOSPARC*`). Cada parcelamento vira um período;
     * consolidação só aparece em `OBTERPARC*` — aqui fica nula.
     *
     * @return array{fields: array<string, string|int|null>, due_on: null, periods: list<array<string, mixed>>, cause: ?string}
     */
    private function pedidosParcelamento(mixed $dados, string $idServico): array
    {
        $dados = is_array($dados) ? $dados : [];
        $lista = is_array($dados['parcelamentos'] ?? null) ? $dados['parcelamentos'] : [];

        $modalidade = $this->modalidadeDePedidos($idServico);
        $linhas = [];

        foreach ($lista as $item) {
            if (! is_array($item)) {
                continue;
            }

            $numero = $item['numero'] ?? null;

            if ($numero === null) {
                continue;
            }

            $linhas[] = [
                'period' => $modalidade.'/'.(string) $numero,
                'declared_at' => $this->data($item['dataDoPedido'] ?? null),
                'rectified' => false,
                'slip_number' => null,
                'slip_issued_at' => null,
                'due_on' => null,
                'slip_paid' => null,
                'situacao' => isset($item['situacao']) ? (string) $item['situacao'] : null,
            ];
        }

        return [
            'fields' => [
                'quantidade_parcelamentos' => count($linhas),
                'modalidade' => $modalidade,
                'consolidacao' => null,
            ],
            'due_on' => null,
            'periods' => $linhas,
            'cause' => $linhas === [] ? 'sem_parcelamento' : null,
        ];
    }

    private function modalidadeDePedidos(string $idServico): string
    {
        return match ($idServico) {
            'PEDIDOSPARC163' => 'PARCSN ordinário',
            'PEDIDOSPARC173' => 'PARCSN especial',
            'PEDIDOSPARC183' => 'PERT-SN',
            'PEDIDOSPARC193' => 'RELP-SN',
            default => 'Parcelamento',
        };
    }

    /**
     * @param  array<string, mixed>  $periodo
     */
    private function prefixoModalidadePeriodo(array $periodo): ?string
    {
        $rotulo = $periodo['period'] ?? null;
        if (! is_string($rotulo) || ! str_contains($rotulo, '/')) {
            return null;
        }

        return explode('/', $rotulo, 2)[0];
    }

    private function caixaPostal(mixed $dados): array
    {
        $dados = is_array($dados) ? $dados : [];
        $bloco = $dados['conteudo'] ?? $dados;
        $bloco = $this->primeiroObjeto($bloco);

        $mensagens = is_array($bloco['listaMensagens'] ?? null) ? $bloco['listaMensagens'] : [];
        $maisPaginas = (string) ($bloco['indicadorUltimaPagina'] ?? '') === 'N';

        return $this->projecaoCaixaPostal($this->stubsCaixaPostal($mensagens), $maisPaginas);
    }

    /**
     * Recorte da projeção e-CAC para uma caixa derivada (FGTS Digital ou DET).
     *
     * @param  array<string, mixed>  $projecaoEcac
     * @return array{fields: array<string, int|string|bool|null>, messages: list<array<string, mixed>>, cause: null}
     */
    private function caixaPostalFiltrada(array $projecaoEcac, string $slug): array
    {
        $mensagens = is_array($projecaoEcac['messages'] ?? null) ? $projecaoEcac['messages'] : [];
        $filtradas = array_values(array_filter(
            $mensagens,
            fn (array $mensagem): bool => $this->assuntoCaixaPostalDerivada(
                $slug,
                (string) ($mensagem['assunto'] ?? ''),
            ),
        ));

        $maisPaginas = (bool) ($projecaoEcac['fields']['mais_paginas'] ?? false);

        return $this->projecaoCaixaPostal($filtradas, $maisPaginas);
    }

    /**
     * @param  list<array<string, mixed>>  $stubs
     * @return array{fields: array<string, int|string|bool|null>, messages: list<array<string, mixed>>, cause: null}
     */
    private function projecaoCaixaPostal(array $stubs, bool $maisPaginas): array
    {
        $naoLidas = 0;
        $ultima = null;

        foreach ($stubs as $mensagem) {
            if (($mensagem['unread'] ?? false) === true) {
                $naoLidas++;
            }

            $recebida = $mensagem['received_at'] ?? null;
            if (is_string($recebida) && ($ultima === null || $recebida > $ultima)) {
                $ultima = $recebida;
            }
        }

        return [
            'fields' => [
                'nao_lidas' => $naoLidas,
                'ultima' => $ultima,
                'mais_paginas' => $maisPaginas,
            ],
            'messages' => $stubs,
            'cause' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $mensagens
     * @return list<array{id: int, assunto: string, received_at: ?string, lida_em: ?string, ciencia_em: ?string, prazo_limite: ?string, unread: bool}>
     */
    private function stubsCaixaPostal(array $mensagens): array
    {
        $stubs = [];

        foreach ($mensagens as $mensagem) {
            if (! is_array($mensagem)) {
                continue;
            }

            // `indicadorLeitura` é o flag de leitura — a tabela do provedor
            // rotula a coluna vizinha com a mesma descrição, mas o exemplo
            // mostra `dataLeitura` preenchida onde este flag vale `1`.
            $lida = (string) ($mensagem['indicadorLeitura'] ?? '') === '1';

            $recebida = $this->data($mensagem['dataEnvio'] ?? null);

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

        return $stubs;
    }

    /**
     * O provedor não publica códigos de assunto para FGTS Digital ou DET na
     * caixa e-CAC — o filtro é textual sobre `assuntoModelo` já resolvido.
     */
    private function assuntoCaixaPostalDerivada(string $slug, string $assunto): bool
    {
        $normalizado = mb_strtoupper($assunto, 'UTF-8');

        return match ($slug) {
            'caixas-postais/fgts-digital' => str_contains($normalizado, 'FGTS DIGITAL')
                || (str_contains($normalizado, 'FGTS') && str_contains($normalizado, 'DIGITAL')),
            'caixas-postais/det' => str_contains($normalizado, 'DOMICÍLIO ELETRÔNICO TRABALHISTA')
                || str_contains($normalizado, 'DOMICILIO ELETRONICO TRABALHISTA')
                || preg_match('/\bDET\b/u', $assunto) === 1,
            default => false,
        };
    }

    /**
     * Documentos de arrecadação pagos (`PAGAMENTOS71`): metadados por
     * documento, sem PDF — a emissão (`COMPARRECADACAO72`) fica fora do sync.
     *
     * @return array{fields: array<string, int|string|bool|null>, due_on: null, periods: list<array<string, mixed>>, cause: ?string}
     */
    private function pagtoweb(mixed $dados): array
    {
        $documentos = $this->documentosPagamento($dados);
        $linhas = [];
        $ultimaArrecadacao = null;
        $tamanhoPagina = 100;

        foreach ($documentos as $documento) {
            if (! is_array($documento)) {
                continue;
            }

            $arrecadacao = $this->dataIso8601($documento['dataArrecadacao'] ?? null);
            if ($arrecadacao !== null && ($ultimaArrecadacao === null || $arrecadacao > $ultimaArrecadacao)) {
                $ultimaArrecadacao = $arrecadacao;
            }

            $tipo = is_array($documento['tipo'] ?? null)
                ? (string) ($documento['tipo']['descricaoAbreviada'] ?? $documento['tipo']['descricao'] ?? '')
                : (string) ($documento['tipo'] ?? '');

            $linhas[] = [
                'period' => $this->periodoPagamento($documento['periodoApuracao'] ?? null),
                'declared_at' => $arrecadacao,
                'rectified' => false,
                'slip_number' => isset($documento['numeroDocumento']) ? (string) $documento['numeroDocumento'] : null,
                'slip_issued_at' => null,
                'due_on' => $this->dataIso8601($documento['dataVencimento'] ?? null),
                'slip_paid' => true,
                'tipo_documento' => $tipo === '' ? null : $tipo,
                'valor_total' => isset($documento['valorTotal']) ? (float) $documento['valorTotal'] : null,
            ];
        }

        usort(
            $linhas,
            fn (array $a, array $b): int => ($b['declared_at'] ?? '') <=> ($a['declared_at'] ?? ''),
        );

        return [
            'fields' => [
                'quantidade' => count($linhas),
                'ultima' => $ultimaArrecadacao,
                'mais_paginas' => count($linhas) >= $tamanhoPagina,
            ],
            'due_on' => null,
            'periods' => $linhas,
            'cause' => $linhas === [] ? 'sem_pagamentos' : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documentosPagamento(mixed $dados): array
    {
        if (! is_array($dados)) {
            return [];
        }

        if (array_is_list($dados)) {
            return array_values(array_filter($dados, 'is_array'));
        }

        foreach (['documentos', 'documentoArrecadacaoLista', 'listaDocumentos'] as $chave) {
            if (is_array($dados[$chave] ?? null)) {
                $lista = $dados[$chave];

                return array_values(array_filter($lista, 'is_array'));
            }
        }

        if (isset($dados['numeroDocumento'])) {
            return [$dados];
        }

        return [];
    }

    private function periodoPagamento(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        if ($texto === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})/', $texto, $achado) === 1) {
            return $achado[1].'-'.$achado[2];
        }

        $digitos = preg_replace('/\D/', '', $texto);

        return strlen($digitos) >= 6
            ? substr($digitos, 0, 4).'-'.substr($digitos, 4, 2)
            : null;
    }

    /**
     * Data contábil ISO (`AAAA-MM-DDTHH:…`) ou `AAAAMMDD` do provedor.
     */
    private function dataIso8601(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));

        if ($texto !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $texto, $achado) === 1) {
            return $achado[1];
        }

        return $this->data($valor);
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

    /**
     * O `RELATORIOSITFIS92` devolve só `pdf` em base64. A projeção decodifica
     * o texto do documento para certidão, emissão, validade e situação fiscal,
     * e descarta o binário — o mesmo parse alimenta a linha derivada de
     * certidões via `SerproSitfisMonitoringPublisher`.
     *
     * @return array{fields: array<string, string|null>, due_on: ?string, cause: ?string}
     */
    private function sitfis(mixed $dados): array
    {
        $dados = is_array($dados) ? $dados : [];
        $pdf = (string) ($dados['pdf'] ?? '');

        if ($pdf === '') {
            return [
                'fields' => [
                    'certidao' => null,
                    'emissao' => null,
                    'validade' => null,
                    'situacao_fiscal' => null,
                ],
                'due_on' => null,
                'cause' => 'sem_relatorio',
            ];
        }

        $texto = $this->sitfisPdfText->extract($pdf);

        if ($texto === null) {
            return [
                'fields' => [
                    'certidao' => null,
                    'emissao' => null,
                    'validade' => null,
                    'situacao_fiscal' => null,
                ],
                'due_on' => null,
                'cause' => 'relatorio_ilegivel',
            ];
        }

        $extraido = $this->textoSitfis($texto);

        return [
            'fields' => [
                'certidao' => $extraido['certidao'],
                'emissao' => $extraido['emissao'],
                'validade' => $extraido['validade'],
                'situacao_fiscal' => $extraido['situacao_fiscal'],
            ],
            'due_on' => $extraido['validade'],
            'cause' => $extraido['certidao'] === null && $extraido['situacao_fiscal'] === null
                ? 'relatorio_ilegivel'
                : null,
        ];
    }

    /**
     * @return array{certidao: ?string, emissao: ?string, validade: ?string, situacao_fiscal: ?string}
     */
    private function textoSitfis(string $texto): array
    {
        $certidao = $this->captura($texto, '/Certid[aã]o(?:\s+Negativa)?[^0-9]{0,40}([0-9][0-9.\-/]{8,}[0-9X])/iu')
            ?? $this->captura($texto, '/N[úu]mero(?:\s+da\s+Certid[aã]o)?[^0-9]{0,20}([0-9][0-9.\-/]{8,}[0-9X])/iu');

        $emissao = $this->dataBr($this->captura($texto, '/Emiss[aã]o[^0-9]{0,20}(\d{2}\/\d{2}\/\d{4})/iu'));
        $validade = $this->dataBr($this->captura($texto, '/Validade[^0-9]{0,20}(\d{2}\/\d{2}\/\d{4})/iu'));

        $situacao = $this->captura($texto, '/Situa[cç][aã]o\s+Fiscal[^A-Za-zÀ-ú]{0,10}([A-Za-zÀ-ú\s]{3,40})/iu');
        $situacao = $situacao === null ? null : trim(preg_replace('/\s+/u', ' ', $situacao) ?? '');

        return [
            'certidao' => $certidao,
            'emissao' => $emissao,
            'validade' => $validade,
            'situacao_fiscal' => $situacao === '' ? null : $situacao,
        ];
    }

    private function captura(string $texto, string $padrao): ?string
    {
        if (! preg_match($padrao, $texto, $match)) {
            return null;
        }

        $valor = trim($match[1]);

        return $valor === '' ? null : $valor;
    }

    private function dataBr(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (! preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $valor, $partes)) {
            return null;
        }

        return $partes[3].'-'.$partes[2].'-'.$partes[1];
    }
}
