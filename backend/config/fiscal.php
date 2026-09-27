<?php

return [
    /*
     * `producao` usa o ambiente nacional de produção; `homologacao` usa o de
     * homologação, que é notoriamente vazio para este serviço.
     */
    'environment' => env('FISCAL_ENVIRONMENT', 'producao'),

    'timeout' => (int) env('FISCAL_TIMEOUT', 60),

    /*
     * Quantos documentos o serviço devolve por lote. O fisco não aceita
     * parametrizar; o valor é informativo e usado nos testes.
     */
    'batch_limit' => 50,

    /*
     * Após este número de dias sem captura bem-sucedida o fisco interrompe a
     * geração de posições sem retroativa, então paramos de consultar e
     * reportamos o histórico como interrompido.
     */
    'continuity_days' => (int) env('FISCAL_CONTINUITY_DAYS', 60),

    'continuity_alert_days' => (int) env('FISCAL_CONTINUITY_ALERT_DAYS', 45),

    /*
     * O fisco bloqueia o CNPJ por uma hora após consumo indevido, e retomar
     * antes disso zera a contagem e reinicia.
     */
    'block_minutes' => (int) env('FISCAL_BLOCK_MINUTES', 60),

    'consulta_hourly_limit' => 20,

    'endpoints' => [
        'nfe_distribuicao' => [
            'producao' => 'https://www1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx',
            'homologacao' => 'https://hom1.nfe.fazenda.gov.br/NFeDistribuicaoDFe/NFeDistribuicaoDFe.asmx',
            'namespace' => 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe',
            'payload_namespace' => 'http://www.portalfiscal.inf.br/nfe',
            'version' => '1.01',
            'method' => 'nfeDistDFeInteresse',
            'soap_action' => 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeDistribuicaoDFe/nfeDistDFeInteresse',
            'holder' => 'nfeDadosMsg',
        ],
    ],

    'ca_bundle' => storage_path('app/icp-brasil/ca-bundle.pem'),
];
