<?php

namespace App\Models\Erp;

use Database\Factories\Erp\ErpProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpProductVariant extends Model
{
    /** @use HasFactory<ErpProductVariantFactory> */
    use HasFactory;

    protected $fillable = [
        'erp_product_id', 'sku', 'name', 'sale_price', 'barcode', 'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sale_price' => 'decimal:4',
        ];
    }

    protected static function newFactory(): ErpProductVariantFactory
    {
        return ErpProductVariantFactory::new();
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ErpProduct::class, 'erp_product_id');
    }
}
