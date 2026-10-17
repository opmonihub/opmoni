<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Models\Erp\ErpPriceGroup;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ErpPriceGroupController extends ErpCatalogController
{
    protected function modelClass(): string
    {
        return ErpPriceGroup::class;
    }

    protected function searchableColumns(): array
    {
        return ['name'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpPriceGroup $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'is_default' => $model->is_default,
            'percentage_adjustment' => $this->decimalString($model->percentage_adjustment),
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $data = $this->validated($request);
        $row = DB::transaction(function () use ($data, $context): ErpPriceGroup {
            if (! empty($data['is_default'])) {
                ErpPriceGroup::query()->where('erp_company_id', $context->company->id)->update(['is_default' => false]);
            }

            return ErpPriceGroup::query()->create([
                ...$data,
                'erp_company_id' => $context->company->id,
                'status' => 'active',
            ]);
        });

        return response()->json(['data' => $this->serialize($row)], 201);
    }

    public function update(Request $request, int $priceGroup): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        $context->assertCanMutate();
        $model = $this->findForCompany($request, $priceGroup);
        $data = $this->validated($request, partial: true);
        DB::transaction(function () use ($data, $model, $context): void {
            if (! empty($data['is_default'])) {
                ErpPriceGroup::query()->where('erp_company_id', $context->company->id)->whereKeyNot($model->id)->update(['is_default' => false]);
            }
            $model->fill($data);
            $model->save();
        });

        return response()->json(['data' => $this->serialize($model->fresh())]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'percentage_adjustment' => ['sometimes', 'regex:/^-?\d+(\.\d{1,4})?$/'],
        ]);
    }
}
