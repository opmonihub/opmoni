<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpFinancialAccount;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpFinancialAccountController extends ErpOperationsController
{
    public function __construct(private ErpCatalogScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpFinancialAccount::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $financialAccount): JsonResponse
    {
        $model = $this->findForCompany($request, ErpFinancialAccount::class, $financialAccount);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_type' => ['sometimes', 'string', 'max:32'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'erp_establishment_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id'] ?? null);

        $account = ErpFinancialAccount::query()->create([
            ...$data,
            'erp_company_id' => $companyId,
            'status' => 'active',
        ]);

        return response()->json(['data' => $this->serialize($account)], 201);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpFinancialAccount $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'account_type' => $model->account_type,
            'currency' => $model->currency,
            'erp_establishment_id' => $model->erp_establishment_id,
            'status' => $model->status,
        ];
    }
}
