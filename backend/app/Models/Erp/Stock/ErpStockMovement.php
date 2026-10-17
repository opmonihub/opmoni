<?php

namespace App\Models\Erp\Stock;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpStockMovement extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'erp_product_variant_id',
        'direction',
        'quantity',
        'unit_cost',
        'valuation_method',
        'source_type',
        'source_id',
        'source_operation_key',
        'reversal_of_id',
        'allocation_snapshot',
        'posted_by_user_id',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'allocation_snapshot' => 'array',
            'posted_at' => 'datetime',
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

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
