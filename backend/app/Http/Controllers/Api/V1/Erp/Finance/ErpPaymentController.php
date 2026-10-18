<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Finance\ErpFinanceParticipant;
use App\Erp\Finance\ErpPaymentAllocationService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ErpPaymentController extends ErpOperationsController
{
    public function __construct(
        private ErpCatalogScope $scope,
        private ErpPaymentAllocationService $allocations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpPayment::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $payment): JsonResponse
    {
        $model = $this->findForCompany($request, ErpPayment::class, $payment);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $data = $request->validate([
            'amount' => ['required'],
            'method' => ['required', 'string', 'max:32'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
            'erp_financial_account_id' => ['sometimes', 'nullable', 'integer'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $data = $this->normalizeIdentifiers($data);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id'] ?? null);
        $this->scope->assertBelongsToCompany(
            ErpFinancialAccount::class,
            $companyId,
            $data['erp_financial_account_id'] ?? null,
            'erp_financial_account_id',
        );

        $amount = ErpDecimal::parse($data['amount']);
        abort_unless(ErpDecimal::isPositive($amount), 422, 'Valor deve ser positivo');
        $operationKey = $data['source_operation_key'] ?? null;
        if ($operationKey !== null) {
            $existing = ErpPayment::query()
                ->where('erp_company_id', $companyId)
                ->where('source_operation_key', $operationKey)
                ->first();
            if ($existing) {
                abort_unless(ErpDecimal::compare((string) $existing->amount, $amount) === 0 && $existing->method === $data['method'] && $existing->currency === ($data['currency'] ?? 'BRL') && $existing->erp_establishment_id === ($data['erp_establishment_id'] ?? null) && $existing->erp_financial_account_id === ($data['erp_financial_account_id'] ?? null), 409, 'Chave de operação reutilizada com conteúdo diferente');

                return response()->json(['data' => $this->serialize($existing)]);
            }
        }

        $payment = ErpPayment::query()->create([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $data['erp_establishment_id'] ?? null,
            'erp_financial_account_id' => $data['erp_financial_account_id'] ?? null,
            'amount' => $amount,
            'currency' => $data['currency'] ?? 'BRL',
            'method' => $data['method'],
            'status' => 'posted',
            'source_type' => 'manual',
            'source_id' => 0,
            'source_operation_key' => $data['source_operation_key'] ?? null,
            'posted_by_user_id' => $userId,
            'posted_at' => now(),
        ]);

        return response()->json(['data' => $this->serialize($payment)], 201);
    }

    public function allocate(Request $request, int $payment): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $userId = $request->user()?->id;
        $paymentModel = $this->findForCompany($request, ErpPayment::class, $payment);
        $data = $request->validate([
            'erp_obligation_id' => ['required', 'integer'],
            'amount' => ['required'],
            'source_operation_key' => ['sometimes', 'nullable', 'string', 'max:128'],
        ]);
        $data = $this->normalizeIdentifiers($data);

        $obligation = ErpObligation::query()
            ->where('erp_company_id', $companyId)
            ->whereKey($data['erp_obligation_id'])
            ->firstOrFail();

        $allocationAmount = ErpDecimal::parse($data['amount']);
        try {
            $allocation = DB::transaction(fn (): ErpPaymentAllocation => app(ErpFinanceParticipant::class)->allocatePayment(
                $companyId, $paymentModel->id, $obligation->id, $allocationAmount,
                $data['source_operation_key'] ?? ('allocation-'.Str::uuid()),
                $userId, $this->allocations,
            ));
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json([
            'data' => [
                'id' => $allocation->id,
                'erp_payment_id' => $allocation->erp_payment_id,
                'erp_obligation_id' => $allocation->erp_obligation_id,
                'amount' => $this->decimalString($allocation->amount),
                'obligation_open_amount' => $this->decimalString($obligation->fresh()->open_amount),
                'obligation_status' => $obligation->fresh()->status,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpPayment $model */
        return [
            'id' => $model->id,
            'amount' => $this->decimalString($model->amount),
            'currency' => $model->currency,
            'method' => $model->method,
            'status' => $model->status,
            'erp_establishment_id' => $model->erp_establishment_id,
            'erp_financial_account_id' => $model->erp_financial_account_id,
            'source_operation_key' => $model->source_operation_key,
        ];
    }
}
