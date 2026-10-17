<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Erp\Finance\ErpDecimal;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseLine;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpPurchaseController extends ErpOperationsController
{
    public function __construct(private ErpCatalogScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpPurchase::query()
            ->where('erp_company_id', $this->companyId($request))
            ->with('lines');

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $purchase): JsonResponse
    {
        $model = ErpPurchase::query()
            ->where('erp_company_id', $this->companyId($request))
            ->with('lines')
            ->whereKey($purchase)
            ->firstOrFail();

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $this->validatedPayload($request);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id']);

        $purchase = DB::transaction(function () use ($data, $companyId): ErpPurchase {
            $lines = $data['lines'];
            unset($data['lines']);
            $total = ErpDecimal::zero();
            foreach ($lines as $line) {
                $lineTotal = ErpDecimal::mul(
                    ErpDecimal::parse($line['quantity']),
                    ErpDecimal::parse($line['unit_cost']),
                );
                $total = ErpDecimal::add($total, $lineTotal);
            }

            $purchase = ErpPurchase::query()->create([
                ...$data,
                'erp_company_id' => $companyId,
                'status' => 'draft',
                'total_amount' => $total,
                'currency' => $data['currency'] ?? 'BRL',
            ]);

            foreach ($lines as $line) {
                $quantity = ErpDecimal::parse($line['quantity']);
                $unitCost = ErpDecimal::parse($line['unit_cost']);
                ErpPurchaseLine::query()->create([
                    'erp_purchase_id' => $purchase->id,
                    'erp_product_variant_id' => $line['erp_product_variant_id'] ?? null,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => ErpDecimal::mul($quantity, $unitCost),
                    'unit_conversion_factor' => $line['unit_conversion_factor'] ?? '1',
                ]);
            }

            return $purchase->load('lines');
        });

        return response()->json(['data' => $this->serialize($purchase)], 201);
    }

    public function post(Request $request, int $purchase): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $model = ErpPurchase::query()
            ->where('erp_company_id', $companyId)
            ->with('lines')
            ->whereKey($purchase)
            ->firstOrFail();

        if ($model->status === 'posted') {
            return response()->json(['data' => $this->serialize($model)]);
        }
        abort_unless($model->status === 'draft', 422, 'Compra não está em rascunho');

        $operationKey = $request->validate([
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ])['source_operation_key'] ?? ('purchase-post-'.$model->id);

        DB::transaction(function () use ($model, $companyId, $userId, $operationKey): void {
            foreach ($model->lines as $line) {
                if ($line->erp_product_variant_id === null) {
                    continue;
                }
                $balance = ErpStockBalance::query()->firstOrCreate(
                    [
                        'erp_establishment_id' => $model->erp_establishment_id,
                        'erp_product_variant_id' => $line->erp_product_variant_id,
                    ],
                    [
                        'erp_company_id' => $companyId,
                        'quantity_on_hand' => ErpDecimal::zero(),
                    ],
                );
                $balance->quantity_on_hand = ErpDecimal::add(
                    $this->decimalString($balance->quantity_on_hand),
                    $this->decimalString($line->quantity),
                );
                $balance->average_cost = $this->decimalString($line->unit_cost);
                $balance->save();

                ErpStockMovement::query()->create([
                    'erp_company_id' => $companyId,
                    'erp_establishment_id' => $model->erp_establishment_id,
                    'erp_product_variant_id' => $line->erp_product_variant_id,
                    'direction' => 'in',
                    'quantity' => $this->decimalString($line->quantity),
                    'unit_cost' => $this->decimalString($line->unit_cost),
                    'source_type' => 'erp_purchase',
                    'source_id' => $model->id,
                    'source_operation_key' => $operationKey.'-line-'.$line->id,
                    'posted_by_user_id' => $userId,
                    'posted_at' => now(),
                ]);
            }

            $model->status = 'posted';
            $model->source_operation_key = $operationKey;
            $model->posted_by_user_id = $userId;
            $model->posted_at = now();
            $model->save();
        });

        return response()->json(['data' => $this->serialize($model->fresh('lines'))]);
    }

    /** @return array<string, mixed> */
    private function validatedPayload(Request $request): array
    {
        return $request->validate([
            'erp_establishment_id' => ['required', 'integer'],
            'document_no' => ['sometimes', 'nullable', 'string', 'max:64'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.erp_product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.quantity' => ['required'],
            'lines.*.unit_cost' => ['required'],
            'lines.*.unit_conversion_factor' => ['sometimes'],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpPurchase $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'document_no' => $model->document_no,
            'status' => $model->status,
            'total_amount' => $this->decimalString($model->total_amount),
            'currency' => $model->currency,
            'source_operation_key' => $model->source_operation_key,
            'lines' => $model->relationLoaded('lines')
                ? $model->lines->map(fn (ErpPurchaseLine $line): array => [
                    'id' => $line->id,
                    'erp_product_variant_id' => $line->erp_product_variant_id,
                    'quantity' => $this->decimalString($line->quantity),
                    'unit_cost' => $this->decimalString($line->unit_cost),
                    'line_total' => $this->decimalString($line->line_total),
                ])->all()
                : [],
        ];
    }
}
