<?php

namespace App\Http\Controllers\Api\V1\Erp\Sales;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Sales\ErpCommercialDocumentStateMachine;
use App\Erp\Sales\ErpSaleTotalsCalculator;
use App\Erp\Stock\ErpStockDecimal;
use App\Erp\Stock\ErpStockOperation;
use App\Erp\Stock\ErpStockParticipant;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleLine;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ErpSaleController extends ErpOperationsController
{
    public function __construct(
        private ErpCatalogScope $scope,
        private ErpSaleTotalsCalculator $totalsCalculator,
        private ErpCommercialDocumentStateMachine $stateMachine,
        private ErpStockParticipant $stockParticipant,
        private ErpFinanceParticipant $financeParticipant,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpSale::query()
            ->where('erp_company_id', $this->companyId($request))
            ->with('lines');

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $sale): JsonResponse
    {
        $model = $this->findForCompany($request, ErpSale::class, $sale)->load('lines');

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'erp_establishment_id' => ['required', 'integer'],
            'invoice_no' => ['required', 'string', 'max:64'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.erp_product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'lines.*.quantity' => ['required'],
            'lines.*.unit_conversion_factor' => ['sometimes'],
            'lines.*.unit_price' => ['required'],
            'discount' => ['sometimes', 'nullable', 'array'],
            'tax_rate_percent' => ['sometimes', 'nullable'],
        ]);
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

        $totals = $this->totalsCalculator->calculate(
            $data['lines'],
            $data['discount'] ?? null,
            $data['tax_rate_percent'] ?? null,
        );

        $sale = DB::transaction(function () use ($data, $companyId, $totals): ErpSale {
            $lines = $data['lines'];
            unset($data['lines'], $data['discount'], $data['tax_rate_percent']);

            $sale = ErpSale::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $data['erp_establishment_id'],
                'invoice_no' => $data['invoice_no'],
                'status' => ErpCommercialDocumentStateMachine::SALE_DRAFT,
                'fiscal_status' => 'none',
                'subtotal_amount' => $totals['subtotal_amount'],
                'discount_amount' => $totals['discount_amount'],
                'tax_amount' => $totals['tax_amount'],
                'total_amount' => $totals['total_amount'],
                'currency' => $data['currency'] ?? 'BRL',
            ]);

            foreach ($lines as $line) {
                $quantity = ErpDecimal::parse($line['quantity']);
                $unitPrice = ErpDecimal::parse($line['unit_price']);
                ErpSaleLine::query()->create([
                    'erp_sale_id' => $sale->id,
                    'erp_product_variant_id' => $line['erp_product_variant_id'] ?? null,
                    'quantity' => $quantity,
                    'unit_conversion_factor' => ErpStockDecimal::factor($line['unit_conversion_factor'] ?? '1', 'factor'),
                    'unit_price' => $unitPrice,
                    'discount_amount' => ErpDecimal::zero(),
                    'tax_amount' => ErpDecimal::zero(),
                    'line_total' => ErpDecimal::mul($quantity, $unitPrice),
                ]);
            }

            return $sale->load('lines');
        });

        return response()->json(['data' => $this->serialize($sale)], 201);
    }

    public function post(Request $request, int $sale): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeStock($request, ErpStockOperation::SALE, $sale, fn (string $participantKey): JsonResponse => $this->postEffect($request, $sale, $participantKey));
    }

    private function postEffect(Request $request, int $sale, string $participantKey): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $model = $this->findForCompany($request, ErpSale::class, $sale)->load('lines');

        if ($model->status === ErpCommercialDocumentStateMachine::SALE_POSTED) {
            return response()->json(['data' => $this->serialize($model)]);
        }

        $this->scope->assertActiveEstablishment($companyId, $model->erp_establishment_id);

        try {
            $nextStatus = $this->stateMachine->postSale($model->status);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        $operationKey = $participantKey;

        try {
            DB::transaction(function () use ($model, $companyId, $userId, $nextStatus, $operationKey): void {
                ErpSale::query()->whereKey($model->id)->lockForUpdate()->first();

                $this->stockParticipant->issueForSale(
                    $companyId,
                    $model->erp_establishment_id,
                    $model->id,
                    $operationKey,
                    $model->lines->map(fn (ErpSaleLine $line): array => [
                        'line_id' => $line->id,
                        'erp_product_variant_id' => $line->erp_product_variant_id,
                        'quantity' => $this->decimalString($line->quantity),
                        'unit_conversion_factor' => (string) $line->unit_conversion_factor,
                    ])->all(),
                    $userId,
                    $model->currency,
                );

                $this->financeParticipant->postReceivableForSale(
                    $companyId,
                    $model->erp_establishment_id,
                    $model->id,
                    $this->decimalString($model->total_amount),
                    $model->currency,
                    $operationKey.'-obligation',
                    $userId,
                );

                $model->status = $nextStatus;
                $model->source_operation_key = $operationKey;
                $model->posted_by_user_id = $userId;
                $model->posted_at = now();
                $model->save();
            });
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json(['data' => $this->serialize($model->fresh('lines'))]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpSale $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'invoice_no' => $model->invoice_no,
            'status' => $model->status,
            'fiscal_status' => $model->fiscal_status,
            'subtotal_amount' => $this->decimalString($model->subtotal_amount),
            'discount_amount' => $this->decimalString($model->discount_amount),
            'tax_amount' => $this->decimalString($model->tax_amount),
            'total_amount' => $this->decimalString($model->total_amount),
            'currency' => $model->currency,
            'source_operation_key' => $model->source_operation_key,
            'lines' => $model->relationLoaded('lines')
                ? $model->lines->map(fn (ErpSaleLine $line): array => [
                    'id' => $line->id,
                    'erp_product_variant_id' => $line->erp_product_variant_id,
                    'quantity' => $this->decimalString($line->quantity),
                    'unit_conversion_factor' => (string) $line->unit_conversion_factor,
                    'unit_price' => $this->decimalString($line->unit_price),
                    'line_total' => $this->decimalString($line->line_total),
                ])->all()
                : [],
        ];
    }
}
