<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Models\Erp\ErpCarrier;
use App\Services\BrazilianTaxId;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpCarrierController extends ErpCatalogController
{
    public function __construct(private BrazilianTaxId $taxId) {}

    protected function modelClass(): string
    {
        return ErpCarrier::class;
    }

    protected function searchableColumns(): array
    {
        return ['legal_name', 'tax_id'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpCarrier $model */
        return [
            'id' => $model->id,
            'legal_name' => $model->legal_name,
            'tax_id' => $model->tax_id,
            'ie' => $model->ie,
            'address' => $model->address,
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request);
        $row = ErpCarrier::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

        return response()->json(['data' => $this->serialize($row)], 201);
    }

    public function update(Request $request, int $carrier): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $carrier);
        $data = $this->validated($request, partial: true);
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $data = $request->validate([
            'legal_name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'ie' => ['sometimes', 'nullable', 'string', 'max:20'],
            'address' => ['sometimes', 'nullable', 'array'],
        ]);
        if (isset($data['tax_id']) && is_string($data['tax_id']) && $data['tax_id'] !== '') {
            $data['tax_id'] = $this->taxId->normalize($data['tax_id']);
        }

        return $data;
    }
}
