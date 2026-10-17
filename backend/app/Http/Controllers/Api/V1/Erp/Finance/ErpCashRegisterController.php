<?php

namespace App\Http\Controllers\Api\V1\Erp\Finance;

use App\Erp\ErpCatalogScope;
use App\Http\Controllers\Api\V1\Erp\ErpOperationsController;
use App\Models\Erp\Finance\ErpCashRegister;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCashRegisterController extends ErpOperationsController
{
    public function __construct(private ErpCatalogScope $scope) {}

    public function index(Request $request): JsonResponse
    {
        $query = ErpCashRegister::query()->where('erp_company_id', $this->companyId($request));

        return $this->paginated($request, $query, fn (Model $model): array => $this->serialize($model));
    }

    public function show(Request $request, int $cashRegister): JsonResponse
    {
        $model = $this->findForCompany($request, ErpCashRegister::class, $cashRegister);

        return response()->json(['data' => $this->serialize($model)]);
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $companyId = $context->company->id;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'erp_establishment_id' => ['required', 'integer'],
        ]);
        $this->scope->assertEstablishmentBelongsToCompany($companyId, $data['erp_establishment_id']);

        $register = ErpCashRegister::query()->create([
            'erp_company_id' => $companyId,
            'erp_establishment_id' => $data['erp_establishment_id'],
            'name' => $data['name'],
            'status' => 'active',
        ]);

        return response()->json(['data' => $this->serialize($register)], 201);
    }

    /** @return array<string, mixed> */
    private function serialize(Model $model): array
    {
        /** @var ErpCashRegister $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'erp_establishment_id' => $model->erp_establishment_id,
            'status' => $model->status,
        ];
    }
}
