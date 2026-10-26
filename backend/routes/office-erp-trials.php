<?php

use App\Http\Controllers\Api\V1\Erp\ErpTrialController;
use Illuminate\Support\Facades\Route;

Route::post('/erp/trials/{code}/register', [ErpTrialController::class, 'register'])
    ->whereUuid('code')
    ->name('erp.trials.register');

Route::middleware('auth')->get('/erp/trials/current', [ErpTrialController::class, 'current'])
    ->name('erp.trials.current');
