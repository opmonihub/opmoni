<?php

namespace App\Models\Erp;

use App\Models\Account;
use App\Models\User;
use Database\Factories\Erp\ErpOfficeDelegationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpOfficeDelegation extends Model
{
    /** @use HasFactory<ErpOfficeDelegationFactory> */
    use HasFactory;

    protected $fillable = [
        'erp_company_id',
        'account_id',
        'authorized_by_user_id',
        'permission_allowlist',
        'status',
        'revoked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'permission_allowlist' => 'array',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ErpOfficeDelegationFactory
    {
        return ErpOfficeDelegationFactory::new();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** @return list<string> */
    public function effectivePermissions(): array
    {
        $list = $this->permission_allowlist;
        if (! is_array($list) || $list === []) {
            return ['erp.read'];
        }

        return array_values(array_map(strval(...), $list));
    }
}
