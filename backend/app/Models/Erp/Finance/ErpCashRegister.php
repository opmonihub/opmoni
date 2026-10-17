<?php

namespace App\Models\Erp\Finance;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpCashRegister extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'name',
        'status',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'erp_establishment_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ErpCashSession::class, 'erp_cash_register_id');
    }
}
