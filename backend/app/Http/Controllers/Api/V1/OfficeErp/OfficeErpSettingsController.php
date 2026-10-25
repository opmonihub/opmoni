<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpDelinquency;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class OfficeErpSettingsController extends Controller
{
    public function show(Request $request, OfficeErpDelinquency $delinquency): JsonResponse
    {
        return response()->json([
            'data' => $delinquency->settingsForOffice($this->context($request)),
        ])->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, OfficeErpDelinquency $delinquency): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:manual,automatic'],
            'grace_days' => ['required', 'integer:strict', 'min:0', 'max:65535'],
            'billing_timezone' => ['required', 'string', 'timezone'],
            'page' => ['sometimes', 'integer:strict', 'min:1', 'max:9007199254740991'],
        ]);
        $settings = [
            'mode' => $validated['mode'],
            'grace_days' => $validated['grace_days'],
            'billing_timezone' => $validated['billing_timezone'],
        ];

        return response()->json($delinquency->preview(
            $this->context($request),
            $settings,
            (int) ($validated['page'] ?? 1),
        ))->header('Cache-Control', 'no-store');
    }

    public function update(Request $request, OfficeErpDelinquency $delinquency): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:manual,automatic'],
            'grace_days' => ['required', 'integer:strict', 'min:0', 'max:65535'],
            'billing_timezone' => ['required', 'string', 'timezone'],
            'confirm_automatic' => ['required', 'boolean:strict'],
        ]);
        $key = Validator::make(
            ['idempotency_key' => $request->header('Idempotency-Key')],
            ['idempotency_key' => ['required', 'string', 'max:191']],
        )->validate()['idempotency_key'];
        $settings = [
            'mode' => $validated['mode'],
            'grace_days' => $validated['grace_days'],
            'billing_timezone' => $validated['billing_timezone'],
        ];

        return response()->json($delinquency->save(
            $this->context($request),
            $settings,
            $validated['confirm_automatic'],
            $key,
        ))->header('Cache-Control', 'no-store');
    }

    private function context(Request $request): OfficeErpExecutionContext
    {
        $context = $request->attributes->get(OfficeErpExecutionContext::class);
        abort_unless($context instanceof OfficeErpExecutionContext, 403);

        return $context;
    }
}
