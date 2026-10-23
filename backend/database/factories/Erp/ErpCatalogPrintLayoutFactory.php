<?php

namespace Database\Factories\Erp;

use App\Models\Erp\ErpCatalogPrintLayout;
use App\Models\Erp\ErpCompany;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErpCatalogPrintLayout>
 */
class ErpCatalogPrintLayoutFactory extends Factory
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
            'name' => fake()->unique()->words(2, true),
            'description' => null,
            'label_width_mm' => '40.0000',
            'label_height_mm' => '25.0000',
            'paper_width_mm' => '120.0000',
            'paper_height_mm' => '100.0000',
            'top_margin_mm' => '2.0000',
            'left_margin_mm' => '2.0000',
            'row_spacing_mm' => '1.0000',
            'column_spacing_mm' => '1.0000',
            'columns_per_row' => 2,
            'labels_per_sheet' => 4,
            'is_continuous' => false,
            'is_default' => false,
            'status' => 'active',
        ];
    }
}
