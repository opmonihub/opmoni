<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpOwnerSetupController;
use Illuminate\Support\Facades\Route;

Route::prefix('erp-invitations')->group(function (): void {
    Route::post('/resolve', [OfficeErpOwnerSetupController::class, 'resolve'])
        ->middleware('throttle:10,1')
        ->name('erp-invitations.resolve');
    Route::get('/current', [OfficeErpOwnerSetupController::class, 'current'])
        ->middleware('throttle:30,1')
        ->name('erp-invitations.current');
    Route::post('/accept', [OfficeErpOwnerSetupController::class, 'accept'])
        ->middleware('throttle:10,1')
        ->name('erp-invitations.accept');
});
