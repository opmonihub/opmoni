<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpOperationJournal;
use App\Erp\Finance\ErpCashLedger;
use App\Erp\Finance\ErpFinanceOperation;
use App\Erp\Finance\ErpFinanceReadService;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpCashMovement;
use App\Models\Erp\Finance\ErpCashSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCashSessionController extends ErpOperationsController
{
    public function index(Request $request, ErpFinanceReadService $read): JsonResponse
    {
        return $read->index($request, ErpCashSession::class);
    }

    public function show(Request $request, int $cashSession, ErpFinanceReadService $read): JsonResponse
    {
        return response()->json(['data' => $read->serialize($read->resource($request, ErpCashSession::class, $cashSession), $request)]);
    }

    public function store(Request $request, ErpOperationJournal $journal): JsonResponse
    {
        return $journal->executeFinance($request, ErpFinanceOperation::OPEN);
    }

    public function close(Request $request, int|string $cashSession, ErpOperationJournal $journal): JsonResponse
    {
        return $journal->executeFinance($request, ErpFinanceOperation::CLOSE, $cashSession);
    }

    public function movements(Request $request, int $cashSession, ErpFinanceReadService $read): JsonResponse
    {
        $session = $read->resource($request, ErpCashSession::class, $cashSession);

        return $read->page($request, ErpCashMovement::where('erp_company_id', $this->companyId($request))->where('erp_cash_session_id', $session->id), ['totals' => app(ErpCashLedger::class)->totals($session)]);
    }

    public function storeMovement(Request $request, int|string $cashSession, ErpOperationJournal $journal): JsonResponse
    {
        return $journal->executeFinance($request, ErpFinanceOperation::MOVEMENT, $cashSession);
    }
}
