<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpMemberCredentials;
use App\Erp\ErpOperationJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class OfficeErpMemberCredentialsController
{
    public function reset(Request $request, int $company, int $membership, OfficeErpAdmission $admission, OfficeErpMemberCredentials $credentials, ErpOperationJournal $journal): JsonResponse
    {
        $input = $request->all();
        $this->clearSensitiveInput($request);
        abort_unless(array_diff(array_keys($input), ['expected_auth_revision', 'password', 'password_confirmation']) === [], 422, 'invalid_credential_reset_input');
        $data = Validator::make($input, [
            'expected_auth_revision' => ['required', 'integer', 'min:0'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ])->validate();
        $ctx = $admission->context($request);
        $key = (string) $journal->key($request, true);
        $expectedRevision = (int) $data['expected_auth_revision'];
        $password = $data['password'];
        $result = $credentials->reset(
            $request,
            $ctx,
            $company,
            $membership,
            $expectedRevision,
            $password,
            $key,
        );

        return response()->json($result)->header('Cache-Control', 'no-store, private');
    }

    public function issueRecovery(Request $request, int $company, int $membership, OfficeErpAdmission $admission, OfficeErpMemberCredentials $credentials, ErpOperationJournal $journal): JsonResponse
    {
        $input = $request->all();
        $this->clearSensitiveInput($request);
        abort_unless($input === [], 422, 'invalid_recovery_input');
        $result = $credentials->issueRecovery(
            $request,
            $admission->context($request),
            $company,
            $membership,
            (string) $journal->key($request, true),
        );

        return response()->json($result, 202)->header('Cache-Control', 'no-store, private');
    }

    private function clearSensitiveInput(Request $request): void
    {
        $request->request->replace([]);
        $request->json()->replace([]);
    }
}
