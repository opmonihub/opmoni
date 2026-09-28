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

// A volta atrás é consulta pontual ao CNPJ, uma por posição pendente, e por
// isso ela é uma passagem da noite e não um fluxo: uma vez ao dia, no fuso
// configurado, com a mesma proteção de sobreposição da entrada de cima. A trava
// por cliente e fonte continua sendo a que serializa o mesmo CNPJ entre os dois
// caminhos, e é a mesma chave.
Schedule::command('fiscal:reconcile')
    ->dailyAt(sprintf('%02d:00', (int) config('fiscal.reconcile_hour')))
    ->timezone((string) config('fiscal.reconcile_timezone'))
    ->withoutOverlapping();
