<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/subscription-companies', [OfficeErpSubscriptionController::class, 'index'])
    ->name('office-erp-commercial.subscription-companies.index');
Route::get('/companies/{company}/subscription', [OfficeErpSubscriptionController::class, 'show'])
    ->whereNumber('company')->name('office-erp-commercial.subscription-companies.show');
Route::get('/companies/{company}/contracts', [OfficeErpSubscriptionController::class, 'contracts'])
    ->whereNumber('company')->name('office-erp-commercial.subscription-companies.contracts');
Route::get('/companies/{company}/invoices', [OfficeErpSubscriptionController::class, 'invoices'])
    ->whereNumber('company')->name('office-erp-commercial.subscription-companies.invoices');
Route::post('/companies/{company}/assignments', [OfficeErpSubscriptionController::class, 'assign'])
    ->whereNumber('company')->name('office-erp-commercial.assignments.store');
