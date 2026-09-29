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

        // `CONSULTASITUACAODTE111` tem path e versão na fixture gravada
        // `dte-consultar-situacao.json`. Nenhuma obrigação do mapa o consome
        // ainda — a entrada existe para a leitura já estar pronta quando a
        // projeção da situação DTE for implementada.
        'CONSULTASITUACAODTE111' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
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
        ],
        'mei' => [
            'category' => 'direct',
            'service' => 'PGMEI/DIVIDAATIVA24',
            'procuracao' => null,
            'derived_from' => null,
            'sync_enabled' => false,
        ],
        'dctfweb' => [
            'category' => 'direct',
            'service' => 'DCTFWEB/CONSXMLDECLARACAO38',
            'procuracao' => '00103',
            'derived_from' => null,
            'sync_enabled' => false,
        ],
        'fgts-digital' => [
            'category' => 'derived',
            'service' => 'DCTFWEB/CONSXMLDECLARACAO38',
            'procuracao' => '00103',
            'derived_from' => 'o valor 1718 da declaração DCTFWeb',
            'sync_enabled' => false,
        ],
        'parcelamentos/simples-nacional' => [
            'category' => 'direct',
            'service' => 'PARCSN',
            'procuracao' => '00076+00188',
            'derived_from' => null,
            'sync_enabled' => false,
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
            'service' => 'PERTSN+RELPSN',
            'procuracao' => '00149+10011, 00210+10036',
            'derived_from' => 'os sistemas PERTSN e RELPSN',
            'sync_enabled' => false,
        ],
        'parcelamentos/especiais' => [
            'category' => 'direct',
            'service' => 'PARCSN-ESP',
            'procuracao' => '00125',
            'derived_from' => null,
            'sync_enabled' => false,
        ],
        'situacao-fiscal/relatorio-fiscal' => [
            'category' => 'direct',
            'service' => 'SITFIS/RELATORIOSITFIS92',
            'procuracao' => '00002',
            'derived_from' => null,
            // O relatório é dois passos (`SOLICITARPROTOCOLO91` + esta): a
            // sequência entra junto com o escritor que a consome.
            'sync_enabled' => false,
        ],
        'situacao-fiscal/certidoes' => [
            'category' => 'derived',
            'service' => 'SITFIS/RELATORIOSITFIS92',
            'procuracao' => '00002',
            'derived_from' => 'o relatório SITFIS, que já traz o número negativo, a emissão e a validade',
            'sync_enabled' => false,
        ],
        'situacao-fiscal/comprovantes' => [
            'category' => 'direct',
            'service' => 'PAGTOWEB/PAGAMENTOS71',
            'procuracao' => '00004',
            'derived_from' => null,
            'sync_enabled' => false,
        ],
        'caixas-postais/e-cac' => [
            'category' => 'direct',
            'service' => 'CAIXAPOSTAL/MSGCONTRIBUINTE61',
            'procuracao' => '00006',
            'derived_from' => null,
            'sync_enabled' => true,
        ],
        'caixas-postais/fgts-digital' => [
            'category' => 'derived',
            'service' => 'CAIXAPOSTAL',
            'procuracao' => '00006',
            'derived_from' => 'um filtro por assunto sobre a caixa postal e-CAC',
            'sync_enabled' => false,
        ],
        'caixas-postais/det' => [
            'category' => 'derived',
            'service' => 'CAIXAPOSTAL',
            'procuracao' => '00006',
            'derived_from' => 'um filtro por assunto sobre a caixa postal e-CAC',
            'sync_enabled' => false,
        ],
        'declaracoes/pgdas' => [
            'category' => 'direct',
            'service' => 'PGDASD/CONSDECLARACAO13',
            'procuracao' => '00146',
            'derived_from' => null,
            'sync_enabled' => true,
        ],
        'declaracoes/dctfweb' => [
            'category' => 'direct',
            'service' => 'DCTFWEB/CONSXMLDECLARACAO38',
            'procuracao' => '00103',
            'derived_from' => null,
            'sync_enabled' => false,
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
            'sync_enabled' => false,
        ],
        'declaracoes/dirf' => [
            'category' => 'extinct',
            'service' => null,
            'procuracao' => null,
            'derived_from' => null,
            'sync_enabled' => false,
        ],
    ],
];
