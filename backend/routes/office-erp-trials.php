<?php

use App\Http\Controllers\Api\V1\Erp\ErpTrialController;
use Illuminate\Support\Facades\Route;

Route::get('/erp/trials/{code}', [ErpTrialController::class, 'show'])
    ->whereUuid('code')
    ->name('erp.trials.show');
