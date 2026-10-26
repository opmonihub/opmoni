<?php

use App\Http\Controllers\Api\V1\Erp\ErpTrialController;
use Illuminate\Support\Facades\Route;

Route::get('/erp/trials/current', [ErpTrialController::class, 'current'])
    ->middleware('auth')
    ->name('erp.trials.current');

Route::post('/erp/trials/{code}/register', [ErpTrialController::class, 'register'])
    ->whereUuid('code')
    ->name('erp.trials.register');

Route::get('/erp/trials/{code}', [ErpTrialController::class, 'show'])
    ->whereUuid('code')
    ->name('erp.trials.show');
