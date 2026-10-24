<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpOperationNature;
use App\Models\Erp\ErpOperationNatureCfopChoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpOperationNatureCfopChoice> */
class ErpOperationNatureCfopChoiceFactory extends Factory
{
    protected $model = ErpOperationNatureCfopChoice::class;

    public function definition(): array
    {
        return ['erp_operation_nature_id' => ErpOperationNature::factory(), 'erp_company_id' => fn (array $attributes) => ErpOperationNature::query()->findOrFail($attributes['erp_operation_nature_id'])->erp_company_id, 'cfop' => '5102', 'reference_version' => 'IT 2023.002 v2.10', 'status' => 'active'];
    }
}
