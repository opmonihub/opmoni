<?php

namespace App\Models\Erp\Finance;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ErpObligation extends Model
{
    protected $fillable = [
        'return_of_id',

        'erp_company_id',
        'erp_establishment_id',
        'erp_contact_id',
        'erp_financial_account_id',
        'direction',
        'category',
        'amount',
        'open_amount',
        'currency',
        'due_on',
        'status',
        'source_type',
        'source_id',
        'source_operation_key',
        'reversal_of_id',
        'posted_by_user_id',
        'party_snapshot',
        'metadata',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'open_amount' => 'decimal:4',
            'due_on' => 'date',
            'party_snapshot' => 'array',
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

    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(ErpFinancialAccount::class, 'erp_financial_account_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ErpPaymentAllocation::class, 'erp_obligation_id');
    }
}
