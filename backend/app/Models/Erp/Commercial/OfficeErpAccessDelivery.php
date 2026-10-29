<?php

namespace App\Models\Erp\Commercial;

use Database\Factories\Erp\Commercial\OfficeErpAccessDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OfficeErpAccessDelivery extends Model
{
    /** @use HasFactory<OfficeErpAccessDeliveryFactory> */
    use HasFactory;

    protected $hidden = ['payload', 'password_recovery_completion_nonce_hash'];

    protected $fillable = [
        'account_id', 'erp_company_id', 'registration_id', 'operation_id', 'purpose', 'member_invitation_id',
        'membership_id', 'recipient_user_id', 'recipient_email', 'access_auth_revision', 'state', 'payload',
        'expires_at', 'claimed_at', 'attempted_at', 'completed_at', 'authority_invalidated_at',
        'password_recovered_at', 'password_recovered_auth_revision',
        'password_recovery_completion_nonce_hash',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array', 'access_auth_revision' => 'integer',
            'expires_at' => 'immutable_datetime', 'claimed_at' => 'immutable_datetime',
            'attempted_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'authority_invalidated_at' => 'immutable_datetime',
            'password_recovered_at' => 'immutable_datetime',
            'password_recovered_auth_revision' => 'integer',
        ];
    }
}
