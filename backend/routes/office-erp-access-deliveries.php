<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpAccessDeliveryController;
use Illuminate\Support\Facades\Route;

Route::get('/companies/{company}/access-deliveries/{delivery}', [OfficeErpAccessDeliveryController::class, 'show'])
    ->whereNumber('company')
    ->whereNumber('delivery')
    ->name('office-erp-commercial.access-deliveries.show');
