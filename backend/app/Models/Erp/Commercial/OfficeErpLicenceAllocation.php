<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\ErpCompany;
use Database\Factories\Erp\Commercial\OfficeErpLicenceAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeErpLicenceAllocation extends Model
{
    /** @use HasFactory<OfficeErpLicenceAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id', 'contract_id', 'erp_company_id', 'state', 'expires_at', 'released_at',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'released_at' => 'datetime'];
    }

    protected static function newFactory(): OfficeErpLicenceAllocationFactory
    {
        return OfficeErpLicenceAllocationFactory::new();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OfficeErpContract::class, 'contract_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }
}
