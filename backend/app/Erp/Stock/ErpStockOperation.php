<?php

namespace App\Erp\Stock;

use App\Erp\ErpEstablishmentAccess;
use App\Erp\Finance\ErpDecimal;
use App\Models\Erp\Sales\ErpSale;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Models\Erp\Stock\ErpStockAdjustment;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Models\Erp\Stock\ErpStockTransfer;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ErpStockOperation
{
    public const ADJUSTMENT = 'erp.stock.adjustments.store';

    public const TRANSFER = 'erp.stock.transfers.store';

    public const TRANSFER_REVERSE = 'erp.stock.transfers.reverse';

    public const MOVEMENT_REVERSE = 'erp.stock.movements.reverse';

    public const PURCHASE = 'erp.purchases.post';

    public const SALE = 'erp.sales.post';

    public const PURCHASE_RETURN = 'erp.purchase-returns.post';

    public const SALE_RETURN = 'erp.sale-returns.post';

    public static function names(): array
    {
        return [self::ADJUSTMENT, self::TRANSFER, self::PURCHASE, self::SALE, self::PURCHASE_RETURN, self::SALE_RETURN, self::TRANSFER_REVERSE, self::MOVEMENT_REVERSE];
    }

    public function canonical(Request $request, string $operation, ?int $sourceId): array
    {
        if (in_array($operation, [self::TRANSFER_REVERSE, self::MOVEMENT_REVERSE], true)) {
            if (array_diff(array_keys($request->all()), ['quantity', 'reason', 'source_operation_key']) !== []) {
                throw ValidationException::withMessages(['quantity' => 'Conteúdo de correção não suportado.']);
            }
            $model = $this->resource($request, $operation, $sourceId);
            $reason = Validator::make($request->all(), ['reason' => ['required', 'string', 'max:255']])->validate()['reason'];
            $quantity = $request->input('quantity', (string) $model->quantity);
            if (! is_string($quantity)) {
                throw ValidationException::withMessages(['quantity' => 'Informe uma quantidade decimal em texto.']);
            }

            return ['source_id' => $model->id, 'quantity' => ErpDecimal::parse($quantity), 'reason' => $reason];
        }
        $manual = in_array($operation, [self::ADJUSTMENT, self::TRANSFER], true);
        $fields = $operation === self::ADJUSTMENT ? ['erp_establishment_id', 'erp_product_variant_id', 'quantity_delta', 'reason'] : ['from_establishment_id', 'to_establishment_id', 'erp_product_variant_id', 'quantity'];
        if (array_diff(array_keys($request->all()), $manual ? [...$fields, 'source_operation_key'] : ['source_operation_key']) !== []) {
            throw ValidationException::withMessages(['source_operation_key' => 'Conteúdo de operação não suportado.']);
        }
        if (! $manual) {
            $model = $this->resource($request, $operation, $sourceId);
            $snapshot = collect($model->attributesToArray())->except(['status', 'source_operation_key', 'posted_by_user_id', 'posted_at', 'created_at', 'updated_at'])->all();
            if (in_array($operation, [self::PURCHASE, self::SALE], true)) {
                $snapshot['lines'] = $model->lines()->orderBy('id')->get()->map(fn (Model $line): array => collect($line->attributesToArray())->except(['created_at', 'updated_at'])->all())->all();
            }

            return ['source_id' => $model->id, 'snapshot' => $snapshot];
        }
        $payload = [];
        foreach ($fields as $field) {
            $value = $request->input($field);
            if (str_ends_with($field, '_id')) {
                if (! is_int($value) && ! is_string($value)) {
                    throw ValidationException::withMessages([$field => 'Informe um identificador inteiro.']);
                }
                $data = Validator::make([$field => $value], [$field => ['required', 'integer', 'min:1', 'max:9007199254740991']])->validate();
                $payload[$field] = (int) $data[$field];
            } elseif ($field === 'reason') {
                $payload[$field] = Validator::make([$field => $value], [$field => ['required', 'string', 'max:255']])->validate()[$field];
            } else {
                if (! is_string($value)) {
                    throw ValidationException::withMessages([$field => 'Informe uma quantidade decimal em texto.']);
                }
                $payload[$field] = ErpDecimal::parse($value);
            }
        }

        return $payload;
    }

    public function assertAuthorized(Request $request, string $operation, array $references): void
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertPermission($operation, true);
        if (isset($references['resource_id']) || isset($references['source_id'])) {
            $this->resource($request, $operation, $references['resource_id'] ?? $references['source_id']);
        } else {
            foreach (['erp_establishment_id', 'from_establishment_id', 'to_establishment_id'] as $field) {
                if (isset($references[$field])) {
                    app(ErpEstablishmentAccess::class)->assertRelated($context, $references[$field], $field);
                }
            }
        }
    }

    public function assertNoLegacy(Request $request, string $operation, array $payload, string $key): void
    {
        if (in_array($operation, [self::TRANSFER_REVERSE, self::MOVEMENT_REVERSE], true)) {
            return;
        }
        if (in_array($operation, [self::ADJUSTMENT, self::TRANSFER], true)) {
            $class = $this->model($operation);
            $legacy = $class::query()->where('erp_company_id', CurrentCompany::fromRequest($request)->company->id)->where('source_operation_key', $key)->exists();
        } else {
            $legacy = $this->resource($request, $operation, $payload['source_id'])->status === 'posted';
        }
        abort_if($legacy, response()->json(['code' => 'legacy_operation_requires_reconciliation', 'message' => 'Operação anterior exige conciliação.'], 409));
    }

    private function resource(Request $request, string $operation, ?int $id): Model
    {
        $class = $this->model($operation);
        $context = CurrentCompany::fromRequest($request);
        $model = $class::query()->where('erp_company_id', $context->company->id)->findOrFail($id);
        app(ErpEstablishmentAccess::class)->assertResource($context, $model);

        return $model;
    }

    /** @return class-string<Model> */
    private function model(string $operation): string
    {
        return match ($operation) {
            self::ADJUSTMENT => ErpStockAdjustment::class, self::TRANSFER, self::TRANSFER_REVERSE => ErpStockTransfer::class,
            self::MOVEMENT_REVERSE => ErpStockMovement::class,
            self::PURCHASE => ErpPurchase::class, self::SALE => ErpSale::class,
            self::PURCHASE_RETURN => ErpPurchaseReturn::class, self::SALE_RETURN => ErpSaleReturn::class,
        };
    }
}
