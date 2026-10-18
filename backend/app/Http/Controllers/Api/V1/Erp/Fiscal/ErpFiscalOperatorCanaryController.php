<?php

namespace App\Http\Controllers\Api\V1\Erp\Fiscal;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Fiscal\ErpFiscalIssuanceService;
use App\Erp\Fiscal\ErpFiscalProductionGate;
use App\Http\Controllers\Controller;
use App\Models\Erp\Fiscal\ErpFiscalDocument;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Non-public operator harness for scoped live fiscal verification.
 */
class ErpFiscalOperatorCanaryController extends Controller
{
    public function __construct(
        private ErpFiscalIssuanceService $issuance,
        private ErpFiscalProductionGate $productionGate,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $this->assertOperatorToken($request);

        return response()->json([
            'data' => [
                'fiscal_production_enabled' => filter_var(config('erp.fiscal_production_enabled', false), FILTER_VALIDATE_BOOL),
                'canary_scope_configured' => is_array(config('erp.fiscal_canary_scope')) && config('erp.fiscal_canary_scope') !== [],
                'live_verification' => filter_var(config('erp.fiscal_production_enabled', false), FILTER_VALIDATE_BOOL)
                    && is_array(config('erp.fiscal_canary_scope')) && config('erp.fiscal_canary_scope') !== []
                    ? 'configured'
                    : 'unverified',
            ],
        ]);
    }

    public function transmit(Request $request, int $document): JsonResponse
    {
        $this->assertOperatorToken($request);
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = ErpFiscalDocument::query()
            ->where('erp_company_id', $context->company->id)
            ->whereKey($document)
            ->firstOrFail();
        app(ErpEstablishmentAccess::class)->assertResource($context, $model);
        if ($model->status !== 'authorized') {
            app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, $model->erp_establishment_id);
        }

        $this->productionGate->assertLiveDispatch($model, $request->user()?->id);
        $result = $this->issuance->transmit($model, $context->company, $request->user()?->id);

        return response()->json(['data' => $this->serializeDocument($result)]);
    }

    private function assertOperatorToken(Request $request): void
    {
        $expected = (string) config('erp.fiscal_canary_operator_token', '');
        abort_if($expected === '', 403, 'Canário fiscal não configurado.');
        abort_unless(hash_equals($expected, (string) $request->header('X-Erp-Fiscal-Canary-Token', '')), 403);
    }

    /** @return array<string, mixed> */
    private function serializeDocument(ErpFiscalDocument $document): array
    {
        return [
            'id' => $document->id,
            'model' => $document->model,
            'status' => $document->status,
            'access_key' => $document->access_key,
            'artifact_ready' => $document->storage_path !== null && $document->artifact_sha256 !== null,
        ];
    }
}
