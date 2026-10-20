<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Stock\ErpStockDecimal;
use App\Erp\Stock\ErpStockLedger;
use App\Erp\Stock\ErpStockOperation;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseLine;
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
        $model = $this->findForCompany($request, ErpPurchase::class, $purchase)->load('lines');

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $this->validatedPayload($request);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id']);
        app(ErpEstablishmentAccess::class)->assertRelated($context, $data['erp_establishment_id']);
        $this->scope->assertActiveEstablishment($companyId, $data['erp_establishment_id']);
        foreach ($data['lines'] as $index => $line) {
            $this->scope->assertVariantBelongsToCompany($companyId, $line['erp_product_variant_id'] ?? null, "lines.{$index}.erp_product_variant_id");
            abort_unless(ErpDecimal::isPositive(ErpDecimal::parse($line['quantity'])), 422, 'Quantidade deve ser positiva');
            $factor = ErpStockDecimal::factor($line['unit_conversion_factor'] ?? '1', 'factor');
            ErpStockDecimal::base(ErpDecimal::parse($line['quantity']), $factor, "lines.{$index}.unit_conversion_factor");
            $price = ErpDecimal::parse($line['unit_cost'] ?? $line['unit_price']);
            abort_if(ErpDecimal::compare($price, ErpDecimal::zero()) === -1, 422, 'Preço não pode ser negativo');
        }

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
                    'unit_conversion_factor' => ErpStockDecimal::factor($line['unit_conversion_factor'] ?? '1', 'factor'),
                ]);
            }

            return $purchase->load('lines');
        });

        return response()->json(['data' => $this->serialize($purchase)], 201);
    }

    public function post(Request $request, int $purchase): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeStock($request, ErpStockOperation::PURCHASE, $purchase, fn (string $participantKey): JsonResponse => $this->postEffect($request, $purchase, $participantKey));
    }

    private function postEffect(Request $request, int $purchase, string $participantKey): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $model = $this->findForCompany($request, ErpPurchase::class, $purchase)->load('lines');

        if ($model->status === 'posted') {
            return response()->json(['data' => $this->serialize($model)]);
        }
        abort_unless($model->status === 'draft', 422, 'Compra não está em rascunho');
        $this->scope->assertActiveEstablishment($companyId, $model->erp_establishment_id);

        $operationKey = $participantKey;

        DB::transaction(function () use ($model, $companyId, $userId, $operationKey): void {
            $locked = ErpPurchase::query()->whereKey($model->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'draft', 409, 'Compra já lançada.');
            foreach ($model->lines->sortBy('erp_product_variant_id') as $line) {
                if ($line->erp_product_variant_id === null) {
                    continue;
                }
                $this->scope->assertVariantBelongsToCompany($companyId, $line->erp_product_variant_id);
                $variant = ErpProductVariant::query()->findOrFail($line->erp_product_variant_id);
                if (! $variant->product->track_stock || $variant->product->kind === 'service') {
                    continue;
                }
                $quantity = ErpStockDecimal::base((string) $line->quantity, (string) $line->unit_conversion_factor, 'quantity');
                app(ErpStockLedger::class)->receive($companyId, $model->erp_establishment_id, $line->erp_product_variant_id, $quantity, null,
                    ['source_type' => 'erp_purchase', 'source_id' => $model->id, 'source_line_id' => $line->id, 'posted_by_user_id' => $userId],
                    $operationKey.'-line-'.$line->id, (string) $line->line_total, $model->currency);
            }

            app(ErpFinanceParticipant::class)->postPayableForPurchase($companyId, $model->erp_establishment_id, $model->id, (string) $model->total_amount, $model->currency, $operationKey.'-obligation', $userId);

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
                    'unit_conversion_factor' => (string) $line->unit_conversion_factor,
                    'quantity' => $this->decimalString($line->quantity),
                    'unit_cost' => $this->decimalString($line->unit_cost),
                    'line_total' => $this->decimalString($line->line_total),
                ])->all()
                : [],
        ];
    }
}
