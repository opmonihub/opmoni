<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UploadAccountContractingCertificateRequest;
use App\Http\Resources\AccountContractingCertificateResource;
use App\Models\Account;
use App\Models\AccountContractingCertificate;
use App\Services\AccountContractingCertificateVault;
use Illuminate\Http\JsonResponse;

class AccountContractingCertificateController extends Controller
{
    public function show(Account $account): AccountContractingCertificateResource
    {
        return new AccountContractingCertificateResource(AccountContractingCertificate::where('account_id', $account->id)->first() ?? new AccountContractingCertificate);
    }

    public function update(UploadAccountContractingCertificateRequest $request, Account $account, AccountContractingCertificateVault $vault): JsonResponse
    {
        return (new AccountContractingCertificateResource($vault->replace($account, $request->file('certificate'), $request->validated('password'))))->response()->setStatusCode(200);
    }
}
