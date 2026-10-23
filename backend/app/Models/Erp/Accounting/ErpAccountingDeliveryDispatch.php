<?php

namespace App\Models\Erp\Accounting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpAccountingDeliveryDispatch extends Model
{
    protected $fillable = [
        'erp_accounting_delivery_id',
        'generation',
        'status',
        'dispatch_attempts',
        'attempt_sequence',
        'available_at',
        'lease_expires_at',
        'lease_token',
        'completed_at',
        'last_error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'dispatch_attempts' => 'integer',
            'attempt_sequence' => 'integer',
            'available_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(ErpAccountingDelivery::class, 'erp_accounting_delivery_id');
    }
}
