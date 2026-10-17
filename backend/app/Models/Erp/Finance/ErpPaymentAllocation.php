<?php

namespace App\Models\Erp\Finance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpPaymentAllocation extends Model
{
    protected $fillable = [
        'erp_payment_id',
        'erp_obligation_id',
        'amount',
        'source_operation_key',
        'reversal_of_id',
        'posted_by_user_id',
        'posted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(ErpPayment::class, 'erp_payment_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(ErpObligation::class, 'erp_obligation_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
