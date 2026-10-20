<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpFinanceOperation;
use App\Erp\Finance\ErpFinanceReadService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpFinancialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpFinancialAccountController extends ErpOperationsController
{
    public function index(Request $request, ErpFinanceReadService $read): JsonResponse
    {
        return $read->index($request, ErpFinancialAccount::class);
    }

    public function show(Request $request, int $financialAccount, ErpFinanceReadService $read): JsonResponse
    {
        return response()->json(['data' => $read->serialize($read->resource($request, ErpFinancialAccount::class, $financialAccount), $request)]);
    }

    public function store(Request $request, ErpOperationJournal $journal): JsonResponse
    {
        return $journal->executeFinance($request, ErpFinanceOperation::ACCOUNT);
    }
}
