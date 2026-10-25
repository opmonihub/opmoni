<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpContextController;
use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpOperationController;
use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpSalesPlanController;
use App\Http\Middleware\ResolveOfficeErpCommercial;
use Illuminate\Support\Facades\Route;

Route::prefix('erp-commercial')->middleware(ResolveOfficeErpCommercial::class)->group(function (): void {
    Route::get('/context', OfficeErpContextController::class)->name('office-erp-commercial.context');
    Route::get('/operations/{key}', OfficeErpOperationController::class)->name('office-erp-commercial.operations.lookup');
    Route::get('/plans', [OfficeErpSalesPlanController::class, 'index'])->name('office-erp-commercial.plans.index');
    Route::post('/plans', [OfficeErpSalesPlanController::class, 'store'])->name('office-erp-commercial.plans.store');
    Route::get('/plans/{plan}', [OfficeErpSalesPlanController::class, 'show'])->whereNumber('plan')->name('office-erp-commercial.plans.show');
    Route::post('/plans/{plan}/revisions', [OfficeErpSalesPlanController::class, 'revise'])->whereNumber('plan')->name('office-erp-commercial.plans.revise');
    Route::post('/plans/{plan}/archive', [OfficeErpSalesPlanController::class, 'archive'])->whereNumber('plan')->name('office-erp-commercial.plans.archive');
});
