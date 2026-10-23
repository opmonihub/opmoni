<?php

namespace App\Http\Controllers\Api\V1\Erp\Accounting;

use App\Enums\Erp\ErpCompanyRole;
use App\Http\Controllers\Controller;
use App\Models\Erp\Accounting\ErpAccountingMapping;
use App\Models\Erp\ErpOfficeDelegation;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpAccountingMappingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $context = CurrentCompany::fromRequest($request);
        abort_unless($context->accessKind === 'direct' && $context->membership?->roleEnum() === ErpCompanyRole::Owner, 403);
        $delegation = ErpOfficeDelegation::query()
            ->with('account:id,name')
            ->where('erp_company_id', $context->company->id)
            ->where('status', 'active')
            ->first();
        $mappings = $delegation ? ErpAccountingMapping::query()->where('delegation_id', $delegation->id)->orderBy('id')->get() : collect();

        return response()->json(['data' => [
            'delegation_id' => $delegation?->id,
            'status' => $delegation?->status ?? 'not_delegated',
            'delegation' => $delegation ? [
                'id' => $delegation->id,
                'account' => ['id' => $delegation->account->id, 'name' => $delegation->account->name],
                'status' => $delegation->status,
                'effective_permissions' => $delegation->effectivePermissions(),
            ] : null,
            'mappings' => $mappings->map(fn (ErpAccountingMapping $row) => $row->only(['id', 'erp_establishment_id', 'revision', 'status'])),
        ]]);
    }
}
