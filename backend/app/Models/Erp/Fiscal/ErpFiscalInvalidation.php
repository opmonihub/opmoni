<?php

namespace App\Models\Erp\Fiscal;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpFiscalInvalidation extends Model
{
    protected $fillable = [
        'erp_company_id',
        'erp_establishment_id',
        'model',
        'environment',
        'series',
        'number_from',
        'number_to',
        'status',
        'idempotency_key',
        'justification',
        'protocol',
        'requested_by_user_id',
        'submitted_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
