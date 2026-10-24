<?php

use App\Http\Controllers\Api\V1\Erp\ErpCompanyInitializationController;
use Illuminate\Support\Facades\Route;

Route::post('/company/initialization/preview', [ErpCompanyInitializationController::class, 'preview'])->name('erp.company.initialization.preview');
Route::post('/company/initialization/apply', [ErpCompanyInitializationController::class, 'apply'])->name('erp.company.initialization.apply');
