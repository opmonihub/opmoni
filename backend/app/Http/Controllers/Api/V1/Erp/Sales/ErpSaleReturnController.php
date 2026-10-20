<?php

namespace App\Http\Controllers\Api\V1\Erp\Sales;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Stock\ErpStockOperation;
use App\Erp\Stock\ErpStockParticipant;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpSaleReturnController extends ErpOperationsController
{
    public function __construct(
        private ErpStockParticipant $stockParticipant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpSaleReturn::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $saleReturn): JsonResponse
    {
        $model = $this->findForCompany($request, ErpSaleReturn::class, $saleReturn);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'erp_sale_id' => ['required', 'integer'],
            'total_amount' => ['required'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $sale = ErpSale::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($data['erp_sale_id'])
            ->firstOrFail();
        app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $sale, 'erp_sale_id');
        app(ErpCatalogScope::class)->assertActiveEstablishment($companyId, $sale->erp_establishment_id);

        abort_unless($sale->status === 'posted', 422, 'Venda precisa estar lançada');

        abort_unless(ErpDecimal::compare(ErpDecimal::parse($data['total_amount']), (string) $sale->total_amount) === 0 && ErpDecimal::isPositive((string) $sale->total_amount), 422, 'Somente devolução integral é suportada; devoluções parciais requerem linhas');
        abort_if(ErpSaleReturn::query()->where('erp_sale_id', $sale->id)->where('status', 'posted')->exists(), 422, 'Origem já devolvida');

        $return = ErpSaleReturn::query()->create([
            'erp_sale_id' => $sale->id,
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $sale->erp_establishment_id,
            'status' => 'draft',
            'total_amount' => ErpDecimal::parse($data['total_amount']),
            'source_operation_key' => $data['source_operation_key'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($return)], 201);
    }

    public function post(Request $request, int $saleReturn): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeStock($request, ErpStockOperation::SALE_RETURN, $saleReturn, fn (string $participantKey): JsonResponse => $this->postEffect($request, $saleReturn, $participantKey));
    }

    private function postEffect(Request $request, int $saleReturn, string $participantKey): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $userId = $request->user()?->id;
        $model = $this->findForCompany($request, ErpSaleReturn::class, $saleReturn);

        if ($model->status === 'posted') {
            return response()->json(['data' => $this->serialize($model)]);
        }
        abort_unless($model->status === 'draft', 422, 'Devolução não está em rascunho');
        $source = ErpSale::query()->where('erp_company_id', $model->erp_company_id)->whereKey($model->erp_sale_id)->firstOrFail();
        app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $source, 'erp_sale_id');
        app(ErpCatalogScope::class)->assertActiveEstablishment($context->company->id, $source->erp_establishment_id);
        abort_unless(ErpDecimal::compare((string) $model->total_amount, (string) $source->total_amount) === 0 && ErpDecimal::isPositive((string) $model->total_amount), 422, 'Somente devolução integral é suportada');

        $operationKey = $participantKey;

        $sale = ErpSale::query()->whereKey($model->erp_sale_id)->firstOrFail();
        $originalIssues = ErpStockMovement::query()
            ->where('source_type', 'erp_sale')
            ->where('source_id', $sale->id)
            ->where('direction', 'out')
            ->get();

        DB::transaction(function () use ($model, $userId, $operationKey, $originalIssues): void {
            ErpSaleReturn::query()->whereKey($model->id)->lockForUpdate()->first();
            abort_if(ErpSaleReturn::query()->where('erp_sale_id', $model->erp_sale_id)->where('status', 'posted')->exists(), 422, 'Venda já devolvida');

            $this->stockParticipant->reverseSaleIssues(
                $model->erp_company_id,
                $model->erp_establishment_id,
                $model->id,
                $operationKey,
                $originalIssues,
                $userId,
            );

            app(ErpFinanceParticipant::class)->reverseSourceObligation($model->erp_company_id, 'erp_sale', $model->erp_sale_id, 'erp_sale_return', $model->id, $userId);

            $model->status = 'posted';
            $model->source_operation_key = $operationKey;
            $model->posted_by_user_id = $userId;
            $model->posted_at = now();
            $model->save();
        });

        return response()->json(['data' => $this->serialize($model->fresh())]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpSaleReturn $model */
        return [
            'id' => $model->id,
            'erp_sale_id' => $model->erp_sale_id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'status' => $model->status,
            'total_amount' => $this->decimalString($model->total_amount),
            'source_operation_key' => $model->source_operation_key,
        ];
    }
}
