<?php

use App\Http\Controllers\Api\V1\AccessController;
use App\Http\Controllers\Api\V1\Admin\AccountCommercialController;
use App\Http\Controllers\Api\V1\Admin\AccountContractingCertificateController;
use App\Http\Controllers\Api\V1\Erp\ErpRegistrationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Middleware\AccessSecurity;
use App\Http\Middleware\RequireSuperAdmin;
use App\Http\Middleware\SerializedAccessSession;
use App\Http\Middleware\TrustedIngress;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/business-webhooks.php';
    Route::get('/health', HealthController::class)->name('api.v1.health');
    Route::middleware([TrustedIngress::class, EncryptCookies::class, AddQueuedCookiesToResponse::class, SerializedAccessSession::class, AccessSecurity::class])->group(function (): void {
        Route::get('/csrf', [AccessController::class, 'csrf'])->name('access.csrf');
        Route::get('/registration-status', [AccessController::class, 'registrationStatus'])->name('access.registration-status');
        Route::post('/register', [AccessController::class, 'register'])->name('access.register');
        Route::get('/erp/registration-status', [ErpRegistrationController::class, 'registrationStatus'])->name('erp.registration-status');
        Route::post('/erp/register', [ErpRegistrationController::class, 'register'])->name('erp.register');
        Route::post('/login', [AccessController::class, 'login'])->name('access.login');
        Route::get('/first-access/status', [AccessController::class, 'activationStatus'])->name('access.activation-status');
        Route::post('/first-access', [AccessController::class, 'activate'])->name('access.activate');
        Route::middleware('auth')->group(function (): void {
            Route::get('/me', [AccessController::class, 'me'])->name('access.me');
            Route::post('/logout', [AccessController::class, 'logout'])->name('access.logout');
            Route::post('/account/switch', [AccessController::class, 'switchAccount'])->name('access.switch');
            require __DIR__.'/erp.php';
            require __DIR__.'/office-erp-commercial.php';
            require __DIR__.'/business.php';
            require __DIR__.'/external-mailbox.php';
            require __DIR__.'/external-whatsapp.php';
            Route::prefix('admin/accounts')->middleware(RequireSuperAdmin::class)->group(function (): void {
                Route::get('/{account}/contracting-certificate', [AccountContractingCertificateController::class, 'show'])->whereNumber('account')->name('access.admin-contracting-certificate');
                Route::put('/{account}/contracting-certificate', [AccountContractingCertificateController::class, 'update'])->whereNumber('account')->name('access.admin-contracting-certificate-update');
                Route::get('/{account}/commercial', [AccountCommercialController::class, 'show'])->whereNumber('account')->name('access.admin-commercial');
                Route::patch('/{account}/commercial', [AccountCommercialController::class, 'update'])->whereNumber('account')->name('access.admin-commercial-update');
                Route::get('/', [AccessController::class, 'adminList'])->name('access.admin-list');
                Route::post('/', [AccessController::class, 'adminCreate'])->name('access.admin-create');
                Route::get('/{id}', [AccessController::class, 'adminShow'])->whereNumber('id')->name('access.admin-show');
                Route::patch('/{id}', [AccessController::class, 'adminUpdate'])->whereNumber('id')->name('access.admin-update');
                Route::post('/{id}/first-access', [AccessController::class, 'adminReissue'])->whereNumber('id')->name('access.admin-reissue');
            });
        });
    });
});
