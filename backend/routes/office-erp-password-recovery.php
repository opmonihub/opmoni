<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpPasswordRecoveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('password-recovery')->group(function (): void {
    Route::post('/resolve', [OfficeErpPasswordRecoveryController::class, 'resolve'])
        ->name('office-erp-password-recovery.resolve');
    Route::get('/current', [OfficeErpPasswordRecoveryController::class, 'current'])
        ->name('office-erp-password-recovery.current');
    Route::post('/complete', [OfficeErpPasswordRecoveryController::class, 'complete'])
        ->name('office-erp-password-recovery.complete');
});
