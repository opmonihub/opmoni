<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Stock\ErpStockLedger;
use App\Erp\Stock\ErpStockOperation;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\ErpProductVariant;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Models\Erp\Stock\ErpStockAdjustment;
use App\Models\Erp\Stock\ErpStockAllocation;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockCostSettlement;
use App\Models\Erp\Stock\ErpStockLot;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\Erp\Stock\ErpStockTransfer;
use App\Support\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ErpStockController extends ErpOperationsController
{
    public function __construct(private ErpCatalogScope $scope) {}

    public function balances(Request $request): JsonResponse
    {
        $query = ErpStockBalance::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $this->stockFilters($request, $query, 'balance'), fn (Model $model): array => $this->serializeBalance($model));
    }

    public function movements(Request $request): JsonResponse
    {
        $query = ErpStockMovement::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $this->stockFilters($request, $query, 'movement'), fn (Model $model): array => $this->serializeMovement($model));
    }

    public function showMovement(Request $request, int $movement): JsonResponse
    {
        $model = $this->findForCompany($request, ErpStockMovement::class, $movement);
        $allocations = ErpStockAllocation::query()->where('erp_stock_movement_id', $model->id)->orderBy('ordinal')->get();

        return response()->json(['data' => [...$this->serializeMovement($model), 'total_cost' => $model->total_cost, 'known_cost' => $model->known_cost, 'unvalued_quantity' => $model->unvalued_quantity, 'cost_status' => $model->cost_status, 'valuation_method' => $model->valuation_method, 'allocations' => $allocations->toArray(), 'reversal' => $this->reversalPolicy($model), 'reversals' => ErpStockMovement::query()->where('reversal_of_id', $model->id)->orderBy('id')->get()->map(fn ($row): array => $this->serializeMovement($row))->all(), 'lots' => ErpStockLot::query()->where('erp_stock_movement_id', $model->id)->get()->toArray(), 'settlements' => ErpStockCostSettlement::query()->where(function ($query) use ($model): void {
            $query->where('erp_stock_movement_id', $model->id)->orWhereIn('erp_stock_allocation_id', ErpStockAllocation::query()->where('erp_stock_movement_id', $model->id)->select('id'));
        })->orderBy('id')->get()->toArray()]]);
    }

    public function lots(Request $request): JsonResponse
    {
        $query = ErpStockLot::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $this->stockFilters($request, $query, 'lot'), fn (Model $lot): array => $lot->toArray());
    }

    public function showAdjustment(Request $request, int $adjustment): JsonResponse
    {
        $model = $this->findForCompany($request, ErpStockAdjustment::class, $adjustment);

        return response()->json(['data' => $this->serializeAdjustment($model)]);
    }

    public function reverseMovement(Request $request, int $movement): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeStock($request, ErpStockOperation::MOVEMENT_REVERSE, $movement, function (string $key) use ($request, $movement): JsonResponse {
            $original = $this->findForCompany($request, ErpStockMovement::class, $movement);
            $ancestor = $original;
            $visited = [];
            while ($ancestor->reversal_of_id !== null) {
                abort_if(in_array($ancestor->id, $visited, true), 409, 'Histórico de correção inválido.');
                $visited[] = $ancestor->id;
                $ancestor = $this->findForCompany($request, ErpStockMovement::class, $ancestor->reversal_of_id);
            }
            abort_unless($ancestor->source_type === 'erp_stock_adjustment', 422, 'Use a devolução comercial ou a correção vinculada da transferência.');
            $reversal = app(ErpStockLedger::class)->reverse($original, (string) $request->input('quantity', $original->quantity), $request->input('reason'), $key,
                ['source_type' => 'erp_stock_reversal', 'source_id' => $original->id, 'posted_by_user_id' => $request->user()->id]);

            return response()->json(['data' => $this->serializeMovement($reversal)], 201);
        });
    }

    public function showTransfer(Request $request, int $transfer): JsonResponse
    {
        $model = $this->findForCompany($request, ErpStockTransfer::class, $transfer);
        $legs = ErpStockMovement::query()->where('erp_company_id', $model->erp_company_id)->where('source_type', 'erp_stock_transfer')->where('source_id', $model->id)->orderBy('id')->get();
        $returned = '0.0000';
        $inbound = $legs->firstWhere('direction', 'in');
        if ($inbound !== null) {
            foreach (ErpStockMovement::query()->where('reversal_of_id', $inbound->id)->pluck('quantity') as $quantity) {
                $returned = bcadd($returned, (string) $quantity, 4);
            }
        }

        return response()->json(['data' => [...$this->serializeTransfer($model), 'returned_quantity' => $returned, 'movements' => $legs->map(fn ($leg): array => $this->serializeMovement($leg))->all(), 'reversals' => ErpStockMovement::query()->where('source_type', 'erp_stock_transfer_return')->where('source_id', $model->id)->orderBy('id')->get()->map(fn ($leg): array => $this->serializeMovement($leg))->all()]]);
    }

    public function reverseTransfer(Request $request, int $transfer): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeStock($request, ErpStockOperation::TRANSFER_REVERSE, $transfer, function (string $key) use ($request, $transfer): JsonResponse {
            $model = $this->findForCompany($request, ErpStockTransfer::class, $transfer);
            $quantity = (string) $request->input('quantity', $model->quantity);
            $legs = app(ErpStockLedger::class)->reverseTransfer($model, $quantity, $request->input('reason'), $key, $request->user()->id);

            return response()->json(['data' => ['id' => $model->id, 'quantity' => ErpDecimal::parse($quantity), 'movements' => array_map(fn ($leg): array => $this->serializeMovement($leg), $legs)]], 201);
        });
    }

    public function adjustments(Request $request): JsonResponse
    {
        return $this->paginated($request, $this->stockFilters($request, ErpStockAdjustment::query()->where('erp_company_id', $this->companyId($request)), 'adjustment'), fn (Model $model): array => $this->serializeAdjustment($model));
    }

    public function transfers(Request $request): JsonResponse
    {
        return $this->paginated($request, $this->stockFilters($request, ErpStockTransfer::query()->where('erp_company_id', $this->companyId($request)), 'transfer'), fn (Model $model): array => $this->serializeTransfer($model));
    }

    public function storeAdjustment(Request $request): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeStock($request, ErpStockOperation::ADJUSTMENT, null, fn (string $participantKey): JsonResponse => $this->storeAdjustmentEffect($request, $participantKey));
    }

    private function storeAdjustmentEffect(Request $request, string $participantKey): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $data = $request->validate([
            'erp_establishment_id' => ['required', 'integer'],
            'erp_product_variant_id' => ['required', 'integer'],
            'quantity_delta' => ['required'],
            'reason' => ['required', 'string', 'max:255'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $data = $this->normalizeIdentifiers($data);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id']);
        app(ErpEstablishmentAccess::class)->assertRelated($context, $data['erp_establishment_id']);

        $this->scope->assertVariantBelongsToCompany($companyId, $data['erp_product_variant_id']);
        $delta = ErpDecimal::parse($data['quantity_delta']);
        $operationKey = $participantKey;
        $existing = ErpStockAdjustment::query()->where('erp_company_id', $companyId)->where('source_operation_key', $operationKey)->first();
        if ($existing) {
            abort_unless($existing->erp_establishment_id === $data['erp_establishment_id'] && $existing->erp_product_variant_id === $data['erp_product_variant_id'] && $existing->reason === $data['reason'] && ErpDecimal::compare((string) $existing->quantity_delta, $delta) === 0, 409, 'Chave reutilizada com conteúdo diferente');
            app(ErpEstablishmentAccess::class)->assertResource($context, $existing);

            return response()->json(['data' => $this->serializeAdjustment($existing)]);
        }

        $this->scope->assertActiveEstablishment($companyId, $data['erp_establishment_id']);

        $adjustment = DB::transaction(function () use ($data, $companyId, $userId, $delta, $operationKey): ErpStockAdjustment {
            abort_if(ErpDecimal::compare($delta, ErpDecimal::zero()) === 0, 422, 'Informe uma diferença de quantidade.');
            $adjustment = ErpStockAdjustment::query()->create([
                'erp_company_id' => $companyId, 'erp_establishment_id' => $data['erp_establishment_id'],
                'erp_product_variant_id' => $data['erp_product_variant_id'], 'quantity_delta' => $delta,
                'reason' => $data['reason'], 'status' => 'posted', 'source_operation_key' => $operationKey,
                'posted_by_user_id' => $userId, 'posted_at' => now(),
            ]);
            $source = ['source_type' => 'erp_stock_adjustment', 'source_id' => $adjustment->id, 'reason' => $data['reason'], 'posted_by_user_id' => $userId];
            if (ErpDecimal::isPositive($delta)) {
                app(ErpStockLedger::class)->receive($companyId, $data['erp_establishment_id'], $data['erp_product_variant_id'], $delta, null, $source, $operationKey);
            } else {
                app(ErpStockLedger::class)->issue($companyId, $data['erp_establishment_id'], $data['erp_product_variant_id'], ErpDecimal::sub('0', $delta), $source, $operationKey);
            }

            return $adjustment;
        });

        return response()->json(['data' => $this->serializeAdjustment($adjustment)], 201);
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        return app(ErpOperationJournal::class)->executeStock($request, ErpStockOperation::TRANSFER, null, fn (string $participantKey): JsonResponse => $this->storeTransferEffect($request, $participantKey));
    }

    private function storeTransferEffect(Request $request, string $participantKey): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $data = $request->validate([
            'from_establishment_id' => ['required', 'integer'],
            'to_establishment_id' => ['required', 'integer', 'different:from_establishment_id'],
            'erp_product_variant_id' => ['required', 'integer'],
            'quantity' => ['required'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $data = $this->normalizeIdentifiers($data);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['from_establishment_id'], 'from_establishment_id');
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['to_establishment_id'], 'to_establishment_id');
        app(ErpEstablishmentAccess::class)->assertRelated($context, $data['from_establishment_id'], 'from_establishment_id');
        app(ErpEstablishmentAccess::class)->assertRelated($context, $data['to_establishment_id'], 'to_establishment_id');

        $this->scope->assertVariantBelongsToCompany($companyId, $data['erp_product_variant_id']);
        $quantity = ErpDecimal::parse($data['quantity']);
        abort_unless(ErpDecimal::isPositive($quantity), 422, 'Quantidade inválida');

        $operationKey = $participantKey;
        $existing = ErpStockTransfer::query()->where('erp_company_id', $companyId)->where('source_operation_key', $operationKey)->first();
        if ($existing) {
            abort_unless($existing->from_establishment_id === $data['from_establishment_id'] && $existing->to_establishment_id === $data['to_establishment_id'] && $existing->erp_product_variant_id === $data['erp_product_variant_id'] && ErpDecimal::compare((string) $existing->quantity, $quantity) === 0, 409, 'Chave reutilizada com conteúdo diferente');
            app(ErpEstablishmentAccess::class)->assertResource($context, $existing);

            return response()->json(['data' => $this->serializeTransfer($existing)]);
        }

        $this->scope->assertActiveEstablishment($companyId, $data['from_establishment_id'], 'from_establishment_id');
        $this->scope->assertActiveEstablishment($companyId, $data['to_establishment_id'], 'to_establishment_id');

        $transfer = DB::transaction(function () use ($data, $companyId, $userId, $quantity, $operationKey): ErpStockTransfer {
            $transfer = ErpStockTransfer::query()->create([
                'erp_company_id' => $companyId, 'from_establishment_id' => $data['from_establishment_id'],
                'to_establishment_id' => $data['to_establishment_id'], 'erp_product_variant_id' => $data['erp_product_variant_id'],
                'quantity' => $quantity, 'status' => 'posted', 'source_operation_key' => $operationKey,
                'posted_by_user_id' => $userId, 'posted_at' => now(),
            ]);
            app(ErpStockLedger::class)->transfer($companyId, $data['from_establishment_id'], $data['to_establishment_id'], $data['erp_product_variant_id'], $quantity,
                ['source_type' => 'erp_stock_transfer', 'source_id' => $transfer->id, 'posted_by_user_id' => $userId], $operationKey);

            return $transfer;
        });

        return response()->json(['data' => $this->serializeTransfer($transfer)], 201);
    }

    private function stockFilters(Request $request, Builder $query, string $kind): Builder
    {
        $rules = [
            'erp_establishment_id' => ['sometimes', 'integer', 'min:1'],
            'erp_product_variant_id' => ['sometimes', 'integer', 'min:1'],
            'source_type' => ['required_with:source_id', 'string', 'in:erp_purchase,erp_sale,erp_purchase_return,erp_sale_return,erp_stock_adjustment,erp_stock_transfer,erp_stock_transfer_return,erp_stock_reversal'],
            'source_id' => ['required_with:source_type', 'integer', 'min:0'],
            'direction' => ['sometimes', 'in:in,out'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],
        ];
        if ($request->exists('date_from') && $request->exists('date_to')) {
            $rules['date_to'][] = 'after_or_equal:date_from';
        }
        $filters = $request->validate($rules);
        if (isset($filters['direction']) && $kind !== 'movement') {
            throw ValidationException::withMessages(['direction' => 'Filtro indisponível para esta lista.']);
        }
        if ($kind === 'balance' && array_intersect(array_keys($filters), ['source_type', 'source_id', 'date_from', 'date_to']) !== []) {
            throw ValidationException::withMessages(['source_type' => 'Saldo atual não possui filtro de origem ou período.']);
        }
        $context = CurrentCompany::fromRequest($request);
        if (isset($filters['erp_establishment_id'])) {
            $establishment = (int) $filters['erp_establishment_id'];
            app(ErpEstablishmentAccess::class)->assertRelated($context, $establishment);
            if ($kind === 'transfer') {
                $query->where(fn (Builder $inner) => $inner->where('from_establishment_id', $establishment)->orWhere('to_establishment_id', $establishment));
            } else {
                $query->where('erp_establishment_id', $establishment);
            }
        }
        if (isset($filters['erp_product_variant_id'])) {
            $variant = (int) $filters['erp_product_variant_id'];
            if (! ErpProductVariant::query()->whereKey($variant)->whereHas('product', fn (Builder $product) => $product->where('erp_company_id', $context->company->id))->exists()) {
                throw ValidationException::withMessages(['erp_product_variant_id' => 'Variante inválida para esta empresa.']);
            }
            $query->where('erp_product_variant_id', $variant);
        }
        if (isset($filters['source_type'])) {
            $class = match ($filters['source_type']) {
                'erp_purchase' => ErpPurchase::class,
                'erp_sale' => ErpSale::class,
                'erp_purchase_return' => ErpPurchaseReturn::class,
                'erp_sale_return' => ErpSaleReturn::class,
                'erp_stock_adjustment' => ErpStockAdjustment::class,
                'erp_stock_transfer', 'erp_stock_transfer_return' => ErpStockTransfer::class,
                'erp_stock_reversal' => ErpStockMovement::class,
            };
            $source = $class::query()->where('erp_company_id', $context->company->id)->whereKey((int) $filters['source_id'])->first();
            if ($source === null) {
                throw ValidationException::withMessages(['source_id' => 'Origem inválida para esta empresa.']);
            }
            app(ErpEstablishmentAccess::class)->assertRelatedResource($context, $source, 'source_id');
            if ($kind === 'movement') {
                $query->where('source_type', $filters['source_type'])->where('source_id', $source->id);
            } elseif ($kind === 'lot') {
                $query->whereIn('erp_stock_movement_id', ErpStockMovement::query()->where('erp_company_id', $context->company->id)->where('source_type', $filters['source_type'])->where('source_id', $source->id)->select('id'));
            } elseif (($kind === 'adjustment' && $filters['source_type'] === 'erp_stock_adjustment') || ($kind === 'transfer' && $filters['source_type'] === 'erp_stock_transfer')) {
                $query->whereKey($source->id);
            } else {
                throw ValidationException::withMessages(['source_type' => 'Filtro de origem incompatível com esta lista.']);
            }
        }
        if (isset($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        $dateColumn = $kind === 'lot' ? 'received_at' : 'posted_at';
        if (isset($filters['date_from'])) {
            $query->where($dateColumn, '>=', $filters['date_from'].' 00:00:00');
        }
        if (isset($filters['date_to'])) {
            $query->where($dateColumn, '<', CarbonImmutable::createFromFormat('!Y-m-d', $filters['date_to'])->addDay()->format('Y-m-d').' 00:00:00');
        }

        return $query;
    }

    private function reversalPolicy(ErpStockMovement $movement): array
    {
        $remaining = (string) $movement->quantity;
        foreach (ErpStockMovement::query()->where('reversal_of_id', $movement->id)->pluck('quantity') as $quantity) {
            $remaining = bcsub($remaining, (string) $quantity, 4);
        }
        $workflow = match ($movement->source_type) {
            'erp_stock_adjustment', 'erp_stock_reversal' => 'standalone',
            'erp_stock_transfer', 'erp_stock_transfer_return' => 'transfer',
            'erp_sale', 'erp_sale_return' => 'sale_return',
            'erp_purchase', 'erp_purchase_return' => 'purchase_return',
            default => 'reconciliation',
        };
        if ($movement->cost_status === 'legacy_unknown') {
            $workflow = 'reconciliation';
        }

        return ['workflow' => $workflow, 'remaining_quantity' => $remaining, 'reason_required' => true, 'source_id' => $movement->source_id, 'permission' => match ($workflow) {
            'standalone' => 'erp.stock.movements.reverse', 'transfer' => 'erp.stock.transfers.reverse', 'sale_return' => 'erp.sale-returns.post', 'purchase_return' => 'erp.purchase-returns.post', default => null
        }];
    }

    /** @return array<string, mixed> */
    private function serializeBalance(Model $model): array
    {
        /** @var ErpStockBalance $model */
        $reconciliation = $model->ledger_reconciliation === null ? null : array_intersect_key($model->ledger_reconciliation, array_flip(['observed_quantity', 'signed_history_quantity', 'watermark_movement_id', 'provenance', 'needs_reconciliation']));
        foreach (['observed_quantity', 'signed_history_quantity'] as $field) {
            if (isset($reconciliation[$field])) {
                $reconciliation[$field] = ErpDecimal::parse((string) $reconciliation[$field]);
            }
        }

        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_product_variant_id' => $model->erp_product_variant_id,
            'quantity_on_hand' => $this->decimalString($model->quantity_on_hand),
            'ledger_initialized_at' => $model->ledger_initialized_at?->toISOString(),
            'reconciliation' => $reconciliation,
            'average_cost' => $model->average_cost !== null ? $this->decimalString($model->average_cost) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeMovement(Model $model): array
    {
        /** @var ErpStockMovement $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_product_variant_id' => $model->erp_product_variant_id,
            'direction' => $model->direction,
            'quantity' => $this->decimalString($model->quantity),
            'unit_cost' => $model->unit_cost !== null ? $this->decimalString($model->unit_cost) : null,
            'source_type' => $model->source_type,
            'source_id' => $model->source_id,
            'source_operation_key' => $model->source_operation_key,
            'posted_by_user_id' => $model->posted_by_user_id, 'posted_at' => $model->posted_at?->toISOString(),
            'source_line_id' => $model->source_line_id, 'reversal_of_id' => $model->reversal_of_id, 'valuation_method' => $model->valuation_method,
            'currency' => $model->currency, 'source_currency' => $model->source_currency, 'cost_breakdown' => $model->cost_breakdown, 'total_cost' => $model->total_cost, 'known_cost' => $model->known_cost,
            'unvalued_quantity' => $model->unvalued_quantity, 'cost_status' => $model->cost_status, 'reason' => $model->reason,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeAdjustment(Model $model): array
    {
        /** @var ErpStockAdjustment $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_product_variant_id' => $model->erp_product_variant_id,
            'quantity_delta' => $this->decimalString($model->quantity_delta),
            'movement_ids' => ErpStockMovement::query()->where('erp_company_id', $model->erp_company_id)->where('source_type', 'erp_stock_adjustment')->where('source_id', $model->id)->orderBy('id')->pluck('id')->all(),
            'reason' => $model->reason,
            'status' => $model->status,
            'source_operation_key' => $model->source_operation_key,
            'posted_by_user_id' => $model->posted_by_user_id, 'posted_at' => $model->posted_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTransfer(Model $model): array
    {
        /** @var ErpStockTransfer $model */
        return [
            'id' => $model->id,
            'movement_ids' => ErpStockMovement::query()->where('erp_company_id', $model->erp_company_id)->where('source_type', 'erp_stock_transfer')->where('source_id', $model->id)->orderBy('id')->pluck('id')->all(),
            'from_establishment_id' => $model->from_establishment_id,
            'to_establishment_id' => $model->to_establishment_id,
            'erp_product_variant_id' => $model->erp_product_variant_id,
            'quantity' => $this->decimalString($model->quantity),
            'status' => $model->status,
            'source_operation_key' => $model->source_operation_key,
            'posted_by_user_id' => $model->posted_by_user_id, 'posted_at' => $model->posted_at?->toISOString(),
        ];
    }
}
