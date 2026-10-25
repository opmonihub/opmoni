<?php

namespace App\Models\Erp\Commercial;

use App\Models\Account;
use App\Models\User;
use Database\Factories\Erp\Commercial\OfficeErpOwnerInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OfficeErpOwnerInvitation extends Model
{
    /** @use HasFactory<OfficeErpOwnerInvitationFactory> */
    use HasFactory;

    protected $hidden = ['token_hash'];

    protected $fillable = [
        'account_id', 'contract_id', 'owner_name', 'owner_email', 'seller_snapshot', 'token_hash', 'status',
        'expires_at', 'consumed_at', 'revoked_at', 'revocation_reason', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'seller_snapshot' => 'array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): OfficeErpOwnerInvitationFactory
    {
        return OfficeErpOwnerInvitationFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(static function (self $invitation): void {
            if ($invitation->isDirty('token_hash')) {
                throw new LogicException('Office ERP owner invitation token hashes are immutable.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Office ERP owner invitations are retained as commercial history.');
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(OfficeErpContract::class, 'contract_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
