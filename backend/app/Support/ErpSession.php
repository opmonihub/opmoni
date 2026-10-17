<?php

namespace App\Support;

use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpOfficeDelegation;
use Illuminate\Http\Request;

class ErpSession
{
    public static function clearCompanySelection(Request $request): void
    {
        $request->session()->forget([
            'current_company_id',
            'current_company_access_kind',
            'current_company_delegation_id',
            'current_company_office_id',
        ]);
    }

    /** @param array{kind: 'direct', membership: ErpCompanyMembership}|array{kind: 'delegated', delegation: ErpOfficeDelegation, office_id: int} $access */
    public static function applyCompanySelection(Request $request, int $companyId, array $access): void
    {
        $payload = ['current_company_id' => $companyId];
        if ($access['kind'] === 'direct') {
            $payload += [
                'current_company_access_kind' => 'direct',
                'current_company_delegation_id' => null,
                'current_company_office_id' => null,
            ];
        } else {
            $payload += [
                'current_company_access_kind' => 'delegated',
                'current_company_delegation_id' => $access['delegation']->id,
                'current_company_office_id' => $access['office_id'],
            ];
        }
        $request->session()->put($payload);
    }

    public static function clearDelegatedCompanyIfNeeded(Request $request): void
    {
        if ($request->session()->get('current_company_access_kind') === 'delegated') {
            self::clearCompanySelection($request);
        }
    }
}
