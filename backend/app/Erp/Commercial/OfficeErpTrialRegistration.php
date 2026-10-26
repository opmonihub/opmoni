<?php

namespace App\Erp\Commercial;

use App\Actions\AccessAccounts;
use App\Actions\ErpCommercialRegistration;
use App\Enums\ClientPersonType;
use App\Enums\ClientStatus;
use App\Erp\ErpCompanyProvisioning;
use App\Http\Resources\IdentityResource;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpTrialLink;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use App\Models\User;
use App\Services\BrazilianTaxId;
use App\Services\ClientManager;
use App\Support\AccessSession;
use App\Support\ErpSession;
use App\Tenant\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OfficeErpTrialRegistration
{
    public function __construct(
        private readonly OfficeErpTrialLinks $links,
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
        private readonly ClientManager $clients,
        private readonly ErpCommercialRegistration $registration,
        private readonly ErpCompanyProvisioning $provisioning,
        private readonly OfficeErpContracts $contracts,
        private readonly OfficeErpLicenceAllocator $allocator,
        private readonly AccessAccounts $identities,
        private readonly BrazilianTaxId $taxId,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{identity: array<string, mixed>, data: array<string, int|string>}
     */
    public function register(Request $request, string $code, array $input, string $key): array
    {
        $this->assertKey($key);
        AccessSession::assertOwnership($request);

        $link = $this->links->resolveForRegistration($code);
        $account = Account::query()->findOrFail((int) $link['account_id']);
        $email = mb_strtolower(trim((string) $input['email']));
        $owner = $request->user();
        $wasAuthenticated = $owner instanceof User;

        if ($owner === null && User::query()->where('email', $email)->exists()) {
            $this->loginRequired();
        }

        if ($owner !== null) {
            abort_unless(mb_strtolower(trim($owner->email)) === $email, 403, 'trial_owner_email_mismatch');
            unset($input['password']);
        } else {
            $password = $input['password'] ?? null;
            if (! is_string($password) || mb_strlen($password) < 8 || mb_strlen($password) > 128) {
                throw ValidationException::withMessages(['password' => 'Defina uma senha com pelo menos 8 caracteres.']);
            }
        }

        $cnpj = $this->taxId->normalize((string) $input['cnpj']);
        if (! $this->taxId->isValidCnpj($cnpj)) {
            throw ValidationException::withMessages(['cnpj' => 'CNPJ inválido.']);
        }

        $companyInput = [
            'person_type' => ClientPersonType::Company->value,
            'tax_id' => $cnpj,
            'name' => trim((string) $input['company_name']),
            'status' => ClientStatus::Active->value,
            'tax_regime' => (string) $input['tax_regime'],
        ];
        $prepared = app(CurrentTenant::class)->run((int) $link['account_id'], fn (): array => $this->clients->prepareCompanyRegistration($companyInput));

        $payload = [
            'link_id' => (int) $link['link_id'],
            'link_revision' => (int) $link['revision'],
            'owner_email' => $email,
            'company_name' => trim((string) $input['company_name']),
            'cnpj' => $cnpj,
            'tax_regime' => (string) $input['tax_regime'],
            'establishment_name' => trim((string) ($input['establishment_name'] ?? 'Matriz')),
            'confirm_existing_association' => (bool) ($input['confirm_existing_association'] ?? false),
            'owner_was_authenticated' => $wasAuthenticated,
        ];

        $journal = DB::transaction(function () use ($owner, $input, $email, $cnpj, $key, $link, $payload, $prepared, $account): array {
            $this->identities->advisory('email:'.$email);
            $this->identities->advisory('erp-company:'.$cnpj);
            $owner ??= $this->registration->createTrialOwner([
                'name' => trim((string) $input['name']),
                'email' => $email,
                'password' => (string) $input['password'],
            ]);

            $context = OfficeErpExecutionContext::trialRegistration($account, $owner, 'user:'.(int) $owner->getKey());

            return $context->run(fn (): array => $this->operations->execute(
                $context,
                $key,
                'trial.register',
                $payload,
                fn (string $action, array $operationPayload): mixed => $this->authorize($context, $link, $action, $operationPayload),
                fn (): array => ['data' => $this->createTrial($context, $link, $owner, $payload, $prepared)],
            ));
        }, 3);

        $data = $journal['data'] ?? [];
        $owner ??= User::query()->findOrFail((int) ($data['owner_user_id'] ?? 0));
        $companyId = (int) ($data['company_id'] ?? 0);
        abort_unless($companyId > 0, 503, 'trial_registration_outcome_unavailable');
        ErpCompany::query()->findOrFail($companyId);
        $membership = ErpCompanyMembership::query()
            ->where('erp_company_id', $companyId)
            ->where('user_id', $owner?->getKey())
            ->where('role', 'owner')
            ->firstOrFail();

        try {
            if (! $wasAuthenticated) {
                AccessSession::authenticate($request, $owner, null);
            }
            ErpSession::applyCompanySelection($request, $companyId, ['kind' => 'direct', 'membership' => $membership]);
        } catch (\Throwable) {
            abort(503, 'trial_registration_committed_login_required');
        }

        unset($data['owner_user_id']);

        return [
            'identity' => (new IdentityResource($owner))->resolve($request),
            'data' => $data,
        ];
    }

    /** @return array<string, int|string> */
    public function current(Request $request, string $key): array
    {
        $this->assertKey($key);
        $owner = $request->user();
        abort_unless($owner instanceof User && $owner->exists, 401);

        $matches = OfficeErpCommercialOperation::query()
            ->where('actor_kind', 'owner')
            ->where('action', 'trial.register')
            ->where('idempotency_key', $key)
            ->where('outcome->owner_user_id', (int) $owner->getKey())
            ->get(['outcome']);

        abort_unless($matches->count() === 1, 404);
        $outcome = $matches->first()->outcome;
        abort_unless(is_array($outcome) && is_array($outcome['data'] ?? null), 404);
        $data = $outcome['data'];
        unset($data['owner_user_id']);

        return $data;
    }

    /** @param array<string, mixed> $payload */
    private function authorize(OfficeErpExecutionContext $context, array $snapshot, string $action, array $payload): void
    {
        abort_unless($action === 'trial.register', 403);
        abort_unless((int) ($payload['link_id'] ?? 0) === (int) $snapshot['link_id']
            && (int) ($payload['link_revision'] ?? 0) === (int) $snapshot['revision'], 409, 'trial_link_changed');

        $this->admission->lockCommercialAggregate($context);
        $link = OfficeErpTrialLink::query()->whereKey((int) $snapshot['link_id'])->lockForUpdate()->first();
        abort_if($link === null || ! $link->enabled, 404);
        abort_if((int) $link->revision !== (int) $snapshot['revision'], 409, 'trial_link_changed');
        abort_unless((int) $link->account_id === $context->accountId
            && (int) $link->erp_plan_id === (int) $snapshot['erp_plan_id'], 409, 'trial_link_changed');

        $this->admission->assertCommand($context, 'trial.register', modules: array_values($snapshot['plan_module_keys']));
    }

    /** @param array<string, mixed> $payload @param array<string, mixed> $prepared @return array<string, int|string> */
    private function createTrial(
        OfficeErpExecutionContext $context,
        array $snapshot,
        User $owner,
        array $payload,
        array $prepared,
    ): array {
        [$account] = $this->admission->lockCommercialAggregate($context);
        abort_unless($account->status === 'active', 403, 'office_inactive');
        abort_if(ErpSubscriptionTrial::query()->where('cnpj', $payload['cnpj'])->exists(), 409, 'trial_already_used');

        $company = ErpCompany::query()->where('cnpj', $payload['cnpj'])->lockForUpdate()->first();
        if ($company !== null) {
            $isOwner = ErpCompanyMembership::query()
                ->where('erp_company_id', $company->id)
                ->where('user_id', $owner->id)
                ->where('role', 'owner')
                ->exists();
            abort_unless($isOwner, 409, 'company_association_required');
            abort_if($company->trial()->exists(), 409, 'trial_already_used');
            $subscription = ErpSubscription::query()->where('erp_company_id', $company->id)->lockForUpdate()->firstOrFail();
            abort_unless($subscription->status === 'pending' && $subscription->office_erp_contract_id === null, 409, 'company_subscription_ineligible');
        }

        $client = $this->clientForRegistration($context, $payload, $company, $owner, $prepared);
        if ($company === null) {
            $company = $this->provisioning->createUnclaimedCompany([
                'name' => $payload['company_name'],
                'cnpj' => $payload['cnpj'],
                'establishment_name' => $payload['establishment_name'],
            ]);
            $company->forceFill(['owner_setup_status' => 'claimed'])->save();
            ErpCompanyMembership::query()->create([
                'erp_company_id' => $company->id,
                'user_id' => $owner->id,
                'role' => 'owner',
            ]);
        }

        $modules = array_values($snapshot['plan_module_keys']);
        $provisioned = $this->contracts->createProvisioned($context, $client, $company, (int) $snapshot['erp_plan_id'], [
            'price' => '0.00',
            'interval_months' => 1,
            'due_in_days' => 0,
            'module_keys' => $modules,
            'billing_timezone' => 'America/Sao_Paulo',
        ], 'office_trial');
        $contract = $provisioned['contract'];
        abort_unless($contract instanceof OfficeErpContract, 503, 'trial_contract_unavailable');

        $subscription = ErpSubscription::query()->where('erp_company_id', $company->id)->lockForUpdate()->firstOrFail();
        $subscription->forceFill([
            'erp_plan_id' => (int) $snapshot['erp_plan_id'],
            'office_erp_contract_id' => $contract->id,
            'status' => 'trialing',
        ])->save();
        $this->allocator->allocateProvisioned($context, $contract, $company);

        $startedAt = CarbonImmutable::now('UTC');
        $endsAt = $startedAt->addHours(168);
        $trial = ErpSubscriptionTrial::query()->create([
            'erp_subscription_id' => $subscription->id,
            'erp_company_id' => $company->id,
            'account_id' => $context->accountId,
            'cnpj' => $payload['cnpj'],
            'started_at' => $startedAt,
            'ends_at' => $endsAt,
            'link_revision' => (int) $snapshot['revision'],
        ]);

        return [
            'client_id' => (int) $client->id,
            'company_id' => (int) $company->id,
            'subscription_id' => (int) $subscription->id,
            'contract_id' => (int) $contract->id,
            'trial_id' => (int) $trial->id,
            'status' => 'trialing',
            'started_at' => $startedAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'owner_user_id' => (int) $owner->id,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function clientForRegistration(OfficeErpExecutionContext $context, array $payload, ?ErpCompany $company, User $owner, array $prepared): Client
    {
        $existing = Client::withoutGlobalScopes()
            ->withTrashed()
            ->where('account_id', $context->accountId)
            ->where('tax_id', $payload['cnpj'])
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            abort_if($existing->trashed() || $existing->status !== ClientStatus::Active, 409, 'client_not_active');
            abort_unless((bool) $payload['owner_was_authenticated'], 409, 'client_association_requires_owner_verification');
            abort_unless((bool) $payload['confirm_existing_association'], 409, 'confirm_existing_association_required');
            if ($company !== null) {
                abort_unless($company->memberships()->where('user_id', $owner->id)->where('role', 'owner')->exists(), 403, 'company_owner_required');
            }

            return $existing;
        }

        abort_unless($company === null, 409, 'company_client_association_required');

        return $this->clients->createPreparedCompany($context->account, $prepared);
    }

    private function assertKey(string $key): void
    {
        if (strlen($key) < 16 || strlen($key) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/D', $key) !== 1) {
            throw ValidationException::withMessages(['idempotency_key' => 'A chave da operação é inválida.']);
        }
    }

    private function loginRequired(): never
    {
        abort(response()->json([
            'code' => 'login_required',
            'message' => 'Entre com sua conta para continuar o cadastro.',
        ], 409));
    }
}
