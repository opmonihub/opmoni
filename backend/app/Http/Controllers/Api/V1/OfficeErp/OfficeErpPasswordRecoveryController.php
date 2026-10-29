<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpPasswordRecovery;
use App\Support\AccessRecoveryBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class OfficeErpPasswordRecoveryController
{
    public function resolve(Request $request, OfficeErpPasswordRecovery $recovery): JsonResponse
    {
        $input = $request->all();
        $this->clearSensitiveInput($request);
        abort_unless(array_diff(array_keys($input), ['token', 'delivery_id']) === [], 422, 'invalid_recovery_input');
        $data = Validator::make($input, [
            'token' => ['required', 'string', 'max:1024'],
            'delivery_id' => ['required', 'integer', 'min:1'],
        ])->validate();
        $state = $recovery->exchange($request, $data['token'], (int) $data['delivery_id']);

        return response()->json($state)->header('Cache-Control', 'no-store, private');
    }

    public function current(Request $request, OfficeErpPasswordRecovery $recovery): JsonResponse
    {
        return response()->json($recovery->current($request))->header('Cache-Control', 'no-store, private');
    }

    public function complete(Request $request, OfficeErpPasswordRecovery $recovery, AccessRecoveryBudget $budget): JsonResponse
    {
        $input = $request->all();
        $this->clearSensitiveInput($request);
        abort_unless(array_diff(array_keys($input), ['password', 'password_confirmation']) === [], 422, 'invalid_recovery_completion_input');
        $data = Validator::make($input, [
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ])->validate();
        $budget->sensitive($request, 0);
        $result = $recovery->complete($request, $data['password']);
        $status = match ($result['state']) {
            'completed' => 200,
            'identity_mismatch' => 409,
            default => 410,
        };

        return response()->json($result, $status)->header('Cache-Control', 'no-store, private');
    }

    private function clearSensitiveInput(Request $request): void
    {
        $request->request->replace([]);
        $request->json()->replace([]);
    }
}
