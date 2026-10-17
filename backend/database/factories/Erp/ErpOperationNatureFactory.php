<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpOperationNature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpOperationNature> */
class ErpOperationNatureFactory extends Factory
{
    protected $model = ErpOperationNature::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'erp_establishment_id' => null,
            'cfop' => '5102',
            'description' => 'Venda de mercadoria',
            'movement' => 'out',
            'fiscal_metadata' => null,
            'status' => 'active',
        ];
    }
}
