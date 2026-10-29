<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpMemberInvitationController;
use Illuminate\Support\Facades\Route;

Route::post('/resolve', [OfficeErpMemberInvitationController::class, 'resolve'])->name('office-erp-member-invitations.resolve');
Route::get('/current', [OfficeErpMemberInvitationController::class, 'current'])->name('office-erp-member-invitations.current');
Route::post('/accept', [OfficeErpMemberInvitationController::class, 'accept'])->name('office-erp-member-invitations.accept');
