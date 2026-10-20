<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCatalogPrinterProfile;
use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErpCatalogPrinterProfile>
 */
class ErpCatalogPrinterProfileFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'name' => fake()->unique()->word(),
            'capability_profile' => 'default',
            'status' => 'active',
        ];
    }
}
