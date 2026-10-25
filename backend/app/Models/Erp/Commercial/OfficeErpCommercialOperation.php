<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use Database\Factories\Erp\Commercial\OfficeErpCommercialOperationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeErpCommercialOperation extends Model
{
    /** @use HasFactory<OfficeErpCommercialOperationFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id', 'actor_kind', 'actor_identity', 'idempotency_key', 'action', 'payload_hash', 'payload', 'outcome',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array', 'outcome' => 'array'];
    }

    protected static function newFactory(): OfficeErpCommercialOperationFactory
    {
        return OfficeErpCommercialOperationFactory::new();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(OfficeErpAuditEvent::class, 'operation_id');
    }
}
