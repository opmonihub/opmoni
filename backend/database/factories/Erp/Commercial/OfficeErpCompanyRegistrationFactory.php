<?php

namespace Database\Factories\Erp\Commercial;

use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<OfficeErpCompanyRegistration> */
class OfficeErpCompanyRegistrationFactory extends Factory
{
    protected $model = OfficeErpCompanyRegistration::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory()->company(),
            'account_id' => fn (array $attributes): int => (int) Client::withoutGlobalScopes()->findOrFail($attributes['client_id'])->account_id,
            'erp_company_id' => ErpCompany::factory(),
            'registered_at' => now(),
        ];
    }
}
