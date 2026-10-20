<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpFinanceOperation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpFinancialReversalController extends Controller
{
    public function allocation(Request $request, int|string $allocation, ErpOperationJournal $journal): JsonResponse
    {
        return $journal->executeFinance($request, ErpFinanceOperation::ALLOCATION_REVERSE, $allocation);
    }

    public function payment(Request $request, int|string $payment, ErpOperationJournal $journal): JsonResponse
    {
        return $journal->executeFinance($request, ErpFinanceOperation::PAYMENT_REVERSE, $payment);
    }
}
