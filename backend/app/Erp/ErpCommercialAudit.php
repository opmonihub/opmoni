<?php

namespace App\Erp;

use App\Models\Erp\ErpCommercialAuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class ErpCommercialAudit
{
    /** @param  array<string, mixed>  $metadata */
    public static function log(Request $request, string $action, ?int $companyId = null, array $metadata = []): void
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            return;
        }

        (new ErpCommercialAuditLog)->forceFill([
            'super_admin_user_id' => $user->getKey(),
            'erp_company_id' => $companyId,
            'action' => $action,
            'metadata' => $metadata,
            'ip' => $request->ip(),
        ])->save();
    }
}
