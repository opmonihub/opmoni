<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
 * Ela é independente de `fiscal.cte_enabled`, que é a porta do botão da tela, e
 * as duas **não** são conferidas juntas: o comando e o job perguntam ao registro
 * de conectores e nunca a `cte_enabled`, então esta entrada começa tráfego sem
 * consultar aquela chave. Furo conhecido, deixado pela revisão que pôs o gate na
 * fronteira HTTP e o nomeou como acompanhamento.
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
// configurado, com a mesma proteção de sobreposição da entrada de cima. A trava
// por cliente e fonte continua sendo a que serializa o mesmo CNPJ entre os dois
// caminhos, e é a mesma chave.
Schedule::command('fiscal:reconcile')
    ->dailyAt(sprintf('%02d:00', (int) config('fiscal.reconcile_hour')))
    ->timezone((string) config('fiscal.reconcile_timezone'))
    ->withoutOverlapping();
