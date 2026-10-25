<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpInvitationContinuation;
use App\Erp\Commercial\OfficeErpOwnerAcceptance;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpOwnerSetupController extends Controller
{
    public function resolve(Request $request, OfficeErpInvitationContinuation $continuation): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/i']]);

        return response()->json($continuation->exchange($request, $data['token']));
    }

    public function current(Request $request, OfficeErpInvitationContinuation $continuation): JsonResponse
    {
        return response()->json($continuation->current($request));
    }

    public function accept(Request $request, OfficeErpOwnerAcceptance $acceptance): JsonResponse
    {
        abort_unless(array_diff(array_keys($request->all()), ['password', 'company_id']) === [], 422, 'invalid_acceptance_input');
        $data = $request->validate([
            'password' => ['sometimes', 'string', 'min:8', 'max:128'],
            'company_id' => ['sometimes', 'integer', 'min:1'],
        ]);
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && preg_match('/\A[a-zA-Z0-9._:-]{16,128}\z/', $key) === 1, 422, 'invalid_idempotency_key');
        $continuation = app(OfficeErpInvitationContinuation::class)->current($request);
        $status = ($continuation['state'] ?? null) === 'accepted' ? 200 : 201;
        $contract = $acceptance->accept($request, $data, $key);

        return response()->json(['data' => [
            'id' => (int) $contract->id,
            'agreement_status' => $contract->agreement_status,
            'company' => [
                'id' => (int) $contract->erp_company_id,
                'name' => (string) $contract->company?->name,
            ],
        ]], $status);
    }
}
