<?php

namespace App\Models\Erp\Sales;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpQuoteLine extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'base_quantity' => 'decimal:4',
            'unit_conversion_factor' => 'decimal:8',
            'product_snapshot' => 'array',
        ];
    }
}
