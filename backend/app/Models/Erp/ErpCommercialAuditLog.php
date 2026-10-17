<?php

namespace App\Models\Erp;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErpCommercialAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['super_admin_user_id', 'erp_company_id', 'action', 'metadata', 'ip'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'super_admin_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(ErpCompany::class, 'erp_company_id');
    }
}
