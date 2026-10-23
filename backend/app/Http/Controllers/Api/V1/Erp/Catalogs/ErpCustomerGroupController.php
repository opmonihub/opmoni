<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogDecimal;
use App\Models\Erp\ErpCustomerGroup;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ErpCustomerGroupController extends ErpCatalogController
{
    protected function modelClass(): string
    {
        return ErpCustomerGroup::class;
    }

    protected function searchableColumns(): array
    {
        return ['name'];
    }

    protected function serialize(Model $model): array
    {
        /** @var ErpCustomerGroup $model */
        return [
            'id' => $model->id,
            'name' => $model->name,
            'percentage_adjustment' => $this->decimalString($model->percentage_adjustment),
            'status' => $model->status,
        ];
    }

    public function store(Request $request): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $this->validated($request);
            $this->assertNameAvailable($data['name'], (int) $context->company->id);
            $group = ErpCustomerGroup::query()->create([
                ...$data,
                'erp_company_id' => $context->company->id,
                'status' => 'active',
            ]);
            $this->record($request, $context, $group, 'created');
            $group->refresh();

            return response()->json(['data' => $this->serialize($group)], 201);
        });
    }

    public function update(Request $request, int $customerGroup): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $customerGroup): JsonResponse {
            $model = $this->findForCompany($request, $customerGroup);
            $data = $this->validated($request, partial: true);
            if (isset($data['name'])) {
                $this->assertNameAvailable($data['name'], (int) $context->company->id, $model);
            }
            $model->fill($data);
            $model->save();
            $this->record($request, $context, $model, 'updated');

            return response()->json(['data' => $this->serialize($model->fresh())]);
        });
    }

    /**
     * Omitted adjustments use the database default on create and remain unchanged on PATCH.
     * Explicit null is invalid, matching the price-group decimal contract.
     *
     * @return array{name?: string, percentage_adjustment?: string}
     */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'percentage_adjustment' => ['sometimes', ...ErpCatalogDecimal::widthRule(6, 4, signed: true)],
        ]);
    }

    private function assertNameAvailable(string $name, int $companyId, ?ErpCustomerGroup $current = null): void
    {
        $normalized = mb_strtolower($name);
        if ($current !== null && mb_strtolower($current->name) === $normalized) {
            return;
        }
        if (ErpCustomerGroup::query()
            ->where('erp_company_id', $companyId)
            ->where('status', 'active')
            ->whereRaw('lower(name) = ?', [$normalized])
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->exists()) {
            throw ValidationException::withMessages(['name' => 'Este grupo de clientes já existe nesta empresa.']);
        }
    }
}
