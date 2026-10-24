<?php

use App\Http\Controllers\Api\V1\Erp\ErpAccessController;
use App\Http\Controllers\Api\V1\Erp\ErpCompanyMemberController;
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
        Route::get('/establishments/{establishment}', [ErpEstablishmentController::class, 'show'])->whereNumber('establishment')->name('erp.establishments.show');
        Route::post('/establishments', [ErpEstablishmentController::class, 'store'])->name('erp.establishments.store');
        Route::patch('/establishments/{establishment}', [ErpEstablishmentController::class, 'update'])->whereNumber('establishment')->name('erp.establishments.update');
        Route::get('/member-permissions', [ErpCompanyMemberController::class, 'permissions'])->name('erp.members.permissions');
        Route::get('/members', [ErpCompanyMemberController::class, 'index'])->name('erp.members.index');
        Route::post('/members/identity-lookup', [ErpCompanyMemberController::class, 'identityLookup'])->middleware('throttle:30,1')->name('erp.members.identity-lookup');
        Route::get('/members/{membership}', [ErpCompanyMemberController::class, 'show'])->whereNumber('membership')->name('erp.members.show');
        Route::post('/members', [ErpCompanyMemberController::class, 'store'])->name('erp.members.store');
        Route::patch('/members/{membership}', [ErpCompanyMemberController::class, 'update'])->whereNumber('membership')->name('erp.members.update');
        Route::delete('/members/{membership}', [ErpCompanyMemberController::class, 'destroy'])->whereNumber('membership')->name('erp.members.destroy');
        Route::get('/company/settings', [ErpCompanySettingsController::class, 'show'])->name('erp.company.settings.show');
        Route::patch('/company/settings', [ErpCompanySettingsController::class, 'update'])->name('erp.company.settings.update');
        require __DIR__.'/erp-catalogs.php';
        require __DIR__.'/erp-cfop-reference.php';
        foreach (['erp-operations', 'erp-accounting-reports'] as $erpRouteFile) {
            $path = __DIR__."/{$erpRouteFile}.php";
            if (is_file($path)) {
                require $path;
            }
        }
    });
});
