<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpCommercialSetting> */
class OfficeErpCommercialSettingFactory extends Factory
{
    protected $model = OfficeErpCommercialSetting::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'delinquency_policy' => 'manual',
            'grace_days' => 0,
            'billing_timezone' => 'America/Sao_Paulo',
        ];
    }
}
