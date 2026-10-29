<?php

namespace App\Erp\Commercial;

use App\Http\Requests\OfficeErp\ListCommercialRequest;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Support\LiteralSearch;
use App\Support\ValidatedListOrdering;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

final class OfficeErpCompanies
{
    public function __construct(private readonly OfficeErpAdmission $admission) {}

    public function index(OfficeErpExecutionContext $ctx, array $input): array
    {
        $filters = ListCommercialRequest::validateFilters('companies', $input);
        $capabilities = $this->admission->capabilities($ctx);

        return $ctx->run(function () use ($ctx, $filters, $capabilities): array {
            $query = $this->query($ctx)->join('erp_companies', 'erp_companies.id', '=', 'office_erp_company_registrations.erp_company_id')
                ->select('office_erp_company_registrations.*');
            if (($filters['search'] ?? '') !== '') {
                $query->where(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'erp_companies.name', $filters['search'])
                    ->orWhere(fn (Builder $documents) => LiteralSearch::whereContains($documents, 'erp_companies.cnpj', $filters['search'])));
            }
            if (isset($filters['state'])) {
                $applied = DB::table('office_erp_contracts')->selectRaw('1')
                    ->where('account_id', $ctx->accountId)
                    ->whereColumn('erp_company_id', 'office_erp_company_registrations.erp_company_id');
                $filters['state'] === 'applied' ? $query->whereExists($applied) : $query->whereNotExists($applied);
            }
            ValidatedListOrdering::apply($query, $filters['sort'] ?? 'name', $filters['direction'] ?? 'asc', [
                'name' => 'erp_companies.name', 'registered_at' => 'office_erp_company_registrations.registered_at',
            ], 'office_erp_company_registrations.erp_company_id');
            $page = $query->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

            return [
                'data' => $page->getCollection()->map(fn (OfficeErpCompanyRegistration $registration): array => $this->project($ctx, $registration, $capabilities))->all(),
                'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            ];
        });
    }

    public function show(OfficeErpExecutionContext $ctx, int $companyId): array
    {
        $capabilities = $this->admission->capabilities($ctx);

        return $ctx->run(fn (): array => $this->project($ctx, $this->query($ctx)->where('erp_company_id', $companyId)->firstOrFail(), $capabilities));
    }

    private function query(OfficeErpExecutionContext $ctx): Builder
    {
        return OfficeErpRecords::query($ctx, OfficeErpCompanyRegistration::class)->with([
            'company' => fn (BelongsTo $company) => $company->withCount([
                'officeErpContracts', 'officeErpRegistrations',
                'memberships as owner_count' => fn (Builder $members) => $members->where('role', 'owner'),
            ]),
            'company.subscription.officeErpContract' => fn (BelongsTo $contract) => $contract
                ->select(['id', 'account_id', 'erp_company_id', 'agreement_status', 'updated_at'])
                ->selectSub(OfficeErpLicenceAllocation::query()->selectRaw('COUNT(*)')
                    ->whereColumn('contract_id', 'office_erp_contracts.id')->where('state', 'released'), 'released_allocation_count'),
        ]);
    }

    private function project(OfficeErpExecutionContext $ctx, OfficeErpCompanyRegistration $registration, array $capabilities): array
    {
        $company = $registration->company;
        $subscription = $company->subscription;
        $linked = $subscription?->officeErpContract;
        $current = $linked !== null && (int) $linked->account_id === $ctx->accountId
            && (int) $linked->erp_company_id === (int) $company->id
            && in_array($linked->agreement_status, ['accepted', 'effective'], true);
        $unassigned = $linked === null && $subscription?->office_erp_contract_id === null
            && $subscription?->status === 'pending' && $company->owner_setup_status === 'unclaimed'
            && (int) $company->owner_count === 0 && (int) $company->office_erp_contracts_count === 0
            && (int) $company->office_erp_registrations_count === 1;
        $relationship = $current ? 'current' : ($unassigned ? 'unassigned' : 'historical');
        $canReassign = $linked !== null && (int) $linked->account_id === $ctx->accountId
            && (int) $linked->erp_company_id === (int) $company->id && $linked->agreement_status === 'canceled'
            && $subscription?->status === 'canceled' && (int) $linked->released_allocation_count === 1;
        $targetCapabilities = [
            'relationship' => $relationship,
            'can_edit_profile' => ($current || $unassigned) && $capabilities['can_register_company'],
            'can_assign' => ($unassigned || $canReassign) && $capabilities['can_provision'],
            'can_manage_members' => $current && $capabilities['can_maintain'],
        ];
        $targetCapabilities['capability_key'] = hash('sha256', json_encode([
            $capabilities['capability_key'], (int) $registration->id, (int) $company->id,
            $subscription?->id, $subscription?->office_erp_contract_id, $subscription?->status,
            $linked?->agreement_status, $linked?->updated_at?->toISOString(), $targetCapabilities,
        ], JSON_THROW_ON_ERROR));

        return [
            'id' => (int) $company->id, 'registration_id' => (int) $registration->id,
            'client_id' => (int) $registration->client_id, 'name' => $company->name,
            'cnpj' => $company->cnpj, 'registered_at' => $registration->registered_at->toISOString(),
            'owner_setup_status' => $company->owner_setup_status,
            'current_contract_id' => $current ? (int) $linked->id : null,
            'capabilities' => $targetCapabilities,
        ];
    }
}
