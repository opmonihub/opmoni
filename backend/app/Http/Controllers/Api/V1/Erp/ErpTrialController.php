<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Erp\Commercial\OfficeErpTrialRegistration;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpTrialController extends Controller
{
    public function register(Request $request, string $code, OfficeErpTrialRegistration $registration): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'company_name' => ['required', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:20'],
            'tax_regime' => ['required', 'string', 'in:mei,simple_national,presumed_profit,actual_profit,other'],
            'establishment_name' => ['nullable', 'string', 'max:255'],
            'confirm_existing_association' => ['sometimes', 'boolean'],
        ]);

        return response()->json($registration->register(
            $request,
            $code,
            $input,
            (string) $request->header('Idempotency-Key', ''),
        ), 201);
    }

    public function current(Request $request, OfficeErpTrialRegistration $registration): JsonResponse
    {
        return response()->json([
            'data' => $registration->current($request, (string) $request->header('Idempotency-Key', '')),
        ]);
    }
}
