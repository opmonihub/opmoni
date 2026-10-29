<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

require __DIR__.'/business-console.php';

Schedule::command('office-erp:expire-access-deliveries --batch=100')->everyMinute()->withoutOverlapping();
