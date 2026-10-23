<?php

namespace App\Models\Erp\Stock;

use Database\Factories\Erp\Stock\ErpStockLotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ErpStockLot extends Model
{
    /** @use HasFactory<ErpStockLotFactory> */
    use HasFactory;

    protected static function newFactory(): ErpStockLotFactory
    {
        return ErpStockLotFactory::new();
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'available_quantity' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'consumed_quantity' => 'decimal:4',
            'consumed_cost' => 'decimal:4',
            'received_at' => 'datetime',
        ];
    }
}
