<?php

namespace App\Models\Erp\Finance;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpFinancialAccount extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'name',
        'account_type',
        'currency',
        'status',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'erp_establishment_id');
    }

    public function obligations(): HasMany
    {
        return $this->hasMany(ErpObligation::class, 'erp_financial_account_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(ErpPayment::class, 'erp_financial_account_id');
    }
}
