<?php

namespace App\Erp\Commercial;

use App\Erp\ErpCommercialCatalog;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpSubscription;
use App\Support\AccessTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpAssignments
{
    public function __construct(
        private readonly OfficeErpContracts $contracts,
        private readonly OfficeErpLicenceAllocator $allocator,
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
        private readonly ErpCommercialCatalog $catalog,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function assign(OfficeErpExecutionContext $ctx, int $companyId, array $input, string $key): array
    {
        $data = $this->validate($input);
        $payload = [
            'command' => 'subscription.assign',
            'company_id' => $companyId,
            'erp_plan_id' => (int) $data['erp_plan_id'],
            'terms' => [
                'price' => OfficeErpMoney::canonical($data['price'], 'price'),
                'currency' => 'BRL',
                'interval_months' => (int) $data['interval_months'],
                'due_in_days' => (int) $data['due_in_days'],
                'start_on' => $data['start_on'],
                'billing_timezone' => $data['billing_timezone'],
            ],
        ];

        return DB::transaction(function () use ($ctx, $key, $payload): array {
            AccessTransaction::boundLockWaits();
            $outcome = $this->operations->execute(
                $ctx,
                $key,
                'subscription.assign',
                $payload,
                fn (string $action, array $stored): mixed => $this->authorizeOperation($ctx, $action, $stored, $key),
                fn (): array => ['data' => $this->assignLocked($ctx, $payload)],
            );

            return $outcome['data'];
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload, string $key): void
    {
        abort_unless($action === 'subscription.assign' && ($payload['command'] ?? null) === $action, 409);
        abort_unless(is_int($payload['company_id'] ?? null) && $payload['company_id'] > 0, 409);
        abort_unless(is_int($payload['erp_plan_id'] ?? null) && $payload['erp_plan_id'] > 0, 409);
        $terms = $payload['terms'] ?? null;
        abort_unless(is_array($terms)
            && ($terms['currency'] ?? null) === 'BRL'
            && is_string($terms['price'] ?? null)
            && is_int($terms['interval_months'] ?? null)
            && is_int($terms['due_in_days'] ?? null)
            && is_string($terms['start_on'] ?? null)
            && is_string($terms['billing_timezone'] ?? null), 409);

        $existing = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)
            ->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key)
            ->where('action', $action)
            ->first(['outcome']);
        if ($existing !== null) {
            $outcome = $existing->outcome['data'] ?? null;
            abort_unless(is_array($outcome)
                && is_int($outcome['company_id'] ?? null)
                && is_int($outcome['contract_id'] ?? null)
                && is_int($outcome['subscription_id'] ?? null)
                && is_int($outcome['allocation_id'] ?? null)
                && ($outcome['invoice_id'] ?? null) === null
                && $outcome['company_id'] === $payload['company_id'], 409);
            $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($outcome['contract_id'])
                ->where('erp_company_id', $payload['company_id'])
                ->where('provisioning_source', 'office_admin')
                ->firstOrFail();
            $this->admission->assertCommand($ctx, 'contract.read', $contract);
            $allocation = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->whereKey($outcome['allocation_id'])
                ->where('contract_id', $contract->id)
                ->where('erp_company_id', $payload['company_id'])
                ->firstOrFail();
            abort_unless(in_array($allocation->state, ['occupied', 'released'], true), 409);

            return;
        }

        $this->admission->lockCommercialAggregate($ctx);
        $this->catalog->advisory();
        $plan = ErpPlan::query()->with('modules')->whereKey($payload['erp_plan_id'])->lockForUpdate()->firstOrFail();
        $modules = $plan->modules->pluck('module_key')->sort()->values()->all();
        abort_unless($modules !== [], 422, 'erp_plan_without_modules');
        $this->admission->assertCommand($ctx, $action, modules: $modules);
        $this->assertAssignableCompany($ctx, $payload['company_id']);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function assignLocked(OfficeErpExecutionContext $ctx, array $payload): array
    {
        $this->admission->lockCommercialAggregate($ctx);
        $this->catalog->advisory();
        $plan = ErpPlan::query()->with('modules')->whereKey($payload['erp_plan_id'])->lockForUpdate()->firstOrFail();
        $modules = $plan->modules->pluck('module_key')->sort()->values()->all();
        abort_unless($modules !== [], 422, 'erp_plan_without_modules');
        $this->admission->assertCommand($ctx, 'subscription.assign', modules: $modules);
        [$registration, $client, $company] = $this->assertAssignableCompany($ctx, $payload['company_id'], true);
        $terms = $payload['terms'];
        $assignment = $this->assignProvisionedLocked($ctx, $client, $company, (int) $plan->id, [
            'price' => $terms['price'],
            'currency' => 'BRL',
            'interval_months' => $terms['interval_months'],
            'due_in_days' => $terms['due_in_days'],
            'module_keys' => $modules,
            'billing_timezone' => $terms['billing_timezone'],
        ], $terms['start_on']);

        return [
            'company_id' => (int) $company->id,
            'registration_id' => (int) $registration->id,
            'contract_id' => (int) $assignment['contract']->id,
            'subscription_id' => (int) $assignment['subscription']->id,
            'allocation_id' => (int) $assignment['allocation']->id,
            'agreement_status' => 'accepted',
            'licence_status' => 'pending',
            'invoice_id' => null,
        ];
    }

    /** @return array{0: OfficeErpCompanyRegistration, 1: Client, 2: ErpCompany} */
    private function assertAssignableCompany(OfficeErpExecutionContext $ctx, int $companyId, bool $lock = false): array
    {
        $registrationQuery = OfficeErpRecords::query($ctx, OfficeErpCompanyRegistration::class)
            ->where('erp_company_id', $companyId);
        $companyQuery = ErpCompany::query()->whereKey($companyId);
        $subscriptionQuery = ErpSubscription::query()->where('erp_company_id', $companyId);
        if ($lock) {
            $registrationQuery->lockForUpdate();
            $companyQuery->lockForUpdate();
            $subscriptionQuery->lockForUpdate();
        }
        $registration = $registrationQuery->firstOrFail();
        $client = Client::withoutGlobalScopes()->where('account_id', $ctx->accountId)
            ->whereKey($registration->client_id)->whereNull('deleted_at')->firstOrFail();
        $company = $companyQuery->firstOrFail();
        abort_unless($client->person_type->value === 'company' && $client->status->value === 'active', 404);
        abort_unless($company->status === 'active', 403, 'company_inactive');
        $subscription = $subscriptionQuery->firstOrFail();
        $linked = $subscription->office_erp_contract_id === null ? null : OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($subscription->office_erp_contract_id)
            ->where('erp_company_id', $companyId)
            ->first();

        if ($linked === null) {
            abort_unless($subscription->office_erp_contract_id === null && $subscription->status === 'pending', 409, 'company_already_linked');
            abort_unless($company->owner_setup_status === 'unclaimed'
                && ! $company->memberships()->where('role', 'owner')->exists()
                && ! $company->officeErpContracts()->exists()
                && $company->officeErpRegistrations()->count() === 1, 409, 'company_not_unassigned');
        } else {
            abort_unless($linked->agreement_status === 'canceled' && $subscription->status === 'canceled', 409, 'company_already_linked');
            $released = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->where('contract_id', $linked->id)->where('erp_company_id', $companyId)->where('state', 'released')->exists();
            abort_unless($released, 409, 'company_already_linked');
        }

        return [$registration, $client, $company];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function validate(array $input): array
    {
        if (array_diff(array_keys($input), ['erp_plan_id', 'price', 'currency', 'interval_months', 'due_in_days', 'start_on', 'billing_timezone']) !== []) {
            throw ValidationException::withMessages(['payload' => ['O conteúdo da assinatura contém campos não permitidos.']]);
        }
        $data = Validator::make($input, [
            'erp_plan_id' => ['required', 'integer:strict', 'min:1', 'max:9007199254740991'],
            'price' => ['required', 'string'],
            'currency' => ['required', 'string', Rule::in(['BRL'])],
            'interval_months' => ['required', 'integer:strict', Rule::in([1, 3, 6, 12])],
            'due_in_days' => ['required', 'integer:strict', 'between:0,65535'],
            'start_on' => ['required', 'date_format:Y-m-d'],
            'billing_timezone' => ['required', 'timezone:all'],
        ])->validate();
        $data['price'] = OfficeErpMoney::canonical($data['price'], 'price');
        $data['start_on'] = CarbonImmutable::parse($data['start_on'], $data['billing_timezone'])->toDateString();

        return $data;
    }

    /**
     * Assign already validated frozen terms while the caller holds the office/catalog/plan/Company locks.
     *
     * @return array{contract:OfficeErpContract,allocation:OfficeErpLicenceAllocation,subscription:ErpSubscription}
     */
    public function assignProvisionedLocked(OfficeErpExecutionContext $ctx, Client $client, ErpCompany $company, int $planId, array $terms, ?string $plannedStartOn = null): array
    {
        $ctx->assertVerified();
        abort_unless($ctx->actorKind === 'office_admin' && DB::transactionLevel() > 0, 403);
        $subscription = ErpSubscription::query()->where('erp_company_id', $company->id)->lockForUpdate()->firstOrFail();
        if ($subscription->office_erp_contract_id !== null) {
            $previous = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($subscription->office_erp_contract_id)
                ->where('erp_company_id', $company->id)
                ->first();
            $released = $previous === null ? false : OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->where('contract_id', $previous->id)
                ->where('erp_company_id', $company->id)
                ->where('state', 'released')
                ->exists();
            abort_unless($previous?->agreement_status === 'canceled' && $released, 409, 'company_already_linked');
            $subscription->forceFill(['office_erp_contract_id' => null, 'status' => 'pending']);
        } else {
            abort_unless($subscription->status === 'pending', 409, 'company_already_linked');
        }
        $subscription->forceFill(['erp_plan_id' => $planId])->save();
        $frozen = $this->contracts->createProvisioned($ctx, $client, $company, $planId, $terms, 'office_admin');
        $contract = $frozen['contract'];
        if ($plannedStartOn !== null) {
            $contract->forceFill(['planned_start_on' => $plannedStartOn]);
            OfficeErpRecords::persist($ctx, $contract);
        }
        $allocation = $this->allocator->allocateProvisioned($ctx, $contract, $company);
        $subscription->forceFill(['office_erp_contract_id' => $contract->id])->save();

        return ['contract' => $contract, 'allocation' => $allocation, 'subscription' => $subscription];
    }
}
