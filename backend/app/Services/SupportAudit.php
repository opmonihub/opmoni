<?php

namespace App\Services;

use App\Models\SupportAccessLog;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Illuminate\Http\Request;

class SupportAudit
{
    /**
     * Support mode means a super_admin operating in an account
     * where they hold no membership of their own.
     */
    public static function isSupportMode(User $user): bool
    {
        $tenantId = resolve(CurrentTenant::class)->accountId;

        if (! $user->isSuperAdmin() || $tenantId === null) {
            return false;
        }

        return $user->accountRole($tenantId) === null;
    }

    /**
     * Record a tenant write performed in support mode. No-op for
     * regular members and for super_admins inside their own accounts.
     *
     * @param  array<string, mixed>  $details
     */
    public static function logWrite(Request $request, string $resource, string $verb, ?int $resourceId = null, array $details = []): void
    {
        $user = $request->user();
        $tenantId = resolve(CurrentTenant::class)->accountId;

        if (! $user instanceof User || $tenantId === null) {
            return;
        }

        if (! self::isSupportMode($user)) {
            return;
        }

        (new SupportAccessLog)->forceFill([
            'super_admin_user_id' => $user->getKey(),
            'account_id' => $tenantId,
            'action' => $verb,
            'metadata' => array_merge(['resource' => $resource, 'resource_id' => $resourceId], $details),
            'ip' => $request->ip(),
        ])->save();
    }
}
