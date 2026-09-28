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
