<?php

namespace App\Models\Erp\Stock;

use Database\Factories\Erp\Stock\ErpStockCostSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpStockCostSettlement extends Model
{
    /** @use HasFactory<ErpStockCostSettlementFactory> */
    use HasFactory;

    protected static function newFactory(): ErpStockCostSettlementFactory
    {
        return ErpStockCostSettlementFactory::new();
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'cost' => 'decimal:4',
            'returned_quantity' => 'decimal:4',
            'returned_cost' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }
}
