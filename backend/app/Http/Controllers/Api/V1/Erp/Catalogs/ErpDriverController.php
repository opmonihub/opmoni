<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Models\Erp\ErpDriver;
use App\Services\BrazilianTaxId;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpDriverController extends ErpCatalogController
{
    public function __construct(private BrazilianTaxId $taxId) {}

    protected function modelClass(): string
    {
        return ErpDriver::class;
    }

    protected function searchableColumns(): array
    {
        return ['name', 'tax_id', 'license_number'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpDriver $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'tax_id' => $model->tax_id,
            'license_number' => $model->license_number,
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request);
        $row = ErpDriver::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

        return response()->json(['data' => $this->serialize($row)], 201);
    }

    public function update(Request $request, int $driver): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $driver);
        $data = $this->validated($request, partial: true);
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);
        if (isset($data['tax_id']) && is_string($data['tax_id']) && $data['tax_id'] !== '') {
            $data['tax_id'] = $this->taxId->normalize($data['tax_id']);
        }

        return $data;
    }
}
