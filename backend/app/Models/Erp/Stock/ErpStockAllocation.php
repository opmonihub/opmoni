<?php

namespace App\Models\Erp\Stock;

use Database\Factories\Erp\Stock\ErpStockAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpStockAllocation extends Model
{
    /** @use HasFactory<ErpStockAllocationFactory> */
    use HasFactory;

    protected static function newFactory(): ErpStockAllocationFactory
    {
        return ErpStockAllocationFactory::new();
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'cost' => 'decimal:4',
            'returned_quantity' => 'decimal:4',
            'returned_cost' => 'decimal:4',
            'settled_quantity' => 'decimal:4',
            'issued_at' => 'datetime',
        ];
    }
}
