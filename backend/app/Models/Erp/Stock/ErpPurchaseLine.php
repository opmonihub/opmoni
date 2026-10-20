<?php

namespace App\Models\Erp\Stock;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpPurchaseLine extends Model
{
    protected $fillable = [
        'erp_purchase_id',
        'erp_product_variant_id',
        'quantity',
        'unit_cost',
        'line_total',
        'unit_conversion_factor',
        'product_snapshot', 'ordinal', 'source_ordinal', 'description', 'erp_unit_id', 'base_quantity',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'base_quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
            'unit_conversion_factor' => 'decimal:8',
            'product_snapshot' => 'array',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(ErpPurchase::class, 'erp_purchase_id');
    }
}
