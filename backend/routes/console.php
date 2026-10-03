<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('work:generate-recurrences')->daily();

// `withoutOverlapping` protege a entrada; a trava por cliente e fonte no
// serviço de captura é quem serializa o mesmo CNPJ entre workers.
Schedule::command('fiscal:capture')->hourly()->withoutOverlapping();

/*
 * A entrada de CT-e é uma segunda decisão, e ela nasce desligada.
 *
 * ⚠️ LIGAR `fiscal.cte_scheduled` PRESUME O CANÁRIO DE UM CLIENTE AUTORIZADO E
 * APROVADO. Esta linha é o que transforma um clique em um pedido por cliente, de
 * hora em hora, sozinho, contra o serviço nacional de produção — e os parâmetros
 * desse serviço (URL, ação SOAP, namespace, versão `1.00`) foram transcritos de
 * um exemplo de terceiro e nunca verificados deste checkout. O gate de liberação
 * do plano manda canariar um cliente só, conferindo o `cStat` e sem expor
 * material de certificado; a agenda vem depois disso, nunca antes. A chave é o
 * registro de uma decisão, não um mecanismo de segurança — ela não impede
 * ninguém, e é por isso que a autorização do canário é a proteção real.
 *
 * ⚠️ E LIGAR ESTA CHAVE NÃO ESTENDE O CANÁRIO: ELA O SUBSTITUI POR TODO MUNDO.
 * Esta entrada **não** passa `--client`, então o comando captura a carteira
 * inteira — e não a da conta que autorizou o canário: na agenda nada seta o
 * `CurrentTenant`, que fica `null`, e o escopo por conta não filtra sem uma conta
 * corrente, de modo que a entrada alcança todo cliente capturável de todas as
 * contas. Quem canariou o cliente 1 e liga a chave achando que continua no um
 * está ligando tráfego de hora em hora para a carteira toda, contra um serviço
 * cujos parâmetros ninguém verificou, e é a rejeição repetida que produz o
 * `656`. A correspondência entre "canário aprovado" e "agenda ligada" vale para
 * um cliente, e é a transição que precisa ser uma decisão consciente.
 *
 * Ela é independente de `fiscal.cte_enabled`, que é a porta do botão da tela. Na
 * **captura**, as duas não são conferidas juntas: o comando e o job perguntam ao
 * registro de conectores e nunca a `cte_enabled`, então esta entrada começa
 * tráfego sem consultar aquela chave — furo conhecido, deixado pela revisão que
 * pôs o gate na fronteira HTTP e o nomeou como acompanhamento. Na **volta
 * atrás**, o oposto: `FiscalReconciliation` e a contagem de lacunas do serviço de
 * captura consultam `cte_enabled`, e é ela que decide se a reconciliação consulta
 * e se uma lacuna segurando a posição a segura.
 *
 * Nenhum leitor de `cte_enabled` consulta **esta** chave — nem a captura nem a
 * volta atrás —, e é por isso que a agenda ligada com a outra desligada é um
 * estado possível e é o estado que a reversão de um incidente produz.
 *
 * Entrada própria, e não uma fusão com a de cima: `--source` fixo é o que
 * impede esta agenda de virar uma segunda captura de NF-e por hora.
 */
if (config('fiscal.cte_scheduled', false)) {
    Schedule::command('fiscal:capture --source=cte_distribuicao')
        ->hourly()
        ->withoutOverlapping();
}

// A volta atrás é consulta pontual ao CNPJ, uma por posição pendente, e por
// isso ela é uma passagem da noite e não um fluxo: uma vez ao dia, no fuso
// configurado, com `withoutOverlapping()` como as duas entradas de captura —
// nenhuma das quais é "a de cima", já que a de CT-e só existe com a chave
// ligada. A trava por cliente e fonte continua sendo a que serializa o mesmo
// CNPJ entre os dois caminhos, e é a mesma chave.
Schedule::command('fiscal:reconcile')
    ->dailyAt(sprintf('%02d:00', (int) config('fiscal.reconcile_hour')))
    ->timezone((string) config('fiscal.reconcile_timezone'))
    ->withoutOverlapping();

/*
 * A ressincronização dos resumos manifestados: a ciência da emissão já
 * registrada libera a `consChNFe`, que traz o XML completo que a distribuição
 * ainda não entregou. Consulta pontual como a reconciliação — mesmo teto de
 * 20/h por CNPJ, mesma trava por cliente e fonte —, e por isso uma passada na
 * noite, depois da volta atrás, e não a cada hora: a chave que espera uma hora
 * não perde nada, e a vaga do teto é da recuperação.
 *
 * O gate é o da feature (`manifestacao_enabled`), lido dentro do comando: a
 * agenda registra a entrada, e o serviço decide se a varredura existe. Não há
 * `manifestacao_scheduled` separada porque a decisão que liga a manifestação é
 * a mesma que liga a recuperação dela — uma chave só, como `nfse_enabled`.
 */
Schedule::command('fiscal:resync-manifestacoes')
    ->dailyAt(sprintf('%02d:30', (int) config('fiscal.reconcile_hour')))
    ->timezone((string) config('fiscal.reconcile_timezone'))
    ->withoutOverlapping();

/*
 * A renovação do termo de autorização, uma vez por dia, à uma da manhã no
 * fuso de Brasília.
 *
 * **A hora é a de Brasília e não a do servidor, e isso não é detalhe.** O
 * provedor documenta que o token de autorização fica válido "até a meia-noite
 * do dia seguinte, horário de Brasília": um servidor em UTC rodando `dailyAt`
 * sem fuso dispararia às 22h do dia anterior, renovando um token que ainda
 * valeria por mais duas horas e gastando uma chamada do provedor sem
 * necessidade. Logo depois da meia-noite é a primeira janela em que a
 * renovação é útil de verdade.
 *
 * O `timezone()` vai na cadeia e não em `config('app.timezone')` pelo mesmo
 * motivo: a validade do token é um fato do provedor, e um sistema pode mudar
 * de fuso sem que a janela de renovação mude de hora.
 *
 * `withoutOverlapping` porque a travessia despacha um job por conta, e duas
 * passadas sobrepostas fariam o mesmo documento ser reenviado duas vezes
 * com dois tokens em jogo — o que o índice único de `account_id` impede que
 * vire duas linhas, mas não impede que a segunda gravação vença a primeira.
 */
Schedule::command('serpro:renew-terms')
    ->dailyAt('01:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

/*
 * O oráculo de autorização, uma vez por dia, às duas da manhã no fuso de
 * Brasília — depois da renovação do termo, porque a consulta de procuração
 * precisa do token que ela mantém vivo. Cada chamada é cobrável, e a janela
 * de vinte horas dentro do job é o que impede a rotina de pagar de novo um
 * cliente que acabou de ser cadastrado.
 */
Schedule::command('serpro:refresh-powers')
    ->dailyAt('02:00')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping();

/*
 * O cão de guarda das execuções: uma passada a cada cinco minutos procura
 * `running` sem progresso há mais de duas vezes o timeout do job. O
 * `withoutOverlapping` impede duas varreduras sobre o mesmo conjunto.
 */
Schedule::command('serpro:fail-abandoned')
    ->everyFiveMinutes()
    ->withoutOverlapping();
