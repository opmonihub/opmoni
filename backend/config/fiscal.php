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

    /*
     * Quando a volta atrás roda. Uma vez ao dia, fora do expediente, e no fuso
     * dela: a reconciliação é consulta pontual ao CNPJ — orçamento que o fisco
     * conta por hora — e a janela dela é a noite, quando ninguém está esperando
     * documento. O fuso é escrito porque a hora da agenda é do fuso dela:
     * `0 2 * * *` em São Paulo são cinco da manhã no fuso do servidor (UTC), e
     * é assim que `schedule:list` mostra a entrada quando ninguém pede outro
     * fuso.
     *
     * Sem `env()` de propósito, como `reconcile_max_attempts`: hora e fuso são a
     * janela que o teste de agenda fixa, e uma variável de ambiente os mudaria
     * sem que ninguém revisasse a mudança.
     */
    'reconcile_hour' => 2,

    'reconcile_timezone' => 'America/Sao_Paulo',

    /*
     * Um bloco por serviço de distribuição, e as chaves são todas parâmetros do
     * serviço: namespace do WSDL, namespace e versão do payload, método, ação
     * SOAP e o elemento que embrulha o payload (`nfeDadosMsg`, `cteDadosMsg`).
     * Nenhum conector monta corpo — ele lê o bloco dele e entrega ao envelope.
     *
     * `xsd_service` é o diretório dos schemas locais em `resources/xsd/`, e a
     * versão vem da chave `version` acima de propósito: a versão do XSD que
     * valida o corpo é a mesma que vai no atributo `versao` dele, então as duas
     * não podem divergir. Um XSD de outra versão aceitaria um corpo que o
     * serviço rejeitaria, que é a rejeição mais cara de evitar.
     */
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
            'xsd_service' => 'nfe',
        ],

        /*
         * ⚠️ ESTE BLOCO NÃO FOI VERIFICADO DESTE CHECKOUT.
         *
         * URL de produção, URL de homologação, ação SOAP, método, namespace do
         * payload e versão `1.00` foram **transcritos de um exemplo de terceiro
         * testado em produção**, e não de uma chamada feita a partir daqui: este
         * repositório não falou com o serviço de CT-e uma vez sequer. O manual
         * publicado descreve `consNSU` e a existência de `consChCTe` não é o que
         * este código assume (ver `CteDistributionConnector::fetchByChave()`), e
         * o pacote oficial de schemas do CT-e (`PL_CTeDistDFe_100`) também não
         * está versionado aqui — o XSD local do CT-e é uma redução transcrita, e
         * o cabeçalho dele diz o mesmo.
         *
         * Uma entrada de configuração que parece fato verificado é uma mentira
         * que vai parar num serviço nacional. Antes de qualquer uso em produção
         * esses valores precisam de um canário de um único cliente, com
         * autorização manual, conferindo o `cStat` da resposta e sem expor
         * material de certificado.
         */
        'cte_distribuicao' => [
            'producao' => 'https://www1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx',
            'homologacao' => 'https://hom1.cte.fazenda.gov.br/CTeDistribuicaoDFe/CTeDistribuicaoDFe.asmx',
            'namespace' => 'http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe',
            'payload_namespace' => 'http://www.portalfiscal.inf.br/cte',
            'version' => '1.00',
            'method' => 'cteDistDFeInteresse',
            'soap_action' => 'http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe/cteDistDFeInteresse',
            'holder' => 'cteDadosMsg',
            'xsd_service' => 'cte',
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
