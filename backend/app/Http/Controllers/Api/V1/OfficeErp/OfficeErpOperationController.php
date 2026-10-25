<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpOperations;
use App\Erp\Commercial\OfficeErpSalesPlans;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class OfficeErpOperationController extends Controller
{
    public function __invoke(Request $request, string $key, OfficeErpAdmission $admission, OfficeErpOperations $operations, ErpOperationJournal $journal, OfficeErpSalesPlans $plans): JsonResponse
    {
        $routeKey = $key;
        $headerKey = $request->header('Idempotency-Key');
        if ($headerKey === null) {
            $request->headers->set('Idempotency-Key', $routeKey);
        } elseif (! is_string($headerKey) || $headerKey !== $routeKey) {
            throw ValidationException::withMessages(['idempotency_key' => ['A chave da operação da URL e do cabeçalho deve ser igual.']]);
        }
        $validatedKey = $journal->key($request, true);
        abort_unless($validatedKey === $routeKey, 422);

        $ctx = $admission->context($request);
        $outcome = $operations->lookup(
            $ctx,
            $validatedKey,
            function (string $action, array $payload) use ($ctx, $admission, $plans): void {
                $planId = $payload['sales_plan_id'] ?? null;
                abort_unless($planId === null || is_int($planId), 409);
                $plans->authorizeOperation($ctx, $admission, $action, $payload, $planId);
            },
        );

        return response()->json($outcome)->header('Cache-Control', 'no-store');
    }
}
