<?php

use App\Http\Controllers\Api\V1\Tenant\SerproMonitoringMessageController;
use App\Http\Middleware\ResolveAccount;
use Illuminate\Support\Facades\Route;

// Require inside the authenticated /api/v1 access group, beside business.php.
Route::middleware(ResolveAccount::class)->group(function (): void {
    Route::post('serpro/monitoring/obligations/{obligation}/clients/{client}/messages/{message}/reconcile', [SerproMonitoringMessageController::class, 'reconcile'])
        ->where(['obligation' => '.+', 'client' => '[0-9]+', 'message' => '[0-9]+']);
});
