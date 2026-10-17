<?php

namespace App\Models\Erp\Fiscal;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpFiscalEvent extends Model
{
    protected $fillable = [
        'erp_fiscal_document_id',
        'event_type',
        'status',
        'idempotency_key',
        'protocol',
        'justification',
        'payload_snapshot',
        'provider_response',
        'requested_by_user_id',
        'submitted_at',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload_snapshot' => 'array',
            'provider_response' => 'array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ErpFiscalDocument::class, 'erp_fiscal_document_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
