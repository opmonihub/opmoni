<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogScope;
use App\Models\Erp\ErpContact;
use App\Services\BrazilianTaxId;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpContactController extends ErpCatalogController
{
    public function __construct(
        private ErpCatalogScope $scope,
        private BrazilianTaxId $taxId,
    ) {}

    protected function modelClass(): string
    {
        return ErpContact::class;
    }

    protected function searchableColumns(): array
    {
        return ['name', 'trade_name', 'tax_id', 'email'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpContact $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'trade_name' => $model->trade_name,
            'tax_id' => $model->tax_id,
            'email' => $model->email,
            'phone' => $model->phone,
            'is_customer' => $model->is_customer,
            'is_supplier' => $model->is_supplier,
            'address' => $model->address,
            'notes' => $model->notes,
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request);
        $contact = ErpContact::query()->create([
            ...$data,
            'erp_company_id' => $context->company->id,
            'status' => 'active',
        ]);

        return response()->json(['data' => $this->serialize($contact)], 201);
    }

    public function update(Request $request, int $contact): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $contact);
        $data = $this->validated($request, partial: true);
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tax_id' => ['sometimes', 'nullable', 'string', 'max:20'],
            'email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'is_customer' => ['sometimes', 'boolean'],
            'is_supplier' => ['sometimes', 'boolean'],
            'address' => ['sometimes', 'nullable', 'array'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
        $data = $request->validate($rules);
        if (isset($data['tax_id']) && is_string($data['tax_id']) && $data['tax_id'] !== '') {
            $data['tax_id'] = $this->taxId->normalize($data['tax_id']);
        }

        return $data;
    }
}
