<?php

namespace App\Http\Controllers\Api\V1\Erp\Sales;

use App\Erp\ErpCatalogScope;
use App\Erp\ErpEstablishmentAccess;
use App\Erp\Finance\ErpDecimal;
use App\Erp\Sales\ErpCommercialDocumentStateMachine;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Sales\ErpOrder;
use App\Models\Erp\Sales\ErpQuote;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ErpQuoteController extends ErpOperationsController
{
    public function __construct(
        private ErpCatalogScope $scope,
        private ErpCommercialDocumentStateMachine $stateMachine,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpQuote::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $quote): JsonResponse
    {
        $model = $this->findForCompany($request, ErpQuote::class, $quote);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'erp_establishment_id' => ['required', 'integer'],
            'quote_no' => ['required', 'string', 'max:64'],
            'total_amount' => ['required'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id']);
        app(ErpEstablishmentAccess::class)->assertRelated($context, $data['erp_establishment_id']);
        $this->scope->assertActiveEstablishment($companyId, $data['erp_establishment_id']);

        $quote = ErpQuote::query()->create([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $data['erp_establishment_id'],
            'quote_no' => $data['quote_no'],
            'status' => ErpCommercialDocumentStateMachine::QUOTE_DRAFT,
            'total_amount' => ErpDecimal::parse($data['total_amount']),
            'currency' => $data['currency'] ?? 'BRL',
        ]);

        return response()->json(['data' => $this->serialize($quote)], 201);
    }

    public function convert(Request $request, int $quote): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $model = $this->findForCompany($request, ErpQuote::class, $quote);
        $this->scope->assertActiveEstablishment($companyId, $model->erp_establishment_id);
        $data = $request->validate([
            'order_no' => ['required', 'string', 'max:64'],
        ]);

        try {
            $model->status = $this->stateMachine->convertQuote($model->status);
        } catch (InvalidArgumentException $exception) {
            abort(response()->json(['code' => 'validation_error', 'message' => $exception->getMessage()], 422));
        }

        $result = DB::transaction(function () use ($model, $companyId, $data): array {
            $model->save();
            $order = ErpOrder::query()->create([
                'erp_company_id' => $companyId,
                'erp_establishment_id' => $model->erp_establishment_id,
                'erp_quote_id' => $model->id,
                'order_no' => $data['order_no'],
                'status' => ErpCommercialDocumentStateMachine::ORDER_CONFIRMED,
                'total_amount' => $model->total_amount,
                'currency' => $model->currency,
            ]);

            return ['quote' => $model->fresh(), 'order' => $order];
        });

        return response()->json([
            'data' => [
                'quote' => $this->serialize($result['quote']),
                'order' => [
                    'id' => $result['order']->id,
                    'order_no' => $result['order']->order_no,
                    'status' => $result['order']->status,
                    'total_amount' => $this->decimalString($result['order']->total_amount),
                ],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpQuote $model */
        return [
            'id' => $model->id,
            'erp_establishment_id' => $model->erp_establishment_id,
            'quote_no' => $model->quote_no,
            'status' => $model->status,
            'total_amount' => $this->decimalString($model->total_amount),
            'currency' => $model->currency,
        ];
    }
}
