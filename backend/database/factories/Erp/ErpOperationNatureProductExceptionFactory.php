<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpOperationNatureCfopChoice;
use App\Models\Erp\ErpOperationNatureProductException;
use App\Models\Erp\ErpProduct;
use App\Models\Erp\ErpUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpOperationNatureProductException> */
class ErpOperationNatureProductExceptionFactory extends Factory
{
    protected $model = ErpOperationNatureProductException::class;

    public function definition(): array
    {
        return [
            'erp_operation_nature_cfop_choice_id' => ErpOperationNatureCfopChoice::factory(),
            'erp_operation_nature_id' => fn (array $attributes) => ErpOperationNatureCfopChoice::query()->findOrFail($attributes['erp_operation_nature_cfop_choice_id'])->erp_operation_nature_id,
            'erp_company_id' => fn (array $attributes) => ErpOperationNatureCfopChoice::query()->findOrFail($attributes['erp_operation_nature_cfop_choice_id'])->erp_company_id,
            'erp_product_id' => fn (array $attributes) => ErpProduct::factory()->create(['erp_company_id' => $attributes['erp_company_id'], 'erp_unit_id' => ErpUnit::factory()->create(['erp_company_id' => $attributes['erp_company_id']])->id])->id,
            'status' => 'active',
        ];
    }
}
