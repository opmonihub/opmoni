<?php

namespace App\Models\Erp\Stock;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpStockTransfer extends Model
{
    protected $fillable = [
        'erp_company_id',
        'from_establishment_id',
        'to_establishment_id',
        'erp_product_variant_id',
        'quantity',
        'status',
        'source_operation_key',
        'posted_by_user_id',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function fromEstablishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'from_establishment_id');
    }

    public function toEstablishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'to_establishment_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
