<?php

namespace Database\Factories\Erp\Sales;

use App\Models\Erp\Sales\ErpQuote;
use App\Models\Erp\Sales\ErpQuoteLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ErpQuoteLine>
 */
class ErpQuoteLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ordinal' => 1, 'quantity' => '1.0000', 'base_quantity' => '1.0000',
            'unit_price' => '10.0000', 'gross_amount' => '10.0000',
            'discount_amount' => '0.0000', 'tax_amount' => '0.0000',
            'line_total' => '10.0000', 'unit_conversion_factor' => '1.00000000',
            'product_snapshot' => ['version' => 'commercial-v1'],
        ];
    }

    public function forDocument(ErpQuote $document): static
    {
        return $this->state(['erp_quote_id' => $document->id, 'erp_company_id' => $document->erp_company_id]);
    }
}
