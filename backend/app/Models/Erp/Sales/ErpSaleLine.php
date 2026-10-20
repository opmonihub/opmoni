<?php

namespace App\Models\Erp\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpSaleLine extends Model
{
    protected $fillable = [
        'ordinal', 'erp_unit_id', 'gross_amount', 'base_quantity', 'source_quote_line_id', 'source_order_line_id',
        'erp_company_id', 'source_quote_document_id', 'source_order_document_id', 'erp_sale_id',
        'erp_product_variant_id',
        'quantity', 'unit_conversion_factor',
        'unit_price',
        'discount_amount',
        'tax_amount',
        'line_total',
        'product_snapshot',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:4', 'base_quantity' => 'decimal:4',
            'quantity' => 'decimal:4', 'unit_conversion_factor' => 'decimal:8',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'product_snapshot' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(ErpSale::class, 'erp_sale_id');
    }
}
