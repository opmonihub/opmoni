<?php

namespace App\Models\Erp\Finance;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpCashSession extends Model
{
    protected $fillable = [
        'currency',

        'erp_cash_register_id',
        'erp_company_id',
        'erp_establishment_id',
        'operator_user_id',
        'opening_amount',
        'expected_closing_amount',
        'declared_closing_amount',
        'closing_difference',
        'status',
        'close_operation_key',
        'opened_by_user_id',
        'closed_by_user_id',
        'opened_at',
        'closed_at',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opening_amount' => 'decimal:4',
            'expected_closing_amount' => 'decimal:4',
            'declared_closing_amount' => 'decimal:4',
            'closing_difference' => 'decimal:4',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(ErpCashRegister::class, 'erp_cash_register_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(ErpEstablishment::class, 'erp_establishment_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }
}
