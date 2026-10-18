<?php

namespace App\Erp;

use App\Enums\Erp\ErpCompanyRole;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\User;
use App\Support\AccessTransaction;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ErpCompanyAuthorization
{
    /**
     * @return array{kind: 'direct', membership: ErpCompanyMembership}|array{kind: 'delegated', delegation: ErpOfficeDelegation, office_id: int, office_role: string}|null
     */
    public function resolveAccess(User $user, ErpCompany $company, ?int $officeAccountId, bool $lockForWrite = false): ?array
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

        $office = $lockForWrite
            ? app(CompanyAccessFence::class)->acquire($officeAccountId)
            : Account::query()->find($officeAccountId);
        if (! $office || $office->status !== 'active') {
            return null;
        }
        $membershipQuery = AccountMembership::query()
            ->where('account_id', $officeAccountId)
            ->where('user_id', $user->id);
        if ($lockForWrite) {
            $membershipQuery->lockForUpdate();
        }
        $officeMembership = $membershipQuery->first();
        if (! $officeMembership || ! in_array($officeMembership->role, ['admin', 'operador', 'user'], true)) {
            return null;
        }
        $delegation->refresh();
        if (! $delegation->isActive() || (int) $delegation->account_id !== $officeAccountId) {
            return null;
        }

        return ['kind' => 'delegated', 'delegation' => $delegation, 'office_id' => $officeAccountId, 'office_role' => $officeMembership->role];
    }

    /** @param Closure(): void|null $assertOwnership Contextual fence supplied by HTTP callers. */
    public function acceptDelegation(ErpCompany $company, User $owner, int $officeAccountId, array $permissionAllowlist, ?Closure $assertOwnership = null): ErpOfficeDelegation
    {
        return DB::transaction(function () use ($company, $owner, $officeAccountId, $permissionAllowlist, $assertOwnership): ErpOfficeDelegation {
            Validator::make(['permissions' => $permissionAllowlist], [
                'permissions' => ['array'],
                'permissions.*' => ['string', Rule::in(ErpCompanyPermissions::delegatedNames())],
            ])->validate();
            AccessTransaction::boundLockWaits();
            $company = ErpCompany::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($company, $owner);
            if ($assertOwnership !== null) {
                $assertOwnership();
            }
            ErpOfficeDelegation::query()
                ->where('erp_company_id', $company->id)
                ->where('status', 'active')
                ->update(['status' => 'revoked', 'revoked_at' => now()]);

            $delegation = ErpOfficeDelegation::query()->create([
                'erp_company_id' => $company->id,
                'account_id' => $officeAccountId,
                'authorized_by_user_id' => $owner->id,
                'permission_allowlist' => $permissionAllowlist === [] ? ['erp.read'] : array_values($permissionAllowlist),
                'status' => 'active',
            ]);
            if ($assertOwnership !== null) {
                $assertOwnership();
            }

            return $delegation;
        });
    }

    /** @param Closure(): void|null $assertOwnership Contextual fence supplied by HTTP callers. */
    public function revokeDelegation(ErpOfficeDelegation $delegation, User $owner, ?Closure $assertOwnership = null): void
    {
        DB::transaction(function () use ($delegation, $owner, $assertOwnership): void {
            AccessTransaction::boundLockWaits();
            $company = ErpCompany::query()->whereKey($delegation->erp_company_id)->lockForUpdate()->firstOrFail();
            $this->assertOwner($company, $owner);
            if ($assertOwnership !== null) {
                $assertOwnership();
            }
            $delegation->refresh();
            if ($delegation->status !== 'active') {
                return;
            }
            $delegation->update(['status' => 'revoked', 'revoked_at' => now()]);
            if ($assertOwnership !== null) {
                $assertOwnership();
            }
        });
    }

    private function assertOwner(ErpCompany $company, User $owner): void
    {
        $membership = ErpCompanyMembership::query()
            ->where('erp_company_id', $company->id)->where('user_id', $owner->id)
            ->lockForUpdate()->first();
        abort_unless($membership && $membership->roleEnum() === ErpCompanyRole::Owner, 403, 'Permissão insuficiente');
    }
}
