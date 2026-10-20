<?php

namespace App\Models\Erp\Stock;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpPurchaseReturnLine extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'subtotal_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'unit_conversion_factor' => 'decimal:8',
            'source_snapshot' => 'array',
        ];
    }
}
