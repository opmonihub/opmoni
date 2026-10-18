<?php

namespace App\Support;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\ErpCompanyAuthorization;
use App\Erp\ErpCompanyPermissions;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use App\Models\User;
use Illuminate\Http\Request;

class ErpIdentity
{
    public function __construct(private ErpCompanyAuthorization $authorization) {}

    /** @return list<array<string, mixed>> */
    public function companies(User $user, ?int $officeAccountId): array
    {
        $direct = ErpCompanyMembership::query()
            ->join('erp_companies', 'erp_companies.id', '=', 'erp_company_memberships.erp_company_id')
            ->where('erp_company_memberships.user_id', $user->id)
            ->orderBy('erp_company_memberships.id')
            ->get([
                'erp_companies.id',
                'erp_companies.name',
                'erp_companies.status',
                'erp_company_memberships.role',
                'erp_company_memberships.action_permissions',
            ]);

        $entries = $direct->map(fn ($row): array => [
            'id' => (int) $row->id,
            'name' => $row->name,
            'status' => $row->status,
            'access_kind' => 'direct',
            'role' => $row->role,
            'permissions' => $this->permissionsFromMembership($row->role, $row->action_permissions),
            'delegated_office_id' => null,
        ])->all();

        if ($officeAccountId === null) {
            return $entries;
        }

        $delegations = ErpOfficeDelegation::query()
            ->join('erp_companies', 'erp_companies.id', '=', 'erp_office_delegations.erp_company_id')
            ->where('erp_office_delegations.account_id', $officeAccountId)
            ->where('erp_office_delegations.status', 'active')
            ->where('erp_companies.status', 'active')
            ->orderBy('erp_office_delegations.id')
            ->get([
                'erp_companies.id',
                'erp_companies.name',
                'erp_companies.status',
                'erp_office_delegations.permission_allowlist',
                'erp_office_delegations.account_id',
            ]);

        $directIds = collect($entries)->pluck('id')->all();
        foreach ($delegations as $delegation) {
            if (in_array((int) $delegation->id, $directIds, true)) {
                continue;
            }
            $access = $this->authorization->resolveAccess($user, ErpCompany::find((int) $delegation->id), $officeAccountId);
            if ($access === null || $access['kind'] !== 'delegated') {
                continue;
            }
            $entries[] = [
                'id' => (int) $delegation->id,
                'name' => $delegation->name,
                'status' => $delegation->status,
                'access_kind' => 'delegated',
                'role' => 'delegated',
                'permissions' => ErpCompanyPermissions::delegatedEffective($access['delegation']->effectivePermissions(), $access['office_role']),
                'delegated_office_id' => (int) $delegation->account_id,
            ];
        }

        return $entries;
    }

    /** @return array<string, mixed>|null */
    public function currentCompany(Request $request, User $user, ?int $officeAccountId): ?array
    {
        $companyId = $request->session()->get('current_company_id');
        if (! is_int($companyId) && ! (is_string($companyId) && ctype_digit($companyId))) {
            return null;
        }
        $companyId = (int) $companyId;
        $company = ErpCompany::find($companyId);
        if (! $company) {
            return null;
        }

        $access = $this->authorization->resolveAccess($user, $company, $officeAccountId);
        if ($access === null) {
            return null;
        }

        $sessionKind = $request->session()->get('current_company_access_kind');
        $sessionOffice = $request->session()->get('current_company_office_id');
        if ($access['kind'] === 'delegated') {
            if ($sessionKind !== 'delegated' || (int) $sessionOffice !== $access['office_id'] || (int) $request->session()->get('current_company_delegation_id') !== (int) $access['delegation']->id) {
                return null;
            }
        } elseif ($sessionKind === 'delegated') {
            return null;
        }

        if ($access['kind'] === 'direct') {
            $membership = $access['membership'];

            return [
                'id' => (int) $company->id,
                'name' => $company->name,
                'status' => $company->status,
                'access_kind' => 'direct',
                'role' => $membership->role,
                'permissions' => $membership->effectivePermissions(),
                'delegated_office_id' => null,
            ];
        }

        return [
            'id' => (int) $company->id,
            'name' => $company->name,
            'status' => $company->status,
            'access_kind' => 'delegated',
            'role' => 'delegated',
            'permissions' => ErpCompanyPermissions::delegatedEffective($access['delegation']->effectivePermissions(), $access['office_role']),
            'delegated_office_id' => $access['office_id'],
        ];
    }

    public function defaultCompanyId(User $user): ?int
    {
        return ErpCompanyMembership::query()
            ->join('erp_companies', 'erp_companies.id', '=', 'erp_company_memberships.erp_company_id')
            ->where('erp_company_memberships.user_id', $user->id)
            ->where('erp_companies.status', 'active')
            ->orderBy('erp_company_memberships.id')
            ->value('erp_company_memberships.erp_company_id');
    }

    private function permissionsFromMembership(string $role, mixed $stored): array
    {
        if (is_array($stored) && $stored !== []) {
            return array_values(array_map(strval(...), $stored));
        }

        return ErpCompanyRole::from($role)->defaultPermissions();
    }
}
