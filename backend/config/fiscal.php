<?php

return [
    /*
     * `producao` usa o ambiente nacional de produção; `homologacao` usa o de
     * homologação, que é notoriamente vazio para este serviço.
     */
    'environment' => env('FISCAL_ENVIRONMENT', 'producao'),

    /*
     * A captura de CT-e por comando do operador — o botão da tela e o
     * `POST /api/fiscal/clients/{id}/capture` — nasce desligada, e é a chave que
     * o canário liga.
     *
     * Ela é diferente de `fiscal.cte_scheduled`, que é a entrada de agenda: o
     * canário é rodado à mão, em um cliente só, e ele precisa poder rodar antes
     * de a agenda existir — essa é a ordem que o gate de liberação exige. Uma
     * chave só para as duas coisas obrigaria a ligar a agenda para rodar o
     * canário, que é o oposto do que se quer. E a agenda **não** consulta esta
     * chave: quem registra a entrada é `routes/console.php`, e o comando e o job
     * de captura nunca chegam aqui.
     *
     * A volta atrás, essa sim, tem a mesma porta: `FiscalReconciliation::run()`
     * pula as lacunas de CT-e com esta chave desligada, sem gastar tentativa e
     * sem derrubar a noite de NF-e. A lacuna de CT-e sobrevive a qualquer chave
     * — ela fica na tabela até ser resolvida ou abandonada —, e o job de
     * reconciliação que já estava na fila é o caminho que a consultaria com as
     * duas chaves desligadas. Ver `isPaused()`.
     *
     * O motivo de a chave existir: os parâmetros do serviço de CT-e (URL, ação
     * SOAP, namespace, versão) foram transcritos de um exemplo de terceiro e
     * nunca verificados deste checkout, e a rejeição repetida de um pedido errado
     * é o que produz o `656` — o bloqueio de consumo indevido que custa uma hora
     * daquele cliente. Ligar isto é ato de quem autorizou o canário, e não
     * DEFAULT de deployment.
     *
     * A chave está fora do `.env.example` (é regra do repositório que nenhuma
     * `FISCAL_*` apareça lá), então a única documentação de que ela existe é este
     * comentário — e a leitura do texto tem de ser honesta com quem a descobre
     * por aqui. `filter_var(..., FILTER_VALIDATE_BOOL)` é o que faz isso: `false`
     * é `false`, `0` é desligado, vazio é desligado, `off` e `no` são desligados,
     * e só `true`, `1`, `on` e `yes` ligam.
     *
     * Uma palavra que **não** está nessa lista é desligada — e essa é a direção
     * que mudou aqui: com o `(bool) env(...)` anterior, qualquer palavra fora do
     * vocabulário do `env()` era ligada. Na prática, `FISCAL_CTE_ENABLED=sim`,
     * que num produto brasileiro é a escrita mais natural que existe, estava
     * ligado e passou a estar desligado; e `off`, `não` ou `desligado` eram
     * ligados e passaram a desligar. Se alguém escrever um valor e não ver
     * efeito nenhum, o valor não estava na lista.
     *
     * Isso é deliberado, e é por isso que a chave não volta a um cast frouxo:
     * o default de desligado é o que segura um valor que ninguém revisou, e o
     * preço de ligar o serviço sem querer — a rejeição repetida de um pedido
     * errado, e a hora de cliente que ela custa — é maior do que o de um valor
     * que ninguém percebeu.
     */
    'cte_enabled' => filter_var(env('FISCAL_CTE_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
     * A entrada de agenda da captura de CT-e — `FISCAL_CTE_SCHEDULED`, lida em
     * `routes/console.php` — é uma segunda chave, separada de `cte_enabled`, e
     * não é um filtro dela.
     *
     * ⚠️ LIGAR ESTA CHAVE PRESUME O CANÁRIO DE UM CLIENTE AUTORIZADO E APROVADO.
     *
     * A diferença entre as duas chaves é a diferença entre um pedido e uma
     * hora: `cte_enabled` libera um clique, `cte_scheduled` faz o servidor
     * mandar um pedido por cliente, de hora em hora, sozinho, contra o serviço
     * nacional de produção. É esta que liga tráfego de verdade, e ela nasce
     * desligada por isso. O gate de liberação do plano manda canariar um
     * cliente só, conferindo o `cStat` e sem expor material de certificado, e a
     * ordem é o canário primeiro: a agenda vem depois do canário, nunca antes.
     *
     * ⚠️ E a agenda não continua o canário, ela o multiplica: a entrada
     * registrada em `routes/console.php` não passa `--client`, e sem ele o
     * comando captura todo cliente capturável de **todas** as contas — na
     * agenda nada seta o `CurrentTenant`, e o escopo por conta não filtra sem
     * uma conta corrente. Ligar esta chave depois de canariar o cliente 1 é, na
     * prática, canariar a carteira inteira de uma vez, e a rejeição repetida é o
     * que produz o `656`.
     *
     * E a chave é o registro de uma decisão, não um mecanismo de segurança: ela
     * não impede ninguém, ela apenas deixa escrito que alguém ligou. A proteção
     * deste caminho é a autorização do canário; o que protege o resto do código
     * é a entrada de agenda não existir enquanto ela não foi ligada. Uma terceira
     * chave aqui seria mais uma posição para alguém errar e nenhuma proteção a
     * mais.
     *
     * A captura é a que **não** confere a outra chave:
     * `CaptureFiscalDocumentsJob` e `FiscalCaptureService` perguntam ao registro
     * de conectores, nunca a `cte_enabled`, então registrar esta agenda começa
     * tráfego sem consultar aquela chave. Isso é um furo conhecido e não um
     * descuido — a revisão que fechou a porta do botão deixou o gate na
     * fronteira HTTP e nomeou isto como acompanhamento. A volta atrás é o
     * caminho que confere a outra chave e não esta (`FiscalReconciliation::
     * isPaused()`), e é a agenda de CT-e ligada com a volta atrás desligada que
     * deixa as lacunas paradas, que é o lado seguro.
     *
     * A leitura do texto é a mesma da chave de cima, e pelo mesmo motivo: a
     * entrada existe ou não existe a partir de uma palavra que alguém escreveu,
     * e essa palavra precisa ser lida como quem a escreveu a quis.
     */
    'cte_scheduled' => filter_var(env('FISCAL_CTE_SCHEDULED', false), FILTER_VALIDATE_BOOL),

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
