<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpMemberCredentialsController;
use Illuminate\Support\Facades\Route;

Route::post('/companies/{company}/members/{membership}/password-reset', [OfficeErpMemberCredentialsController::class, 'reset'])
    ->whereNumber('company')->whereNumber('membership')
    ->name('office-erp-commercial.members.password-reset');
Route::post('/companies/{company}/members/{membership}/recovery', [OfficeErpMemberCredentialsController::class, 'issueRecovery'])
    ->whereNumber('company')->whereNumber('membership')
    ->name('office-erp-commercial.members.password-recovery');
