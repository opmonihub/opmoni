<?php

namespace App\Erp\Commercial;

use App\Http\Requests\OfficeErp\ListCommercialRequest;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Support\LiteralSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpSubscriptionCompanies
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpCompanies $companies,
        private readonly OfficeErpContracts $contracts,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function index(OfficeErpExecutionContext $ctx, array $input): array
    {
        $filters = ListCommercialRequest::validateFilters('subscription-companies', $input);
        $this->admission->capabilities($ctx);
        $query = OfficeErpRecords::query($ctx, OfficeErpCompanyRegistration::class)
            ->join('erp_companies', 'erp_companies.id', '=', 'office_erp_company_registrations.erp_company_id')
            ->whereExists(function ($contracts): void {
                $contracts->selectRaw('1')->from('office_erp_contracts')
                    ->whereColumn('office_erp_contracts.erp_company_id', 'office_erp_company_registrations.erp_company_id')
                    ->whereColumn('office_erp_contracts.account_id', 'office_erp_company_registrations.account_id');
            })
            ->select('office_erp_company_registrations.*')
            ->with([
                'client',
                'company' => fn (BelongsTo $company) => $company->withCount([
                    'officeErpContracts', 'officeErpRegistrations',
                    'memberships as owner_count' => fn (Builder $members) => $members->where('role', 'owner'),
                ]),
                'company.subscription.plan', 'company.subscription.officeErpContract.currentRevision', 'company.subscription.officeErpContract.pendingRevision',
            ]);
        if (($filters['search'] ?? '') !== '') {
            $search = $filters['search'];
            $query->where(fn (Builder $matches) => LiteralSearch::whereContains($matches, 'erp_companies.name', $search)
                ->orWhere(fn (Builder $cnpj) => LiteralSearch::whereContains($cnpj, 'erp_companies.cnpj', $search))
                ->orWhereHas('client', fn (Builder $client) => LiteralSearch::whereContains($client, 'name', $search)));
        }
        if (isset($filters['status'])) {
            $query->whereHas('company.subscription', fn (Builder $subscription) => $subscription->where('status', $filters['status'])
                ->whereHas('officeErpContract', fn (Builder $contract) => $contract->where('account_id', $ctx->accountId)
                    ->whereColumn('erp_company_id', 'erp_subscriptions.erp_company_id')));
        }
        $sortColumn = match ($filters['sort'] ?? 'name') {
            'name' => 'erp_companies.name',
            'registered_at' => 'office_erp_company_registrations.registered_at',
            'status' => DB::table('erp_subscriptions')->join('office_erp_contracts', 'office_erp_contracts.id', '=', 'erp_subscriptions.office_erp_contract_id')
                ->select('erp_subscriptions.status')
                ->whereColumn('erp_subscriptions.erp_company_id', 'erp_companies.id')
                ->whereColumn('office_erp_contracts.account_id', 'office_erp_company_registrations.account_id')
                ->limit(1),
            default => 'erp_companies.name',
        };
        // Subscription status can be NULL for historical relationships. Keep
        // those rows last in either direction for deterministic API ordering.
        if (($filters['sort'] ?? 'name') === 'status') {
            $query->orderByRaw('CASE WHEN ('.$sortColumn->toSql().') IS NULL THEN 1 ELSE 0 END ASC', $sortColumn->getBindings());
        }
        $query->orderBy($sortColumn, $filters['direction'] ?? 'asc');
        $query->orderBy('erp_companies.id');
        $page = $query->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        $registrations = $page->getCollection();
        $contractIds = $registrations->map(fn (OfficeErpCompanyRegistration $registration): ?int => $registration->company->subscription?->officeErpContract !== null
                && (int) $registration->company->subscription->officeErpContract->account_id === $ctx->accountId
                && (int) $registration->company->subscription->officeErpContract->erp_company_id === (int) $registration->erp_company_id
                ? (int) $registration->company->subscription->officeErpContract->id
                : null)->filter()->unique()->values()->all();
        $contractData = $this->contracts->showMany($ctx, $contractIds);

        return [
            'data' => $registrations->map(fn (OfficeErpCompanyRegistration $registration): array => $this->row($ctx, $registration, $contractData))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /** @return array<string, mixed> */
    public function show(OfficeErpExecutionContext $ctx, int $companyId): array
    {
        $companyProjection = $this->companies->show($ctx, $companyId);
        $company = ErpCompany::query()->whereKey($companyId)->firstOrFail();
        $subscription = $company->subscription()->with(['plan', 'trial', 'officeErpContract.currentRevision', 'officeErpContract.pendingRevision'])->first();
        $linked = $subscription?->officeErpContract;
        $inOffice = $linked !== null && (int) $linked->account_id === $ctx->accountId
            && (int) $linked->erp_company_id === $companyId;
        $contract = $inOffice ? $this->contracts->show($ctx, (int) $linked->id)['data'] : null;
        $allocation = $inOffice
            ? OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)->where('contract_id', $linked->id)->first()
            : null;
        $officeCapabilities = $this->admission->capabilities($ctx);
        $lifecycleAvailable = $contract !== null && $officeCapabilities['can_maintain'];
        $isEffective = ($contract['agreement_status'] ?? null) === 'effective';
        $isAccepted = ($contract['agreement_status'] ?? null) === 'accepted';
        $subscriptionData = $inOffice && $subscription !== null ? [
            'id' => (int) $subscription->id,
            'status' => (string) $subscription->status,
            'erp_plan_id' => (int) $subscription->erp_plan_id,
            'erp_plan' => $subscription->plan === null ? null : ['id' => (int) $subscription->plan->id, 'name' => (string) $subscription->plan->name],
        ] : null;

        return ['data' => [
            'company' => ['id' => (int) $company->id, 'registration_id' => (int) $companyProjection['registration_id'], 'client_id' => (int) $companyProjection['client_id'], 'name' => (string) $company->name],
            'relationship' => $companyProjection['capabilities']['relationship'],
            'subscription' => $subscriptionData,
            'current_contract' => $contract,
            'trial' => $contract['trial'] ?? null,
            'allocation' => $allocation === null ? null : [
                'id' => (int) $allocation->id,
                'state' => (string) $allocation->state,
                'expires_at' => $allocation->expires_at?->toISOString(),
                'released_at' => $allocation->released_at?->toISOString(),
            ],
            'balance' => $contract['balance'] ?? ['outstanding' => '0.00', 'invoice_count' => 0],
            'action_capabilities' => [
                ...$companyProjection['capabilities'],
                'can_activate' => $lifecycleAvailable && $isAccepted && $allocation?->state === 'occupied' && $subscription?->status === 'pending',
                'can_manage_lifecycle' => $lifecycleAvailable && ($isAccepted || $isEffective),
                'can_amend_terms' => $lifecycleAvailable && $isEffective,
                'can_cancel' => $lifecycleAvailable && ($isAccepted || $isEffective),
                'can_suspend' => $lifecycleAvailable && $isEffective && ! ($contract['manual_hold'] ?? false),
                'can_resume' => $lifecycleAvailable && $isEffective && ($contract['manual_hold'] ?? false),
            ],
        ]];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function contracts(OfficeErpExecutionContext $ctx, int $companyId, array $input): array
    {
        $this->companies->show($ctx, $companyId);
        $filters = $this->contractFilters($input);
        $query = OfficeErpRecords::query($ctx, OfficeErpContract::class)->where('erp_company_id', $companyId);
        if (isset($filters['status'])) {
            $query->where('agreement_status', $filters['status']);
        }
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'data' => array_map(fn (OfficeErpContract $contract): array => $this->contracts->show($ctx, (int) $contract->id)['data'], $page->items()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function invoices(OfficeErpExecutionContext $ctx, int $companyId, array $input): array
    {
        $this->companies->show($ctx, $companyId);
        $filters = $this->invoiceFilters($input);
        $contractIds = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->where('erp_company_id', $companyId)->orderBy('id')->pluck('id');
        $query = OfficeErpRecords::query($ctx, OfficeErpInvoice::class)
            ->whereIn('contract_id', $contractIds)
            ->with(['contract', 'adjustments', 'allocations.receipt.reversal']);
        if (isset($filters['status'])) {
            $query->where('document_status', $filters['status']);
        }
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 25, ['*'], 'page', $filters['page'] ?? 1);

        return [
            'data' => $page->getCollection()->map(static function (OfficeErpInvoice $invoice): array {
                $balance = OfficeErpBalances::forInvoice($invoice);

                return [
                    'id' => (int) $invoice->id,
                    'contract_id' => (int) $invoice->contract_id,
                    'kind' => (string) $invoice->kind,
                    'document_status' => (string) $invoice->document_status,
                    'number' => (string) $invoice->number,
                    'period_start' => $invoice->period_start?->toDateString(),
                    'period_end' => $invoice->period_end?->toDateString(),
                    'due_on' => $invoice->due_on?->toDateString(),
                    'currency' => (string) $invoice->currency,
                    'original_total' => OfficeErpMoney::canonical((string) $invoice->getRawOriginal('original_total'), 'original_total'),
                    'balance' => $balance,
                ];
            })->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ];
    }

    private function row(OfficeErpExecutionContext $ctx, OfficeErpCompanyRegistration $registration, array $contractData): array
    {
        $company = $registration->company;
        $subscription = $company->subscription;
        $linked = $subscription?->officeErpContract;
        $inOffice = $linked !== null && (int) $linked->account_id === $ctx->accountId
            && (int) $linked->erp_company_id === (int) $company->id;
        $companyContract = $inOffice ? ($contractData[(int) $linked->id] ?? null) : null;
        $hasCurrentContract = $companyContract !== null && in_array($companyContract['agreement_status'] ?? null, ['accepted', 'effective'], true);
        $unassigned = $linked === null && $subscription?->office_erp_contract_id === null
            && $subscription?->status === 'pending' && $company->owner_setup_status === 'unclaimed'
            && (int) $company->owner_count === 0
            && (int) $company->office_erp_contracts_count === 0
            && (int) $company->office_erp_registrations_count === 1;
        $relationship = $hasCurrentContract ? 'current' : ($unassigned ? 'unassigned' : 'historical');

        return [
            'id' => (int) $company->id,
            'registration_id' => (int) $registration->id,
            'client_id' => (int) $registration->client_id,
            'name' => (string) $company->name,
            'cnpj' => (string) $company->cnpj,
            'registered_at' => $registration->registered_at->toISOString(),
            'owner_setup_status' => (string) $company->owner_setup_status,
            'relationship' => $relationship,
            'current_contract_id' => $hasCurrentContract ? (int) $linked->id : null,
            'linked_contract_id' => $inOffice && $linked !== null ? (int) $linked->id : null,
            'subscription' => $companyContract === null || $subscription === null ? null : [
                'id' => (int) $subscription->id,
                'status' => (string) $subscription->status,
                'erp_plan_id' => (int) $subscription->erp_plan_id,
                'erp_plan' => $subscription->plan === null ? null : ['id' => (int) $subscription->plan->id, 'name' => (string) $subscription->plan->name],
            ],
            'current_contract' => $companyContract,
        ];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function contractFilters(array $input): array
    {
        if (array_diff(array_keys($input), ['status', 'page', 'per_page']) !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_diff(array_keys($input), ['status', 'page', 'per_page']), 'Este filtro não se aplica a esta lista.'));
        }

        return Validator::make($input, [
            'status' => ['sometimes', Rule::in(['draft', 'awaiting_owner', 'accepted', 'effective', 'canceled'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
        ])->validate();
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function invoiceFilters(array $input): array
    {
        if (array_diff(array_keys($input), ['status', 'page', 'per_page']) !== []) {
            throw ValidationException::withMessages(array_fill_keys(array_diff(array_keys($input), ['status', 'page', 'per_page']), 'Este filtro não se aplica a esta lista.'));
        }

        return Validator::make($input, [
            'status' => ['sometimes', Rule::in(['draft', 'issued', 'canceled'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', Rule::in([25, 50, 100])],
        ])->validate();
    }
}
