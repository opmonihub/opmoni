<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use App\Models\User;
use Database\Factories\Erp\Commercial\OfficeErpAuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OfficeErpAuditEvent extends Model
{
    /** @use HasFactory<OfficeErpAuditEventFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'account_id', 'operation_id', 'actor_kind', 'actor_identity', 'actor_user_id', 'action',
        'subject_type', 'subject_id', 'metadata',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function newFactory(): OfficeErpAuditEventFactory
    {
        return OfficeErpAuditEventFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Office ERP audit events are immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Office ERP audit events are immutable.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(OfficeErpCommercialOperation::class, 'operation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
