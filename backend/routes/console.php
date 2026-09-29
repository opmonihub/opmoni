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
 * O cão de guarda das execuções: uma passada a cada cinco minutos procura
 * `running` sem progresso há mais de duas vezes o timeout do job. O
 * `withoutOverlapping` impede duas varreduras sobre o mesmo conjunto.
 */
Schedule::command('serpro:fail-abandoned')
    ->everyFiveMinutes()
    ->withoutOverlapping();
