<?php

use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpCfopReferenceController;
use Illuminate\Support\Facades\Route;

Route::get('/tax-settings/cfops', [ErpCfopReferenceController::class, 'index'])->name('erp.cfops.index');
Route::get('/tax-settings/cfops/{code}', [ErpCfopReferenceController::class, 'show'])
    ->where('code', '[0-9]{4}')
    ->name('erp.cfops.show');
