<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpFinanceOperation;
use App\Erp\Finance\ErpFinanceReadService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpCashRegister;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCashRegisterController extends ErpOperationsController
{
    public function index(Request $request, ErpFinanceReadService $read): JsonResponse
    {
        return $read->index($request, ErpCashRegister::class);
    }

    public function show(Request $request, int $cashRegister, ErpFinanceReadService $read): JsonResponse
    {
        return response()->json(['data' => $read->serialize($read->resource($request, ErpCashRegister::class, $cashRegister), $request)]);
    }

    public function store(Request $request, ErpOperationJournal $journal): JsonResponse
    {
        return $journal->executeFinance($request, ErpFinanceOperation::REGISTER);
    }
}
