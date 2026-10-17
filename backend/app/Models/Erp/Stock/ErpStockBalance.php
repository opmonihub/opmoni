<?php

namespace App\Models\Erp\Stock;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpStockBalance extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'erp_product_variant_id',
        'quantity_on_hand',
        'average_cost',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'average_cost' => 'decimal:4',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'erp_establishment_id');
    }
}
