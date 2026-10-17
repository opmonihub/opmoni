<?php

namespace Database\Factories\Erp;

use App\Enums\Erp\ErpSubscriptionStatus;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpSubscription> */
class ErpSubscriptionFactory extends Factory
{
    protected $model = ErpSubscription::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'erp_plan_id' => ErpPlan::factory(),
            'status' => ErpSubscriptionStatus::Pending->value,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => ErpSubscriptionStatus::Active->value]);
    }
}
