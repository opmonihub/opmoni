<?php

namespace App\Models\Erp\Sales;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpSaleLine extends Model
{
    protected $fillable = [
        'erp_sale_id',
        'erp_product_variant_id',
        'quantity',
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
            'quantity' => 'decimal:4',
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
