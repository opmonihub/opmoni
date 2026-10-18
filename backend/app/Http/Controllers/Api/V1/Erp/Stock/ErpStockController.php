<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Stock\ErpStockValuation;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Stock\ErpStockAdjustment;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\Erp\Stock\ErpStockTransfer;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpStockController extends ErpOperationsController
{
    public function __construct(private ErpCatalogScope $scope) {}

    public function balances(Request $request): JsonResponse
    {
        $query = ErpStockBalance::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serializeBalance($model));
    }

    public function movements(Request $request): JsonResponse
    {
        $query = ErpStockMovement::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serializeMovement($model));
    }

    public function adjustments(Request $request): JsonResponse
    {
        return $this->paginated($request, ErpStockAdjustment::query()->where('erp_company_id', $this->companyId($request)), fn (Model $model): array => $this->serializeAdjustment($model));
    }

    public function transfers(Request $request): JsonResponse
    {
        return $this->paginated($request, ErpStockTransfer::query()->where('erp_company_id', $this->companyId($request)), fn (Model $model): array => $this->serializeTransfer($model));
    }

    public function storeAdjustment(Request $request): JsonResponse
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
        app(ErpStockValuation::class)->assertSupportedCompany($companyId);
        $delta = ErpDecimal::parse($data['quantity_delta']);
        $operationKey = $data['source_operation_key'] ?? ('adjustment-'.uniqid('', true));
        $existing = ErpStockAdjustment::query()->where('erp_company_id', $companyId)->where('source_operation_key', $operationKey)->first();
        if ($existing) {
            abort_unless($existing->erp_establishment_id === $data['erp_establishment_id'] && $existing->erp_product_variant_id === $data['erp_product_variant_id'] && $existing->reason === $data['reason'] && ErpDecimal::compare((string) $existing->quantity_delta, $delta) === 0, 409, 'Chave reutilizada com conteúdo diferente');
            app(ErpEstablishmentAccess::class)->assertResource($context, $existing);

            return response()->json(['data' => $this->serializeAdjustment($existing)]);
        }

        $this->scope->assertActiveEstablishment($companyId, $data['erp_establishment_id']);

        $adjustment = DB::transaction(function () use ($data, $companyId, $userId, $delta, $operationKey): ErpStockAdjustment {
            $balance = ErpStockBalance::query()->firstOrCreate(
                [
                    'erp_establishment_id' => $data['erp_establishment_id'],
                    'erp_product_variant_id' => $data['erp_product_variant_id'],
                ],
                [
                    'erp_company_id' => $companyId,
                    'quantity_on_hand' => ErpDecimal::zero(),
                ],
            );
            abort_if(ErpDecimal::compare(ErpDecimal::add((string) $balance->quantity_on_hand, $delta), ErpDecimal::zero()) === -1, 422, 'Estoque insuficiente');
            abort_if(ErpDecimal::isPositive($delta) && $balance->average_cost === null, 422, 'Entrada sem custo exige recebimento de compra');
            $balance->quantity_on_hand = ErpDecimal::add($this->decimalString($balance->quantity_on_hand), $delta);
            $balance->save();

            $movementQuantity = ErpDecimal::compare($delta, ErpDecimal::zero()) === -1
                ? ErpDecimal::sub(ErpDecimal::zero(), $delta)
                : $delta;

            ErpStockMovement::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $data['erp_establishment_id'],
                'erp_product_variant_id' => $data['erp_product_variant_id'],
                'direction' => ErpDecimal::compare($delta, ErpDecimal::zero()) === -1 ? 'out' : 'in',
                'quantity' => $movementQuantity,
                'source_type' => 'erp_stock_adjustment',
                'source_id' => 0,
                'source_operation_key' => $operationKey,
                'posted_by_user_id' => $userId,
                'posted_at' => now(),
            ]);

            return ErpStockAdjustment::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $data['erp_establishment_id'],
                'erp_product_variant_id' => $data['erp_product_variant_id'],
                'quantity_delta' => $delta,
                'reason' => $data['reason'],
                'status' => 'posted',
                'source_operation_key' => $operationKey,
                'posted_by_user_id' => $userId,
                'posted_at' => now(),
            ]);
        });

        return response()->json(['data' => $this->serializeAdjustment($adjustment)], 201);
    }

    public function storeTransfer(Request $request): JsonResponse
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
        app(ErpStockValuation::class)->assertSupportedCompany($companyId);
        $quantity = ErpDecimal::parse($data['quantity']);
        abort_unless(ErpDecimal::isPositive($quantity), 422, 'Quantidade inválida');

        $operationKey = $data['source_operation_key'] ?? ('transfer-'.uniqid('', true));
        $existing = ErpStockTransfer::query()->where('erp_company_id', $companyId)->where('source_operation_key', $operationKey)->first();
        if ($existing) {
            abort_unless($existing->from_establishment_id === $data['from_establishment_id'] && $existing->to_establishment_id === $data['to_establishment_id'] && $existing->erp_product_variant_id === $data['erp_product_variant_id'] && ErpDecimal::compare((string) $existing->quantity, $quantity) === 0, 409, 'Chave reutilizada com conteúdo diferente');
            app(ErpEstablishmentAccess::class)->assertResource($context, $existing);

            return response()->json(['data' => $this->serializeTransfer($existing)]);
        }

        $this->scope->assertActiveEstablishment($companyId, $data['from_establishment_id'], 'from_establishment_id');
        $this->scope->assertActiveEstablishment($companyId, $data['to_establishment_id'], 'to_establishment_id');

        $transfer = DB::transaction(function () use ($data, $companyId, $userId, $quantity, $operationKey): ErpStockTransfer {
            $fromBalance = ErpStockBalance::query()->firstOrCreate(
                [
                    'erp_establishment_id' => $data['from_establishment_id'],
                    'erp_product_variant_id' => $data['erp_product_variant_id'],
                ],
                ['erp_company_id' => $companyId, 'quantity_on_hand' => ErpDecimal::zero()],
            );
            $available = ErpDecimal::parse($this->decimalString($fromBalance->quantity_on_hand));
            abort_if(ErpDecimal::compare($available, $quantity) === -1, 422, 'Estoque insuficiente');

            $fromBalance->quantity_on_hand = ErpDecimal::sub($available, $quantity);
            $fromBalance->save();

            $toBalance = ErpStockBalance::query()->firstOrCreate(
                [
                    'erp_establishment_id' => $data['to_establishment_id'],
                    'erp_product_variant_id' => $data['erp_product_variant_id'],
                ],
                ['erp_company_id' => $companyId, 'quantity_on_hand' => ErpDecimal::zero()],
            );
            app(ErpStockValuation::class)->assertCompatibleReceipt($toBalance, (string) $fromBalance->average_cost);
            $toBalance->average_cost = $fromBalance->average_cost;
            $toBalance->quantity_on_hand = ErpDecimal::add($this->decimalString($toBalance->quantity_on_hand), $quantity);
            $toBalance->save();

            ErpStockMovement::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $data['from_establishment_id'],
                'erp_product_variant_id' => $data['erp_product_variant_id'],
                'direction' => 'out',
                'unit_cost' => $fromBalance->average_cost,
                'quantity' => $quantity,
                'source_type' => 'erp_stock_transfer',
                'source_id' => 0,
                'source_operation_key' => $operationKey.'-out',
                'posted_by_user_id' => $userId,
                'posted_at' => now(),
            ]);
            ErpStockMovement::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $data['to_establishment_id'],
                'erp_product_variant_id' => $data['erp_product_variant_id'],
                'direction' => 'in',
                'unit_cost' => $fromBalance->average_cost,
                'quantity' => $quantity,
                'source_type' => 'erp_stock_transfer',
                'source_id' => 0,
                'source_operation_key' => $operationKey.'-in',
                'posted_by_user_id' => $userId,
                'posted_at' => now(),
            ]);

            return ErpStockTransfer::query()->create([
                'erp_company_id' => $companyId,
                'from_establishment_id' => $data['from_establishment_id'],
                'to_establishment_id' => $data['to_establishment_id'],
                'erp_product_variant_id' => $data['erp_product_variant_id'],
                'quantity' => $quantity,
                'status' => 'posted',
                'source_operation_key' => $operationKey,
                'posted_by_user_id' => $userId,
                'posted_at' => now(),
            ]);
        });

        return response()->json(['data' => $this->serializeTransfer($transfer)], 201);
    }

    /** @return array<string, mixed> */
    private function serializeBalance(Model $model): array
    {
        /** @var ErpStockBalance $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_product_variant_id' => $model->erp_product_variant_id,
            'quantity_on_hand' => $this->decimalString($model->quantity_on_hand),
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
            'reason' => $model->reason,
            'status' => $model->status,
            'source_operation_key' => $model->source_operation_key,
        ];
    }

    /** @return array<string, mixed> */
    private function serializeTransfer(Model $model): array
    {
        /** @var ErpStockTransfer $model */
        return [
            'id' => $model->id,
            'from_establishment_id' => $model->from_establishment_id,
            'to_establishment_id' => $model->to_establishment_id,
            'erp_product_variant_id' => $model->erp_product_variant_id,
            'quantity' => $this->decimalString($model->quantity),
            'status' => $model->status,
            'source_operation_key' => $model->source_operation_key,
        ];
    }
}
