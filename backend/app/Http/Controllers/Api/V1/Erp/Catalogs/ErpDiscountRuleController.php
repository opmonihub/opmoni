<?php

namespace App\Http\Controllers\Api\V1\Erp\Catalogs;

use App\Erp\ErpCatalogDecimal;
use App\Models\Erp\ErpDiscountRule;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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
        return $this->write($request, function (CurrentCompany $context) use ($request): JsonResponse {
            $data = $this->validated($request);
            $this->assertCouponAvailable($data['coupon_code'] ?? null, (int) $context->company->id);
            $row = ErpDiscountRule::query()->create([...$data, 'erp_company_id' => $context->company->id, 'status' => 'active']);

            return response()->json(['data' => $this->serialize($row)], 201);
        });
    }

    public function update(Request $request, int $discountRule): JsonResponse
    {
        return $this->write($request, function (CurrentCompany $context) use ($request, $discountRule): JsonResponse {
            $model = $this->findForCompany($request, $discountRule);
            $data = $this->validated($request, partial: true);
            if (array_key_exists('coupon_code', $data)) {
                $this->assertCouponAvailable($data['coupon_code'], (int) $context->company->id, $model);
            }
            $model->fill($data);
            $model->save();

            return response()->json(['data' => $this->serialize($model->fresh())]);
        });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        return $request->validate([
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'kind' => [$partial ? 'sometimes' : 'required', 'in:percentage,fixed'],
            'value' => [$partial ? 'sometimes' : 'required', ...ErpCatalogDecimal::widthRule(16, 4)],
            'coupon_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'conditions' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    private function assertCouponAvailable(?string $coupon, int $companyId, ?ErpDiscountRule $current = null): void
    {
        if ($coupon === null || $coupon === '') {
            return;
        }
        if ($current !== null && $current->coupon_code !== null && mb_strtolower($current->coupon_code) === mb_strtolower($coupon)) {
            return;
        }
        if (ErpDiscountRule::query()
            ->where('erp_company_id', $companyId)
            ->where('status', 'active')
            ->whereRaw('lower(coupon_code) = ?', [mb_strtolower($coupon)])
            ->when($current, fn ($query) => $query->whereKeyNot($current->id))
            ->exists()) {
            throw ValidationException::withMessages(['coupon_code' => 'Este cupom já está em uso nesta empresa.']);
        }
    }
}
