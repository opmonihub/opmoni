<?php

namespace App\Models\Erp\Stock;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpPurchase extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'erp_contact_id',
        'document_no',
        'status',
        'total_amount',
        'currency',
        'source_operation_key',
        'posted_by_user_id',
        'supplier_snapshot',
        'metadata',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:4',
            'supplier_snapshot' => 'array',
            'metadata' => 'array',
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

    public function lines(): HasMany
    {
        return $this->hasMany(ErpPurchaseLine::class, 'erp_purchase_id');
    }

    public function returns(): HasMany
    {
        return $this->hasMany(ErpPurchaseReturn::class, 'erp_purchase_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
