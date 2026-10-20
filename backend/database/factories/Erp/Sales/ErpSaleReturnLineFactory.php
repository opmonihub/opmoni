<?php

namespace Database\Factories\Erp\Sales;

use App\Models\Erp\Sales\ErpSaleLine;
use App\Models\Erp\Sales\ErpSaleReturn;
use App\Models\Erp\Sales\ErpSaleReturnLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErpSaleReturnLine>
 */
class ErpSaleReturnLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => '1.0000', 'base_quantity' => '1.0000',
            'unit_conversion_factor' => '1.00000000', 'subtotal_amount' => '10.0000',
            'discount_amount' => '0.0000', 'tax_amount' => '0.0000', 'total_amount' => '10.0000',
            'source_snapshot' => ['version' => 'commercial-v1'],
        ];
    }

    public function forSource(ErpSaleReturn $document, ErpSaleLine $line): static
    {
        return $this->state(['erp_sale_return_id' => $document->id, 'source_document_id' => $document->erp_sale_id, 'source_line_id' => $line->id, 'erp_company_id' => $document->erp_company_id]);
    }
}
