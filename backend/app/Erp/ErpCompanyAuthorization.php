<?php

namespace App\Erp;

use App\Enums\Erp\ErpCompanyRole;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ErpCompanyAuthorization
{
    /**
     * @return array{kind: 'direct', membership: ErpCompanyMembership}|array{kind: 'delegated', delegation: ErpOfficeDelegation, office_id: int}|null
     */
    public function resolveAccess(User $user, ErpCompany $company, ?int $officeAccountId): ?array
    {
        if ($company->status !== 'active') {
            return null;
        }

        $membership = ErpCompanyMembership::query()
            ->where('erp_company_id', $company->id)
            ->where('user_id', $user->id)
            ->first();
        if ($membership) {
            return ['kind' => 'direct', 'membership' => $membership];
        }

        if ($officeAccountId === null) {
            return null;
        }

        $delegation = ErpOfficeDelegation::query()
            ->where('erp_company_id', $company->id)
            ->where('account_id', $officeAccountId)
            ->where('status', 'active')
            ->first();
        if (! $delegation) {
            return null;
        }

        $officeMembership = AccountMembership::query()
            ->join('accounts', 'accounts.id', '=', 'account_memberships.account_id')
            ->where('account_memberships.account_id', $officeAccountId)
            ->where('account_memberships.user_id', $user->id)
            ->where('accounts.status', 'active')
            ->first(['account_memberships.*']);
        if (! $officeMembership) {
            return null;
        }
        if ($officeMembership->role === 'user') {
            return null;
        }

        return ['kind' => 'delegated', 'delegation' => $delegation, 'office_id' => $officeAccountId];
    }

    public function acceptDelegation(ErpCompany $company, User $owner, int $officeAccountId, array $permissionAllowlist): ErpOfficeDelegation
    {
        $membership = ErpCompanyMembership::query()
            ->where('erp_company_id', $company->id)
            ->where('user_id', $owner->id)
            ->first();
        abort_unless($membership && $membership->roleEnum() === ErpCompanyRole::Owner, 403, 'Permissão insuficiente');

        return DB::transaction(function () use ($company, $owner, $officeAccountId, $permissionAllowlist): ErpOfficeDelegation {
            ErpCompany::query()->whereKey($company->id)->lockForUpdate()->first();
            ErpOfficeDelegation::query()
                ->where('erp_company_id', $company->id)
                ->where('status', 'active')
                ->update(['status' => 'revoked', 'revoked_at' => now()]);

            return ErpOfficeDelegation::query()->create([
                'erp_company_id' => $company->id,
                'account_id' => $officeAccountId,
                'authorized_by_user_id' => $owner->id,
                'permission_allowlist' => $permissionAllowlist === [] ? ['erp.read'] : array_values($permissionAllowlist),
                'status' => 'active',
            ]);
        });
    }

    public function revokeDelegation(ErpOfficeDelegation $delegation, User $owner): void
    {
        $membership = ErpCompanyMembership::query()
            ->where('erp_company_id', $delegation->erp_company_id)
            ->where('user_id', $owner->id)
            ->first();
        abort_unless($membership && $membership->roleEnum() === ErpCompanyRole::Owner, 403, 'Permissão insuficiente');

        DB::transaction(function () use ($delegation): void {
            ErpCompany::query()->whereKey($delegation->erp_company_id)->lockForUpdate()->first();
            $delegation->refresh();
            if ($delegation->status !== 'active') {
                return;
            }
            $delegation->update(['status' => 'revoked', 'revoked_at' => now()]);
        });
    }
}
