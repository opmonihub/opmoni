<?php

return [
    /*
     * Integra Contador (SERPRO). A URL base de produção e a de demonstração
     * ficam separadas para que uma chamada de teste seja distinguível de uma
     * chamada real nos logs.
     */
    'auth_url' => env('SERPRO_AUTH_URL', 'https://autenticacao.sapi.serpro.gov.br/authenticate'),

    'gateway_url' => env('SERPRO_GATEWAY_URL', 'https://gateway.apiserpro.serpro.gov.br/integra-contador/v1'),

    'trial_gateway_url' => env('SERPRO_TRIAL_GATEWAY_URL', 'https://gateway.apiserpro.serpro.gov.br/integra-contador-trial/v1'),

    /*
     * O ambiente de demonstração publica o próprio bearer na documentação e
     * dispensa certificado. Usado apenas por teste de contrato.
     */
    'trial_token' => env('SERPRO_TRIAL_TOKEN'),

    /*
     * O gateway responde de forma síncrona em até 30s. O padrão fica abaixo
     * desse teto para não empurrar a chamada para o circuit breaker.
     */
    'timeout' => (int) env('SERPRO_TIMEOUT', 25),

    /** Segundos de margem sobre a validade informada antes de considerar o token vencido. */
    'token_margin' => 300,

    'temp_dir' => storage_path('app/private/serpro-tmp'),

    /*
     * A régua de consumo do contratante: o gateway cobra por chamada, e o
     * teto de buscas manuais (10/mês por cliente×documento) mora na validação
     * do endpoint de busca manual — é lá que o estouro pode listar cliente a
     * cliente. `scheduled_run_day` é o dia de fallback da execução agendada
     * para a conta que não configurou agenda por documento em Settings.
     */
    'scheduled_run_day' => (int) env('SERPRO_SCHEDULED_RUN_DAY', 15),

    /*
     * Catálogo do primeiro conjunto de leitura. `path` é o segmento da
     * operação e `versaoSistema` varia por serviço — nunca fixar "1.0".
     */
    'services' => [
        'OBTERPROCURACAO41' => ['path' => 'Consultar', 'versaoSistema' => '1', 'billable' => true],
        'CONSULTARANOSCALENDARIOS102' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'CONSULTAROPCAOREGIME103' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'SOLICITARPROTOCOLO91' => ['path' => 'Apoiar', 'versaoSistema' => '2.0', 'billable' => false],
        'RELATORIOSITFIS92' => ['path' => 'Emitir', 'versaoSistema' => '2.0', 'billable' => true],
        'MSGCONTRIBUINTE61' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],

        /*
         * O detalhe de uma mensagem da caixa postal. Executá-lo **caracteriza
         * ciência da intimação** (art. 23, § 2º, III, do Decreto 70.235/1972),
         * e por isso nenhuma execução o chama: só `SerproMailboxReader`, depois
         * da confirmação explícita do Membro (D19).
         *
         * `idSistema`, `idServico`, `versaoSistema` e o `dados` `{"isn": …}`
         * são da documentação do serviço, lida em 2026-09-29. A página não
         * publica o `path`; `Consultar` é o do serviço irmão da mesma caixa
         * (`MSGCONTRIBUINTE61`) e não foi conferido contra o gateway.
         */
        'MSGDETALHAMENTO62' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'CONSDECLARACAO13' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],

        /*
         * Consulta o XML da DCTFWeb de um período (`categoria`, `anoPA`,
         * `mesPA`). O retorno traz `XMLStringBase64` — documento do
         * contribuinte — e a projeção de monitoramento só extrai metadados
         * do XML decodificado, nunca o base64.
         *
         * Fonte: …/integra-dctfweb/dctfweb/servicos/consultar_xml_declaracao/,
         * lida em 2026-10-04. `path = Consultar` é o do catálogo de serviços
         * (item 13.x, operação Consultar).
         */
        'CONSXMLDECLARACAO38' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],

        /*
         * Lista as DEFIS transmitidas no período não decadente; o pedido leva
         * `dados` vazio. Não confundir com `CONSDECREC144`, que devolve PDF
         * em base64 de uma declaração específica — esse fica fora do sync.
         *
         * Fonte: …/integra-sn/defis/servicos/consultar_declaracoes/, lida em
         * 2026-10-04.
         */
        'CONSDECLARACAO142' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],

        /*
         * Períodos com inscrição em dívida ativa do MEI (`anoCalendario` no
         * pedido). Não exige outorga e-CAC (n/a na tabela serviços × procurações).
         *
         * Fonte: …/integra-mei/pgmei/servicos/consultar_divida_ativa/, lida em
         * 2026-10-04.
         */
        'DIVIDAATIVA24' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],

        /*
         * O envio do termo de autorização assinado, que é o serviço **gratuito**
         * de apoio e o único ponto do gateway que este sistema escreve num
         * documento. Os três valores são do provedor, e cada um tem uma
         * leitura diferente:
         *
         * - `path = Apoiar` é o mesmo caminho de `SOLICITARPROTOCOLO91`, e é a
         *   operação que não consome cota do contratante — por isso
         *   `billable = false`, e é por isso que o termo pode ser emitido sem
         *   custo para a plataforma.
         * - `versaoSistema = 1.0` **não** é o `2.0` do outro serviço do mesmo
         *   `path`. A versão é por serviço e o provedor publica `1.0` para
         *   este; copiar o do vizinho produziria um envelope recusado sem
         *   mensagem que dissesse o porquê.
         * - `idServico` e o `idSistema` ficam em `SerproClient`, porque o
         *   `idSistema` do termo é `AUTENTICAPROCURADOR` e **não** o nome do
         *   sistema do serviço — a entrada aqui existe pelo `path` e pela
         *   versão, e é o mesmo par que as demais chamadas leem.
         *
         * Fonte: `…/integra-contador-gerenciador/autenticaprocurador/servicos/envio_de_xml_assinado/`,
         * lida em 2026-09-28.
         */
        'ENVIOXMLASSINADO81' => ['path' => 'Apoiar', 'versaoSistema' => '1.0', 'billable' => false],

        /*
         * Indicador de adesão ao DTE (`indicadorEnquadramento`); `dados` vazio,
         * CNPJ no envelope. Exige outorga 00050 quando o autor não é o
         * contribuinte. Nenhuma das dezenove obrigações do painel consome este
         * serviço: `situacao-fiscal/*` projeta SITFIS/PAGTOWEB, não enquadramento
         * DTE; criar slug novo exigiria contrato frontend + spec. A fixture
         * `dte-consultar-situacao.json` mantém path/versão verificados.
         *
         * Fonte: …/integra-caixapostal/dte/servicos/obter_indicador_dte/, lida
         * em 2026-10-04.
         */
        'CONSULTASITUACAODTE111' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],

        /*
         * Consulta documentos de arrecadação já pagos (DARF, DAS, DAE, DJE).
         * A emissão do PDF (`COMPARRECADACAO72`) fica fora do sync — o retorno
         * aqui é metadado estruturado, não base64.
         *
         * Fonte: …/integra-pagamento/pagtoweb/servicos/consulta_pagamento/,
         * lida em 2026-10-04. `path = Consultar` é o do catálogo (item 7.1).
         */
        'PAGAMENTOS71' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],

        /*
         * Integra-Parcelamentos — consulta de pedidos (lista) e detalhe. O sync
         * de monitoramento usa só os `PEDIDOSPARC*`, com `dados` vazio; emissão
         * de DAS (`GERARDAS*`) e parcelas para impressão (`PARCELASPARAGERAR*`)
         * ficam fora deste conjunto.
         *
         * Fonte: catálogo de serviços e páginas do Integra-Parcelamentos
         * (serviços de consulta), lidas em 2026-10-04. `path = Consultar` é o
         * tipo publicado.
         */
        'PEDIDOSPARC163' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'OBTERPARC164' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'PEDIDOSPARC173' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'OBTERPARC174' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'PEDIDOSPARC183' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'OBTERPARC184' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'PEDIDOSPARC193' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
        'OBTERPARC194' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
    ],

    /*
     * As dezenove obrigações que o painel mostra, classificadas pelo que o
     * catálogo do SERPRO serve — a mesma lista que
     * `frontend/app/utils/monitoringNav.ts` publica, e o teste de contrato
     * do plano 05 garante que as duas continuam iguais.
     *
     * `service` é `SISTEMA/IDSERVICO` quando a leitura é uma chamada só;
     * `procuracao` é a família (ou as famílias, com `+` para conjunção e `,`
     * para alternativa) que `SerproEligibility` confere antes de cobrar.
     * `sync_enabled = false` não é "não serve": é "o serviço existe, e o
     * `path`/`versaoSistema` ainda não foi conferido no catálogo publicado"
     * — ligar sem isso mandaria a chamada para um caminho chutado. As
     * entradas `unavailable` e `extinct` não têm serviço porque o catálogo
     * não publica nenhum, e nenhuma linha de cliente pode nascer delas.
     */
    'catalogue_revision' => '2026-09',

    'obligations' => [
        'simples-nacional' => [
            'category' => 'direct',
            'service' => 'REGIMEAPURACAO/CONSULTAROPCAOREGIME103',
            'procuracao' => '00060',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'Simples Nacional',
        ],
        'mei' => [
            'category' => 'direct',
            'service' => 'PGMEI/DIVIDAATIVA24',
            'procuracao' => null,
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'MEI',
        ],
        'dctfweb' => [
            'category' => 'direct',
            'service' => 'DCTFWEB/CONSXMLDECLARACAO38',
            'procuracao' => '00103',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'DCTFWeb',
        ],
        'fgts-digital' => [
            'category' => 'derived',
            'service' => 'DCTFWEB/CONSXMLDECLARACAO38',
            'procuracao' => '00103',
            'derived_from' => 'o valor 1718 da declaração DCTFWeb',
            'sync_enabled' => false,
            'label' => 'FGTS Digital',
        ],
        'parcelamentos/simples-nacional' => [
            'category' => 'direct',
            'service' => 'PARCSN/PEDIDOSPARC163',
            'procuracao' => '00076+00188',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'Simples Nacional',
        ],
        'parcelamentos/pgfn' => [
            'category' => 'unavailable',
            'service' => null,
            'procuracao' => null,
            'derived_from' => null,
            'sync_enabled' => false,
        ],
        'parcelamentos/receita-federal' => [
            'category' => 'derived',
            // Duas chamadas (`PEDIDOSPARC183` + `PEDIDOSPARC193`); o job mescla
            // as projeções numa linha só — ver `SerproMonitoringMapper::mesclarPedidosParcelamento`.
            'service' => 'PERTSN/PEDIDOSPARC183+RELPSN/PEDIDOSPARC193',
            'procuracao' => '00149+10011, 00210+10036',
            'derived_from' => 'os sistemas PERTSN e RELPSN',
            'sync_enabled' => true,
            'label' => 'Receita Federal',
        ],
        'parcelamentos/especiais' => [
            'category' => 'direct',
            'service' => 'PARCSN-ESP/PEDIDOSPARC173',
            'procuracao' => '00125',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'Especiais',
        ],
        'situacao-fiscal/relatorio-fiscal' => [
            'category' => 'direct',
            'service' => 'SITFIS/RELATORIOSITFIS92',
            'procuracao' => '00002',
            'derived_from' => null,
            // O relatório é dois passos (`SOLICITARPROTOCOLO91` + esta): a
            // sequência mora em `SerproSitfisSequence`, acionada pelo job.
            'sync_enabled' => true,
            'label' => 'Relatório Fiscal',
        ],
        'situacao-fiscal/certidoes' => [
            'category' => 'derived',
            'service' => 'SITFIS/RELATORIOSITFIS92',
            'procuracao' => '00002',
            'derived_from' => 'o relatório SITFIS, que já traz o número negativo, a emissão e a validade',
            'sync_enabled' => false,
            'label' => 'Certidões',
        ],
        'situacao-fiscal/comprovantes' => [
            'category' => 'direct',
            'service' => 'PAGTOWEB/PAGAMENTOS71',
            'procuracao' => '00004',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'Comprovantes',
        ],
        'caixas-postais/e-cac' => [
            'category' => 'direct',
            'service' => 'CAIXAPOSTAL/MSGCONTRIBUINTE61',
            'procuracao' => '00006',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'e-CAC',
        ],
        'caixas-postais/fgts-digital' => [
            'category' => 'derived',
            'service' => 'CAIXAPOSTAL',
            'procuracao' => '00006',
            'derived_from' => 'um filtro por assunto sobre a caixa postal e-CAC',
            'sync_enabled' => false,
            'label' => 'FGTS Digital',
        ],
        'caixas-postais/det' => [
            'category' => 'derived',
            'service' => 'CAIXAPOSTAL',
            'procuracao' => '00006',
            'derived_from' => 'um filtro por assunto sobre a caixa postal e-CAC',
            'sync_enabled' => false,
            'label' => 'DET',
        ],
        'declaracoes/pgdas' => [
            'category' => 'direct',
            'service' => 'PGDASD/CONSDECLARACAO13',
            'procuracao' => '00146',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'PGDAS',
        ],
        'declaracoes/dctfweb' => [
            'category' => 'direct',
            'service' => 'DCTFWEB/CONSXMLDECLARACAO38',
            'procuracao' => '00103',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'DCTFWeb',
        ],
        'declaracoes/fgts' => [
            'category' => 'unavailable',
            'service' => null,
            'procuracao' => null,
            'derived_from' => null,
            'sync_enabled' => false,
        ],
        'declaracoes/defis' => [
            'category' => 'direct',
            'service' => 'DEFIS/CONSDECLARACAO142',
            'procuracao' => '00146',
            'derived_from' => null,
            'sync_enabled' => true,
            'label' => 'DEFIS',
        ],
        'declaracoes/dirf' => [
            'category' => 'extinct',
            'service' => null,
            'procuracao' => null,
            'derived_from' => null,
            'sync_enabled' => false,
            'label' => 'DIRF',
        ],
    ],

    /*
     * O mapa regime → obrigações da etapa de módulos do cadastro: a sugestão
     * que o GET devolve marcada para o operador conferir. O valor é uma lista
     * de slugs do mapa acima — só `direct` e `derived`, porque o teste do
     * catálogo impede que um slug de `unavailable` ou `extinct` entre aqui —
     * e a regra é da plataforma inteira: duas Accounts com o mesmo regime
     * recebem a mesma sugestão, e nenhuma a personaliza.
     */
    'regime_suggestions' => [
        'simple_national' => [
            'simples-nacional',
            'declaracoes/pgdas',
            'declaracoes/defis',
            'parcelamentos/simples-nacional',
            'caixas-postais/e-cac',
            'situacao-fiscal/relatorio-fiscal',
        ],
        'mei' => [
            'mei',
            'caixas-postais/e-cac',
            'situacao-fiscal/relatorio-fiscal',
        ],
        'presumed_profit' => [
            'dctfweb',
            'declaracoes/dctfweb',
            'fgts-digital',
            'caixas-postais/e-cac',
            'situacao-fiscal/relatorio-fiscal',
        ],
        'actual_profit' => [
            'dctfweb',
            'declaracoes/dctfweb',
            'fgts-digital',
            'caixas-postais/e-cac',
            'situacao-fiscal/relatorio-fiscal',
        ],
        'other' => [
            'caixas-postais/e-cac',
            'situacao-fiscal/relatorio-fiscal',
        ],
        'not_applicable' => [],
    ],
];
