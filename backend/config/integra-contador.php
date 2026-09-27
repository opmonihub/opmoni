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
    ],
];
