<?php

use App\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\SupportLogController as AdminSupportLogController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SupportAccessController;
use App\Http\Controllers\Tenant\AccountMemberController;
use App\Http\Controllers\Tenant\AccountSwitchController;
use App\Http\Controllers\Tenant\ClientBulkDeletionController;
use App\Http\Controllers\Tenant\ClientCertificateController;
use App\Http\Controllers\Tenant\ClientCnpjLookupController;
use App\Http\Controllers\Tenant\ClientCnpjRefreshController;
use App\Http\Controllers\Tenant\ClientController;
use App\Http\Controllers\Tenant\ClientEcacPowerOfAttorneyController;
use App\Http\Controllers\Tenant\ClientSavedFilterController;
use App\Http\Controllers\Tenant\ClientSelectionController;
use App\Http\Controllers\Tenant\ClientTagAssignmentController;
use App\Http\Controllers\Tenant\DepartmentController;
use App\Http\Controllers\Tenant\ProcessController;
use App\Http\Controllers\Tenant\ProcessTemplateController;
use App\Http\Controllers\Tenant\SerproMonitoringController;
use App\Http\Controllers\Tenant\TagController;
use App\Http\Controllers\Tenant\TaskController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/registration-status', [AuthController::class, 'registrationStatus'])->middleware('throttle:30,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

Route::post('/account/switch', AccountSwitchController::class)->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('clients/summary', [ClientController::class, 'summary']);
    Route::get('clients/analytics', [ClientController::class, 'analytics']);
    Route::post('clients/tags', [ClientTagAssignmentController::class, 'store']);
    Route::apiResource('tags', TagController::class)->except(['show']);
    Route::apiResource('departments', DepartmentController::class)->except(['show']);
    Route::get('clients/saved-filters', [ClientSavedFilterController::class, 'index']);
    Route::post('clients/saved-filters', [ClientSavedFilterController::class, 'store']);
    Route::delete('clients/saved-filters/{savedFilter}', [ClientSavedFilterController::class, 'destroy']);
    Route::post('clients/selections', [ClientSelectionController::class, 'store']);
    Route::post('clients/selections/{selection}/presence', [ClientSelectionController::class, 'presence']);
    Route::post('clients/bulk-deletions', [ClientBulkDeletionController::class, 'store']);
    Route::get('clients/bulk-deletions/{bulkDeletion}', [ClientBulkDeletionController::class, 'show']);
    Route::post('clients/cnpj-lookup', ClientCnpjLookupController::class)
        ->middleware('throttle:10,1');
    Route::post('clients/{client}/cnpj-refresh-preview', [ClientCnpjRefreshController::class, 'preview']);
    Route::post('clients/{client}/cnpj-refresh', [ClientCnpjRefreshController::class, 'update']);
    Route::post('clients/{client}/certificate', [ClientCertificateController::class, 'store']);
    Route::delete('clients/{client}/certificate', [ClientCertificateController::class, 'destroy']);
    Route::put('clients/{client}/ecac-power-of-attorney', [ClientEcacPowerOfAttorneyController::class, 'update']);
    Route::delete('clients/{client}/ecac-power-of-attorney', [ClientEcacPowerOfAttorneyController::class, 'destroy']);
    Route::apiResource('clients', ClientController::class);
    Route::apiResource('monitorings', SerproMonitoringController::class);
    Route::apiResource('processes', ProcessController::class);
    Route::get('account/members/directory', [AccountMemberController::class, 'directory']);
    Route::apiResource('process-templates', ProcessTemplateController::class);
    Route::get('process-templates/{process_template}/preview', [ProcessTemplateController::class, 'preview']);
    Route::post('process-templates/{process_template}/generate', [ProcessTemplateController::class, 'generate']);
    Route::apiResource('tasks', TaskController::class)->only(['index', 'show', 'update']);
    Route::get('work/calendar', [TaskController::class, 'calendar']);
    Route::get('work/grouped', [TaskController::class, 'grouped']);
    Route::get('work/tasks/unscoped', [TaskController::class, 'unscopedForMonth']);
    Route::apiResource('account/members', AccountMemberController::class)->parameter('members', 'member');
});

Route::middleware(['auth:sanctum', 'super_admin'])->prefix('admin')->group(function (): void {
    Route::apiResource('accounts', AdminAccountController::class)->except(['destroy']);
    Route::apiResource('plans', AdminPlanController::class)->except(['destroy']);
    Route::apiResource('subscriptions', AdminSubscriptionController::class)->only(['index', 'show', 'update']);
    Route::get('users', [AdminUserController::class, 'index']);
    Route::get('support/logs', [AdminSupportLogController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'super_admin'])
    ->post('support/accounts/{account}/enter', [SupportAccessController::class, 'enter']);
Route::middleware(['auth:sanctum', 'super_admin'])
    ->post('support/exit', [SupportAccessController::class, 'exit']);
