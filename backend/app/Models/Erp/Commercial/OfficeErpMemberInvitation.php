<?php

namespace App\Models\Erp\Commercial;

use Database\Factories\Erp\Commercial\OfficeErpMemberInvitationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class OfficeErpMemberInvitation extends Model
{
    /** @use HasFactory<OfficeErpMemberInvitationFactory> */
    use HasFactory;

    protected $table = 'erp_company_member_invitations';

    protected $hidden = ['token_hash'];

    protected $fillable = [
        'account_id', 'erp_company_id', 'registration_id', 'purpose', 'name', 'email', 'role',
        'permissions_mode', 'permissions', 'establishment_scope', 'establishment_ids', 'token_hash',
        'status', 'expires_at', 'accepted_at', 'revoked_at', 'created_by_user_id', 'accepted_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'permissions' => 'array', 'establishment_ids' => 'array', 'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $email): string => mb_strtolower(trim($email)));
    }

    protected static function booted(): void
    {
        static::updating(static function (self $invitation): void {
            if ($invitation->isDirty(['account_id', 'erp_company_id', 'registration_id', 'purpose', 'email', 'token_hash'])) {
                throw new LogicException('Member invitation identity, authority and token are immutable.');
            }
        });
        static::deleting(static function (): never {
            throw new LogicException('Member invitations are retained as access history.');
        });
    }
}
