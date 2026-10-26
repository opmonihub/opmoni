<?php

namespace App\Erp\Commercial;

use App\Erp\ErpCommercialCatalog;
use App\Erp\ErpCompanyProvisioning;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpPlan;
use App\Rules\ValidCnpj;
use App\Services\BrazilianTaxId;
use App\Services\ClientManager;
use App\Services\PlanLimits;
use App\Support\AccessTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpCustomerEnrollment
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly ErpCommercialCatalog $catalog,
        private readonly OfficeErpOperations $operations,
        private readonly ClientManager $clients,
        private readonly ErpCompanyProvisioning $companies,
        private readonly OfficeErpLicenceAllocator $allocator,
        private readonly OfficeErpContracts $contracts,
    ) {}

    /** @return array{data:list<array{id:int,slug:string,name:string,module_keys:list<string>}>,eligibility:array<string,mixed>} */
    public function plans(OfficeErpExecutionContext $ctx): array
    {
        $capabilities = $this->admission->capabilities($ctx);
        $allowedModules = $capabilities['allowance']['module_keys'];
        $plans = ErpPlan::query()->with('modules')->orderBy('name')->get()
            ->map(function (ErpPlan $plan) use ($allowedModules): ?array {
                $modules = $plan->modules->pluck('module_key')->sort()->values()->all();
                if ($modules === [] || array_diff($modules, $allowedModules) !== []) {
                    return null;
                }

                return ['id' => (int) $plan->id, 'slug' => $plan->slug, 'name' => $plan->name, 'module_keys' => $modules];
            })
            ->filter()
            ->values()
            ->all();

        $used = $capabilities['usage']['reserved'] + $capabilities['usage']['occupied'];
        $limit = $capabilities['allowance']['licence_limit'];
        $reasons = $capabilities['capability_reasons']['can_provision'];
        if ($plans === []) {
            $reasons[] = 'no_eligible_erp_plan';
        }

        return [
            'data' => $plans,
            'eligibility' => [
                'can_provision' => $capabilities['can_provision'] && $plans !== [],
                'reasons' => $reasons,
                'licence_limit' => $limit,
                'used_licences' => $used,
                'available_module_keys' => $allowedModules,
            ],
        ];
    }

    /** @param array<string,mixed> $input @return array{client_id:int,company_id:int,subscription_id:int,contract_id:int,invoice_id:int,owner_setup_status:string} */
    public function enroll(OfficeErpExecutionContext $ctx, array $input, string $key): array
    {
        $data = $this->validate($input);
        $replayExists = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)
            ->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key)
            ->where('action', 'customer.enroll')
            ->exists();
        $prepared = null;
        if (isset($data['new_client']) && ! $replayExists) {
            PlanLimits::assertCanCreate($ctx->account, 'clients');
            $prepared = $this->clients->prepareCompanyRegistration($this->clientData($data['new_client']));
        }
        $fingerprint = hash('sha256', json_encode($data['new_client'] ?? ['client_id' => $data['client_id']], JSON_THROW_ON_ERROR));
        $payload = [
            'command' => 'customer.enroll',
            'customer_fingerprint' => $fingerprint,
            'erp_plan_id' => (int) $data['erp_plan_id'],
            'terms' => [
                'price' => $data['price'],
                'currency' => 'BRL',
                'interval_months' => (int) $data['interval_months'],
                'due_in_days' => (int) $data['due_in_days'],
                'start_on' => $data['start_on'],
                'billing_timezone' => $data['billing_timezone'],
            ],
        ];

        $cnpj = $data['new_client']['tax_id'] ?? null;
        if (isset($data['client_id'])) {
            $selectedClient = Client::withoutGlobalScopes()
                ->where('account_id', $ctx->accountId)
                ->whereKey($data['client_id'])
                ->whereNull('deleted_at')
                ->first();
            abort_unless($selectedClient !== null, 404);
            $cnpj = $selectedClient->tax_id;
        }

        $outcome = DB::transaction(function () use ($ctx, $key, $payload, $data, $prepared, $cnpj): array {
            AccessTransaction::boundLockWaits();
            if (is_string($cnpj)) {
                $this->lockCompanyCnpj($cnpj);
            }

            return $this->operations->execute(
                $ctx,
                $key,
                'customer.enroll',
                $payload,
                fn (string $action, array $storedPayload): mixed => $this->authorizeOperation($ctx, $action, $storedPayload, $key),
                function () use ($ctx, $data, $prepared, $key): array {
                    return DB::transaction(function () use ($ctx, $data, $prepared, $key): array {
                        [$account, $officeSubscription, , $allowance] = $this->admission->lockCommercialAggregate($ctx);
                        abort_unless($officeSubscription?->status === 'active', 403, 'subscription_inactive');
                        $this->catalog->advisory();
                        $plan = ErpPlan::query()->with('modules')->whereKey($data['erp_plan_id'])->lockForUpdate()->firstOrFail();
                        $modules = $plan->modules->pluck('module_key')->sort()->values()->all();
                        abort_unless($modules !== [] && array_diff($modules, $allowance['module_keys']) === [], 403, 'commercial_module_not_allowed');
                        abort_unless(array_intersect($modules, $allowance['module_keys']) === $modules, 403, 'commercial_module_not_allowed');
                        $this->admission->assertCommand($ctx, 'customer.enroll', modules: $modules);

                        if (isset($data['client_id'])) {
                            $client = Client::withoutGlobalScopes()
                                ->where('account_id', $ctx->accountId)
                                ->whereKey($data['client_id'])
                                ->whereNull('deleted_at')
                                ->lockForUpdate()
                                ->firstOrFail();
                            abort_unless($client->person_type->value === 'company' && $client->status->value === 'active', 404);
                            $companyName = $client->name;
                            $cnpj = $client->tax_id;
                        } else {
                            /** @var array{data:array<string,mixed>,company_payload:array<string,mixed>|null} $prepared */
                            $client = $this->clients->createPreparedCompany($account, $prepared);
                            $companyName = $client->name;
                            $cnpj = $client->tax_id;
                        }

                        $companyMatch = ErpCompany::query()->where('cnpj', $cnpj)->lockForUpdate()->first();
                        abort_if($companyMatch !== null, 409, 'company_association_required');

                        $company = $this->companies->createUnclaimedCompany([
                            'name' => $companyName,
                            'cnpj' => $cnpj,
                        ]);
                        $company->subscription()->update(['erp_plan_id' => $plan->id]);
                        $frozen = $this->contracts->createProvisioned($ctx, $client, $company, (int) $plan->id, [
                            'price' => $data['price'],
                            'currency' => 'BRL',
                            'interval_months' => $data['interval_months'],
                            'due_in_days' => $data['due_in_days'],
                            'module_keys' => $modules,
                            'billing_timezone' => $data['billing_timezone'],
                        ], 'office_admin');
                        $contract = $frozen['contract'];
                        $allocation = $this->allocator->allocateProvisioned($ctx, $contract, $company);
                        abort_unless($allocation->state === 'occupied', 409);

                        $activation = $this->contracts->activateProvisioned(
                            $ctx,
                            $contract,
                            CarbonImmutable::parse($data['start_on'], $data['billing_timezone'])->startOfDay(),
                            'enrollment-invoice-'.substr(hash('sha256', $key), 0, 48),
                        );

                        return ['data' => [
                            'client_id' => (int) $client->id,
                            'company_id' => (int) $company->id,
                            'subscription_id' => $activation['subscription_id'],
                            'contract_id' => (int) $contract->id,
                            'invoice_id' => $activation['invoice_id'],
                            'owner_setup_status' => 'unclaimed',
                        ]];
                    }, 3);
                },
            );
        }, 3);

        return $outcome['data'];
    }

    /** @param array<string,mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload, string $key = ''): void
    {
        abort_unless($action === 'customer.enroll' && ($payload['command'] ?? null) === 'customer.enroll', 409);
        abort_unless(is_string($payload['customer_fingerprint'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $payload['customer_fingerprint']) === 1, 409);
        abort_unless(is_int($payload['erp_plan_id'] ?? null) && $payload['erp_plan_id'] > 0, 409);
        abort_unless(is_array($payload['terms'] ?? null), 409);
        $existing = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)
            ->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key)
            ->where('action', 'customer.enroll')
            ->first(['outcome']);
        if ($existing !== null) {
            $outcome = $existing->outcome['data'] ?? null;
            abort_unless(is_array($outcome)
                && is_int($outcome['client_id'] ?? null)
                && is_int($outcome['company_id'] ?? null)
                && is_int($outcome['contract_id'] ?? null), 409);
            $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($outcome['contract_id'])
                ->where('client_id', $outcome['client_id'])
                ->where('erp_company_id', $outcome['company_id'])
                ->where('provisioning_source', 'office_admin')
                ->firstOrFail();
            abort_unless($contract->agreement_status === 'effective', 409);

            return;
        }
        $plan = ErpPlan::query()->with('modules')->whereKey($payload['erp_plan_id'])->firstOrFail();
        $modules = $plan->modules->pluck('module_key')->sort()->values()->all();
        abort_unless($modules !== [], 409);
        $this->admission->assertCommand($ctx, 'customer.enroll', modules: $modules);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validate(array $input): array
    {
        $allowed = ['client_id', 'new_client', 'erp_plan_id', 'price', 'currency', 'interval_months', 'due_in_days', 'start_on', 'billing_timezone'];
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            $errors = [];
            foreach ($unknown as $field) {
                $errors[$field] = 'Campo não permitido.';
            }
            throw ValidationException::withMessages($errors);
        }

        $validator = Validator::make($input, [
            'client_id' => ['sometimes', 'integer:strict', 'min:1'],
            'new_client' => ['sometimes', 'array:tax_id,tax_regime,name,email,phone'],
            'new_client.tax_id' => ['required_with:new_client', 'string', 'size:14', new ValidCnpj],
            'new_client.tax_regime' => ['required_with:new_client', Rule::in(['mei', 'simple_national', 'presumed_profit', 'actual_profit', 'other'])],
            'new_client.name' => ['nullable', 'string', 'max:255'],
            'new_client.email' => ['nullable', 'email', 'max:255'],
            'new_client.phone' => ['nullable', 'string', 'max:20'],
            'erp_plan_id' => ['required', 'integer:strict', 'min:1'],
            'price' => ['required', 'string'],
            'currency' => ['required', 'string', Rule::in(['BRL'])],
            'interval_months' => ['required', 'integer:strict', Rule::in([1, 3, 6, 12])],
            'due_in_days' => ['required', 'integer:strict', 'between:0,65535'],
            'start_on' => ['required', 'date_format:Y-m-d'],
            'billing_timezone' => ['required', 'timezone:all'],
        ]);
        $data = $validator->validate();
        $hasClientId = isset($data['client_id']);
        $hasNewClient = isset($data['new_client']);
        if ($hasClientId === $hasNewClient) {
            throw ValidationException::withMessages(['client_id' => 'Selecione um cliente existente ou informe um novo cliente.']);
        }
        if ($hasNewClient) {
            $data['new_client']['tax_id'] = resolve(BrazilianTaxId::class)->normalize($data['new_client']['tax_id']);
        }
        $data['price'] = OfficeErpMoney::canonical($data['price'], 'price');
        $today = CarbonImmutable::now($data['billing_timezone'])->toDateString();
        if ($data['start_on'] > $today) {
            throw ValidationException::withMessages(['start_on' => 'A data de início deve ser hoje ou anterior.']);
        }

        return $data;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function clientData(array $data): array
    {
        return [
            'person_type' => 'company',
            'tax_id' => $data['tax_id'],
            'name' => $data['name'] ?? null,
            'status' => 'active',
            'tax_regime' => $data['tax_regime'],
            'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
        ];
    }

    private function lockCompanyCnpj(string $cnpj): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['erp-company:'.$cnpj]);
        }
    }
}
