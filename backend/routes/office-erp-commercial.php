<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpContextController;
use App\Http\Middleware\ResolveOfficeErpCommercial;
use Illuminate\Support\Facades\Route;

Route::prefix('erp-commercial')->middleware(ResolveOfficeErpCommercial::class)->group(function (): void {
    Route::get('/context', OfficeErpContextController::class)->name('office-erp-commercial.context');
});
