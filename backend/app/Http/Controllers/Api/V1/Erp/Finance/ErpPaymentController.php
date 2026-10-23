<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Enums\Erp\ErpEstablishmentResource;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpFinanceOperation;
use App\Erp\Finance\ErpFinanceReadService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpObligation;
use App\Models\Erp\Finance\ErpPayment;
use App\Models\Erp\Finance\ErpPaymentAllocation;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ErpPaymentController extends ErpOperationsController
{
    public function index(Request $request, ErpFinanceReadService $read): JsonResponse
    {
        return $read->index($request, ErpPayment::class);
    }

    public function show(Request $request, int $payment, ErpFinanceReadService $read): JsonResponse
    {
        return response()->json(['data' => $read->serialize($read->resource($request, ErpPayment::class, $payment), $request)]);
    }

    public function store(Request $request, ErpOperationJournal $journal): JsonResponse
    {
        return $journal->executeFinance($request, ErpFinanceOperation::PAYMENT);
    }

    public function allocate(Request $request, int|string $payment): JsonResponse
    {
        try {
            $result = app(ErpOperationJournal::class)->execute($request, $payment);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        return response()->json($result['body'], $result['status']);
    }

    public function allocations(Request $request, int $payment, ErpFinanceReadService $read): JsonResponse
    {
        $read->resource($request, ErpPayment::class, $payment);
        $query = ErpPaymentAllocation::where('erp_payment_id', $payment)->whereHas('obligation', function (Builder $query) use ($request): void {
            $query->where('erp_company_id', $this->companyId($request));
            app(ErpEstablishmentAccess::class)->apply($query, CurrentCompany::fromRequest($request), ErpEstablishmentResource::Obligation);
        });

        return $read->page($request, $query);
    }

    public function candidates(Request $request, int $payment, ErpFinanceReadService $read): JsonResponse
    {
        CurrentCompany::fromRequest($request)->assertPermission('erp.payments.allocate', true);
        $read->resource($request, ErpPayment::class, $payment);
        $request->merge(['eligible_for_payment_id' => $payment]);

        return $read->index($request, ErpObligation::class);
    }

    public function receipt(Request $request, int $payment, ErpFinanceReadService $read): JsonResponse
    {
        $model = $read->resource($request, ErpPayment::class, $payment);
        $read->assertPaymentReferences($request, $model);
        $facts = $read->serialize($model, $request);
        unset($facts['source_operation_key']);

        return response()->json(['data' => ['version' => 1, 'reference' => 'ERP-PAYMENT-'.$model->id, 'company' => ['id' => $model->erp_company_id, 'name' => CurrentCompany::fromRequest($request)->company->name], ...$facts]], 200, ['Cache-Control' => 'private, no-store', 'Content-Disposition' => 'attachment; filename="recibo-'.$model->id.'.json"']);
    }
}
