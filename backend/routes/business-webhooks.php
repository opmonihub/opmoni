<?php

use App\Http\Controllers\WzapWebhookController;
use Illuminate\Support\Facades\Route;

// Include within /api/v1 outside the session/CSRF group after reconciliation.
Route::post('webhooks/wzap', [WzapWebhookController::class, 'store'])->middleware('throttle:60,1');
