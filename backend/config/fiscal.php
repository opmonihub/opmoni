<?php

return [
    /*
     * `producao` usa o ambiente nacional de produção; `homologacao` usa o de
     * homologação, que é notoriamente vazio para este serviço.
     */
    'environment' => env('FISCAL_ENVIRONMENT', 'producao'),

    'timeout' => (int) env('FISCAL_TIMEOUT', 60),

    /*
     * Quanto tempo vive a trava de captura por cliente e fonte. Derivado do
     * --timeout=120 do worker (docker/queue-entrypoint.sh) com margem: a
     * trava precisa vencer DEPOIS do worker poder matar o job, senão a
     * execução nova começa enquanto a antiga, lenta mas viva, ainda escreve.
     * É o teste de feature quem amarra este valor ao --timeout do entrypoint.
     */
    'lock_ttl' => (int) env('FISCAL_LOCK_TTL', 180),

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

    /*
     * Quantas vezes a reconciliação consulta a mesma lacuna antes de parar com
     * ela. Três tentativas separadas por uma hora é o que distingue "a posição
     * ainda não foi publicada" de "não existe documento nesta posição": depois
     * disso a lacuna continua registrada e visível, mas não custa mais consulta
     * ao CNPJ, que é o recurso que o fisco conta.
     */
    'reconcile_max_attempts' => 3,

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

    /*
     * Cadeia da ICP-Brasil versionada no repositório, e não no `storage`: o
     * conector aponta o `CURLOPT_CAINFO` para cá, e um bundle que o deploy não
     * recebe é uma verificação de TLS que aceita qualquer autoridade. Extensão
     * `.crt` de propósito — o `.gitignore` da raiz esconde `*.pem`, que é
     * formato de chave.
     */
    'ca_bundle' => resource_path('icp-brasil/ca-bundle.crt'),
];
