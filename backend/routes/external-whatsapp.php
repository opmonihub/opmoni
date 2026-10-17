<?php

use App\Http\Controllers\Api\V1\Tenant\WhatsAppChannelController;
use App\Http\Middleware\ResolveAccount;
use Illuminate\Support\Facades\Route;

// Require inside the same authenticated, CSRF-protected /api/v1 group as business.php.
Route::middleware([ResolveAccount::class])->group(function (): void {
    Route::post('whatsapp/channel/operations/{operation}/continue', [WhatsAppChannelController::class, 'continueOperation'])->whereUuid('operation');
    Route::post('whatsapp/channel/operations/{operation}/reconcile', [WhatsAppChannelController::class, 'reconcileOperation'])->whereUuid('operation');
});
