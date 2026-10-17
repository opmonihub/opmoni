<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogScope;
use App\Models\Erp\ErpCarrier;
use App\Models\Erp\ErpVehicle;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpVehicleController extends ErpCatalogController
{
    public function __construct(private ErpCatalogScope $scope) {}

    protected function modelClass(): string
    {
        return ErpVehicle::class;
    }

    protected function searchableColumns(): array
    {
        return ['plate', 'model', 'brand'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpVehicle $model */
        return [
            'id' => $model->id,
            'erp_carrier_id' => $model->erp_carrier_id,
            'vehicle_type' => $model->vehicle_type,
            'plate' => $model->plate,
            'uf' => $model->uf,
            'color' => $model->color,
            'model' => $model->model,
            'brand' => $model->brand,
            'wheel_type' => $model->wheel_type,
            'body_type' => $model->body_type,
            'owner_type' => $model->owner_type,
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request, $context->company->id);
        $row = ErpVehicle::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

        return response()->json(['data' => $this->serialize($row)], 201);
    }

    public function update(Request $request, int $vehicle): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $vehicle);
        $data = $this->validated($request, $context->company->id, partial: true);
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, int $companyId, bool $partial = false): array
    {
        $data = $request->validate([
            'erp_carrier_id' => ['sometimes', 'nullable', 'integer'],
            'vehicle_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'plate' => [$partial ? 'sometimes' : 'required', 'string', 'max:16'],
            'uf' => ['sometimes', 'nullable', 'string', 'size:2'],
            'color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'model' => ['sometimes', 'nullable', 'string', 'max:64'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:64'],
            'wheel_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'body_type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'owner_type' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        if (! empty($data['erp_carrier_id'])) {
            $this->scope->assertBelongsToCompany(ErpCarrier::class, $companyId, (int) $data['erp_carrier_id'], 'erp_carrier_id');
        }

        return $data;
    }
}
