<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpPlanModule> */
class ErpPlanModuleFactory extends Factory
{
    protected $model = ErpPlanModule::class;

    public function definition(): array
    {
        return [
            'erp_plan_id' => ErpPlan::factory(),
            'module_key' => 'purchases',
            'quota' => null,
        ];
    }
}
