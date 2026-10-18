<?php

namespace App\Http\Controllers\Api\V1\Erp\Stock;

use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Stock\ErpStockValuation;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Stock\ErpPurchase;
use App\Models\Erp\Stock\ErpPurchaseReturn;
use App\Models\Erp\Stock\ErpStockBalance;
use App\Models\Erp\Stock\ErpStockMovement;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpPurchaseReturnController extends ErpOperationsController
{
    public function index(Request $request): JsonResponse
    {
        $query = ErpPurchaseReturn::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $purchaseReturn): JsonResponse
    {
        $model = $this->findForCompany($request, ErpPurchaseReturn::class, $purchaseReturn);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'erp_purchase_id' => ['required', 'integer'],
            'total_amount' => ['required'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);

        $purchase = ErpPurchase::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($data['erp_purchase_id'])
            ->firstOrFail();

        abort_unless($purchase->status === 'posted', 422, 'Compra precisa estar lançada');

        abort_unless(ErpDecimal::compare(ErpDecimal::parse($data['total_amount']), (string) $purchase->total_amount) === 0 && ErpDecimal::isPositive((string) $purchase->total_amount), 422, 'Somente devolução integral é suportada; devoluções parciais requerem linhas');
        abort_if(ErpPurchaseReturn::query()->where('erp_purchase_id', $purchase->id)->where('status', 'posted')->exists(), 422, 'Origem já devolvida');

        $return = ErpPurchaseReturn::query()->create([
            'erp_purchase_id' => $purchase->id,
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $purchase->erp_establishment_id,
            'status' => 'draft',
            'total_amount' => ErpDecimal::parse($data['total_amount']),
            'source_operation_key' => $data['source_operation_key'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($return)], 201);
    }

    public function post(Request $request, int $purchaseReturn): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $userId = $request->user()?->id;
        $model = $this->findForCompany($request, ErpPurchaseReturn::class, $purchaseReturn);

        if ($model->status === 'posted') {
            return response()->json(['data' => $this->serialize($model)]);
        }
        abort_unless($model->status === 'draft', 422, 'Devolução não está em rascunho');
        $source = ErpPurchase::query()->where('erp_company_id', $model->erp_company_id)->whereKey($model->erp_purchase_id)->firstOrFail();
        abort_unless(ErpDecimal::compare((string) $model->total_amount, (string) $source->total_amount) === 0 && ErpDecimal::isPositive((string) $model->total_amount), 422, 'Somente devolução integral é suportada');

        DB::transaction(function () use ($model, $userId): void {
            abort_if(ErpPurchaseReturn::query()->where('erp_purchase_id', $model->erp_purchase_id)->where('status', 'posted')->exists(), 422, 'Compra já devolvida');
            app(ErpFinanceParticipant::class)->reverseSourceObligation($model->erp_company_id, 'erp_purchase', $model->erp_purchase_id, 'erp_purchase_return', $model->id, $userId);
            $movements = ErpStockMovement::query()->where('erp_company_id', $model->erp_company_id)->where('source_type', 'erp_purchase')->where('source_id', $model->erp_purchase_id)->where('direction', 'in')->get();
            foreach ($movements as $movement) {
                $balance = ErpStockBalance::query()->where('erp_company_id', $model->erp_company_id)->where('erp_establishment_id', $model->erp_establishment_id)->where('erp_product_variant_id', $movement->erp_product_variant_id)->lockForUpdate()->firstOrFail();
                abort_if(ErpDecimal::compare((string) $balance->quantity_on_hand, (string) $movement->quantity) === -1, 422, 'Estoque insuficiente para devolução integral');
                app(ErpStockValuation::class)->assertCompatibleReceipt($balance, (string) $movement->unit_cost);
                abort_if(ErpStockMovement::query()->where('reversal_of_id', $movement->id)->exists(), 422, 'Recebimento já devolvido');
                $balance->update(['quantity_on_hand' => ErpDecimal::sub((string) $balance->quantity_on_hand, (string) $movement->quantity)]);
                ErpStockMovement::query()->create([
                    'erp_company_id' => $model->erp_company_id, 'erp_establishment_id' => $model->erp_establishment_id,
                    'erp_product_variant_id' => $movement->erp_product_variant_id, 'direction' => 'out',
                    'quantity' => (string) $movement->quantity, 'unit_cost' => $movement->unit_cost,
                    'source_type' => 'erp_purchase_return', 'source_id' => $model->id,
                    'source_operation_key' => 'purchase-return-'.$model->id.'-'.$movement->id,
                    'reversal_of_id' => $movement->id, 'posted_by_user_id' => $userId, 'posted_at' => now(),
                ]);
            }
            $model->status = 'posted';
            $model->posted_by_user_id = $userId;
            $model->posted_at = now();
            $model->save();
        });

        return response()->json(['data' => $this->serialize($model->fresh())]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpPurchaseReturn $model */
        return [
            'id' => $model->id,
            'erp_purchase_id' => $model->erp_purchase_id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'status' => $model->status,
            'total_amount' => $this->decimalString($model->total_amount),
            'source_operation_key' => $model->source_operation_key,
        ];
    }
}
