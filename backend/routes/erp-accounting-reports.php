<?php

use App\Http\Controllers\Api\V1\Erp\Accounting\ErpAccountingDeliveryController;
use App\Http\Controllers\Api\V1\Erp\Reports\ErpReportController;
use Illuminate\Support\Facades\Route;

Route::get('/accounting-deliveries', [ErpAccountingDeliveryController::class, 'index'])
    ->name('erp.accounting-deliveries.index');
Route::post('/accounting-deliveries/{delivery}/retry', [ErpAccountingDeliveryController::class, 'retry'])
    ->whereNumber('delivery')
    ->name('erp.accounting-deliveries.retry');

Route::get('/reports/exports/{export}', [ErpReportController::class, 'showExport'])
    ->whereNumber('export')
    ->name('erp.reports.exports.show');
Route::get('/reports/{category}', [ErpReportController::class, 'show'])
    ->where('category', 'sales|purchases|stock|financial|fiscal|overview')
    ->name('erp.reports.show');
Route::post('/reports/{category}/exports', [ErpReportController::class, 'storeExport'])
    ->where('category', 'sales|purchases|stock|financial|fiscal|overview')
    ->name('erp.reports.exports.store');
