<?php

use App\Http\Controllers\Api\V1\Erp\ErpAccessController;
use App\Http\Controllers\Api\V1\Erp\ErpCompanySettingsController;
use App\Http\Controllers\Api\V1\Erp\ErpEstablishmentController;
use App\Http\Controllers\Api\V1\Erp\ErpSubscriptionController;
use App\Http\Middleware\ResolveCompany;
use Illuminate\Support\Facades\Route;

Route::prefix('erp')->group(function (): void {
    Route::post('/company/switch', [ErpAccessController::class, 'switchCompany'])->name('erp.company.switch');
    Route::post('/companies', [ErpAccessController::class, 'createCompany'])->name('erp.companies.create');
    Route::post('/delegations', [ErpAccessController::class, 'acceptDelegation'])->name('erp.delegations.accept');
    Route::delete('/delegations/{delegation}', [ErpAccessController::class, 'revokeDelegation'])->whereNumber('delegation')->name('erp.delegations.revoke');

    Route::middleware(ResolveCompany::class)->group(function (): void {
        Route::get('/subscription', [ErpSubscriptionController::class, 'show'])->name('erp.subscription.show');
        Route::get('/establishments', [ErpEstablishmentController::class, 'index'])->name('erp.establishments.index');
        Route::get('/company/settings', [ErpCompanySettingsController::class, 'show'])->name('erp.company.settings.show');
        Route::patch('/company/settings', [ErpCompanySettingsController::class, 'update'])->name('erp.company.settings.update');
        require __DIR__.'/erp-catalogs.php';
        foreach (['erp-operations', 'erp-accounting-reports'] as $erpRouteFile) {
            $path = __DIR__."/{$erpRouteFile}.php";
            if (is_file($path)) {
                require $path;
            }
        }
    });
});
