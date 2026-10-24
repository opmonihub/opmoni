<?php

use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpOperationNaturePreviewController;
use App\Http\Controllers\Api\V1\Erp\Catalogs\ErpOperationNatureRuleController;
use Illuminate\Support\Facades\Route;

Route::post('/tax-settings/operation-natures/operational-preview', ErpOperationNaturePreviewController::class)->name('erp.operation-natures.operational-preview');
Route::prefix('/tax-settings/operation-natures/{operationNature}')->whereNumber('operationNature')->group(function (): void {
    Route::get('/choices', [ErpOperationNatureRuleController::class, 'choices'])->name('erp.operation-natures.choices.index');
    Route::get('/choices/{choice}', [ErpOperationNatureRuleController::class, 'showChoice'])->whereNumber('choice')->name('erp.operation-natures.choices.show');
    Route::post('/choices', [ErpOperationNatureRuleController::class, 'storeChoice'])->name('erp.operation-natures.choices.store');
    Route::delete('/choices/{choice}', [ErpOperationNatureRuleController::class, 'archiveChoice'])->whereNumber('choice')->name('erp.operation-natures.choices.archive');
    Route::get('/product-exceptions', [ErpOperationNatureRuleController::class, 'exceptions'])->name('erp.operation-natures.product-exceptions.index');
    Route::get('/product-exceptions/{exception}', [ErpOperationNatureRuleController::class, 'showException'])->whereNumber('exception')->name('erp.operation-natures.product-exceptions.show');
    Route::post('/product-exceptions', [ErpOperationNatureRuleController::class, 'storeException'])->name('erp.operation-natures.product-exceptions.store');
    Route::patch('/product-exceptions/{exception}', [ErpOperationNatureRuleController::class, 'updateException'])->whereNumber('exception')->name('erp.operation-natures.product-exceptions.update');
    Route::delete('/product-exceptions/{exception}', [ErpOperationNatureRuleController::class, 'archiveException'])->whereNumber('exception')->name('erp.operation-natures.product-exceptions.archive');
});
