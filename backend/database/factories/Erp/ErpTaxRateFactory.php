<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpTaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ErpTaxRate> */
class ErpTaxRateFactory extends Factory
{
    protected $model = ErpTaxRate::class;

    public function definition(): array
    {
        return [
            'erp_company_id' => ErpCompany::factory(),
            'erp_establishment_id' => null,
            'name' => 'ICMS padrão',
            'rate' => '18.0000',
            'tax_kind' => 'icms',
            'metadata' => null,
            'status' => 'active',
        ];
    }
}
