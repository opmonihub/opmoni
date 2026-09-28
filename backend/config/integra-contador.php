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
    ],
];
