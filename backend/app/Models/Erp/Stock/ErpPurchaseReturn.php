<?php

namespace App\Models\Erp\Stock;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpPurchaseReturn extends Model
{
    protected $fillable = [
        'commercial_snapshot', 'reason', 'currency', 'subtotal_amount', 'discount_amount', 'tax_amount',
        'erp_purchase_id',
        'erp_company_id',
        'erp_establishment_id',
        'status',
        'total_amount',
        'source_operation_key',
        'posted_by_user_id',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'commercial_snapshot' => 'array',
            'subtotal_amount' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_amount' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(ErpPurchase::class, 'erp_purchase_id');
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

    public function lines(): HasMany
    {
        return $this->hasMany(ErpPurchaseReturnLine::class, 'erp_purchase_return_id');
    }
}
