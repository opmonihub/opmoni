<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Models\Erp\ErpDiscountRule;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpDiscountRuleController extends ErpCatalogController
{
    protected function modelClass(): string
    {
        return ErpDiscountRule::class;
    }

    protected function searchableColumns(): array
    {
        return ['name', 'coupon_code'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpDiscountRule $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'kind' => $model->kind,
            'value' => $this->decimalString($model->value),
            'coupon_code' => $model->coupon_code,
            'conditions' => $model->conditions,
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request);
        $row = ErpDiscountRule::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

        return response()->json(['data' => $this->serialize($row)], 201);
    }

    public function update(Request $request, int $discountRule): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $discountRule);
        $data = $this->validated($request, partial: true);
        $model->fill($data);
        $model->save();

        return response()->json(['data' => $this->serialize($model)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => [$partial ? 'sometimes' : 'required', 'in:percentage,fixed'],
            'value' => [$partial ? 'sometimes' : 'required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'conditions' => ['sometimes', 'nullable', 'array'],
        ]);
    }
}
