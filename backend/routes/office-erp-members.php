<?php

use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpCompanyMemberController;
use Illuminate\Support\Facades\Route;

Route::get('/companies/{company}/members', [OfficeErpCompanyMemberController::class, 'index'])->whereNumber('company')->name('office-erp-commercial.members.index');
Route::get('/companies/{company}/member-options', [OfficeErpCompanyMemberController::class, 'options'])->whereNumber('company')->name('office-erp-commercial.members.options');
Route::post('/companies/{company}/member-invitations', [OfficeErpCompanyMemberController::class, 'invite'])->whereNumber('company')->name('office-erp-commercial.member-invitations.store');
Route::post('/companies/{company}/member-invitations/{invitation}/reissue', [OfficeErpCompanyMemberController::class, 'reissue'])->whereNumber('company')->whereNumber('invitation')->name('office-erp-commercial.member-invitations.reissue');
Route::post('/companies/{company}/member-invitations/{invitation}/revoke', [OfficeErpCompanyMemberController::class, 'revoke'])->whereNumber('company')->whereNumber('invitation')->name('office-erp-commercial.member-invitations.revoke');
Route::get('/companies/{company}/members/{membership}', [OfficeErpCompanyMemberController::class, 'show'])->whereNumber('company')->whereNumber('membership')->name('office-erp-commercial.members.show');
Route::patch('/companies/{company}/members/{membership}', [OfficeErpCompanyMemberController::class, 'update'])->whereNumber('company')->whereNumber('membership')->name('office-erp-commercial.members.update');
Route::delete('/companies/{company}/members/{membership}', [OfficeErpCompanyMemberController::class, 'destroy'])->whereNumber('company')->whereNumber('membership')->name('office-erp-commercial.members.destroy');
