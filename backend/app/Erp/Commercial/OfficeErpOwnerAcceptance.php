<?php

namespace App\Erp\Commercial;

use App\Actions\AccessAccounts;
use App\Enums\ClientStatus;
use App\Erp\ErpCompanyProvisioning;
use App\Models\Account;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\User;
use App\Services\BrazilianTaxId;
use App\Support\AccessSession;
use App\Support\ErpSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class OfficeErpOwnerAcceptance
{
    public function __construct(
        private readonly OfficeErpInvitationContinuation $continuations,
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpLicenceAllocator $allocator,
        private readonly OfficeErpOperations $operations,
        private readonly ErpCompanyProvisioning $provisioning,
        private readonly BrazilianTaxId $taxId,
    ) {}

    /** @param array<string, mixed> $input */
    public function accept(Request $request, array $input, string $key): OfficeErpContract
    {
        AccessSession::assertOwnership($request);
        $continuation = $this->continuations->verifiedContinuation($request);
        $state = $this->continuations->current($request);
        abort_if(in_array($state['state'] ?? null, ['invalid', 'expired', 'missing'], true), 410, 'invitation_expired');
        abort_if(($state['state'] ?? null) === 'identity_mismatch', 403, 'invitation_owner_mismatch');
        abort_if(($state['state'] ?? null) === 'login_required', 409, 'login_required');
        abort_unless(hash_equals((string) $continuation['operation_key'], $key), 409, 'idempotency_key_mismatch');

        $owner = $request->user();
        if ($owner !== null) {
            abort_unless(mb_strtolower(trim($owner->email)) === $continuation['owner_email'], 403, 'invitation_owner_mismatch');
            abort_if(array_key_exists('password', $input), 422, 'password_not_allowed_for_existing_identity');
        } else {
            if (User::query()->where('email', $continuation['owner_email'])->exists()) {
                abort(response()->json(['code' => 'login_required', 'message' => 'Entre com a conta indicada no convite para continuar.'], 409));
            }
            $password = $input['password'] ?? null;
            if (! is_string($password) || mb_strlen($password) < 8 || mb_strlen($password) > 128) {
                throw ValidationException::withMessages(['password' => 'Defina uma senha com pelo menos 8 caracteres.']);
            }
        }

        $companyId = $input['company_id'] ?? null;
        abort_unless($companyId === null || is_int($companyId) && $companyId > 0, 422, 'invalid_company_selection');
        $account = Account::query()->find($continuation['account_id']);
        abort_unless($account, 410, 'invitation_expired');
        $context = OfficeErpExecutionContext::owner(
            $account,
            $owner,
            'commercial.owner.accept',
            'invitation:'.$continuation['invitation_id'].':'.$continuation['owner_identity'],
        );
        $payload = [
            'invitation_id' => (int) $continuation['invitation_id'],
            'owner_identity' => (string) $continuation['owner_identity'],
            'company_id' => $companyId,
        ];
        $createdOwner = null;

        $outcome = $context->run(function () use ($context, $key, $payload, $continuation, $request, $input, $companyId, &$createdOwner): array {
            return $this->operations->execute(
                $context,
                $key,
                'owner.accept',
                $payload,
                fn (string $action, array $operationPayload): mixed => $this->authorize(
                    $context,
                    $continuation,
                    $request,
                    $action,
                    $operationPayload,
                ),
                function () use ($context, $continuation, $request, $input, $companyId, &$createdOwner): array {
                    $invitation = OfficeErpRecords::query($context, OfficeErpOwnerInvitation::class)
                        ->whereKey($continuation['invitation_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $contract = OfficeErpRecords::query($context, OfficeErpContract::class)
                        ->with(['client', 'currentRevision'])
                        ->whereKey($continuation['contract_id'])
                        ->lockForUpdate()
                        ->firstOrFail();
                    $client = $contract->client;
                    abort_unless($client && $client->status === ClientStatus::Active, 409, 'client_inactive');

                    $owner = $request->user();
                    if (! $owner) {
                        $email = (string) $continuation['owner_email'];
                        app(AccessAccounts::class)->advisory('email:'.$email);
                        abort_if(User::query()->where('email', $email)->lockForUpdate()->exists(), 409, 'login_required');
                        $owner = User::query()->create([
                            'name' => $invitation->owner_name,
                            'email' => $email,
                            'password' => Hash::make((string) ($input['password'] ?? '')),
                            'is_super_admin' => false,
                        ]);
                        $createdOwner = $owner;
                    }

                    if ($companyId !== null) {
                        $company = ErpCompany::query()->whereKey($companyId)->firstOrFail();
                        abort_unless($this->taxId->normalize((string) $company->cnpj) === $this->taxId->normalize((string) $client->tax_id), 422, 'company_cnpj_mismatch');
                        $membership = ErpCompanyMembership::query()
                            ->where('erp_company_id', $company->id)
                            ->where('user_id', $owner->id)
                            ->where('role', 'owner')
                            ->first();
                        abort_unless($membership, 403, 'direct_company_owner_required');
                    } else {
                        $company = $this->provisioning->createCompanyForOwner($owner, [
                            'name' => $client->name,
                            'cnpj' => $this->taxId->normalize((string) $client->tax_id),
                        ]);
                    }

                    $this->allocator->occupy($context, $contract, $company);
                    abort_unless($invitation->status === 'pending', 409, 'invitation_not_pending');
                    $invitation->forceFill(['status' => 'consumed', 'consumed_at' => now()]);
                    OfficeErpRecords::persist($context, $invitation);

                    return ['data' => [
                        'invitation_id' => (int) $invitation->id,
                        'contract_id' => (int) $contract->id,
                        'company' => ['id' => (int) $company->id, 'name' => (string) $company->name],
                        'company_created' => $companyId === null,
                    ]];
                },
            );
        });

        $result = $outcome['data'] ?? [];
        $companyId = (int) ($result['company']['id'] ?? 0);
        abort_unless($companyId > 0, 503, 'owner_acceptance_outcome_unavailable');
        $company = ErpCompany::query()->findOrFail($companyId);
        if ($createdOwner instanceof User) {
            AccessSession::authenticate($request, $createdOwner, null);
        }
        $authenticatedOwner = $createdOwner ?? $request->user();
        $membership = ErpCompanyMembership::query()
            ->where('erp_company_id', $company->id)
            ->where('user_id', $authenticatedOwner?->id)
            ->where('role', 'owner')
            ->firstOrFail();
        ErpSession::applyCompanySelection($request, (int) $company->id, ['kind' => 'direct', 'membership' => $membership]);

        return $context->run(fn (): OfficeErpContract => OfficeErpRecords::query($context, OfficeErpContract::class)
            ->whereKey($continuation['contract_id'])
            ->firstOrFail());
    }

    /** @param array<string, mixed> $continuation @param array<string, mixed> $payload */
    private function authorize(
        OfficeErpExecutionContext $context,
        array $continuation,
        Request $request,
        string $action,
        array $payload,
    ): void {
        abort_unless($action === 'owner.accept', 403);
        abort_unless((int) ($payload['invitation_id'] ?? 0) === (int) $continuation['invitation_id']
            && hash_equals((string) $continuation['owner_identity'], (string) ($payload['owner_identity'] ?? '')), 409);
        $authenticatedOwner = $request->user();
        if ($authenticatedOwner !== null) {
            abort_unless(mb_strtolower(trim($authenticatedOwner->email)) === $continuation['owner_email'], 403, 'invitation_owner_mismatch');
        }
        $targetCompanyId = $payload['company_id'] ?? null;
        $candidate = OfficeErpRecords::query($context, OfficeErpContract::class)
            ->whereKey($continuation['contract_id'])
            ->firstOrFail();
        [$account, $subscription, , $allowance] = $this->admission->lockCommercialAggregate($context);
        abort_unless($account->status === 'active', 403, 'resale_disabled');
        $companyIdToLock = $targetCompanyId ?? ($candidate->agreement_status === 'accepted' ? $candidate->erp_company_id : null);
        $company = $companyIdToLock === null
            ? null
            : ErpCompany::query()->whereKey($companyIdToLock)->lockForUpdate()->firstOrFail();
        if ($company !== null && $authenticatedOwner !== null) {
            abort_unless(ErpCompanyMembership::query()
                ->where('erp_company_id', $company->id)
                ->where('user_id', $authenticatedOwner->id)
                ->where('role', 'owner')
                ->lockForUpdate()
                ->exists(), 403, 'direct_company_owner_required');
        }
        $this->admission->prepareAdmissionLocks($context, $candidate, $company, false);
        $contract = OfficeErpRecords::query($context, OfficeErpContract::class)
            ->with('currentRevision')
            ->whereKey($continuation['contract_id'])
            ->lockForUpdate()
            ->firstOrFail();
        $allocation = OfficeErpRecords::query($context, OfficeErpLicenceAllocation::class)
            ->where('contract_id', $contract->id)
            ->lockForUpdate()
            ->first();
        $invitation = OfficeErpRecords::query($context, OfficeErpOwnerInvitation::class)
            ->whereKey($continuation['invitation_id'])
            ->lockForUpdate()
            ->firstOrFail();

        abort_unless((int) $invitation->account_id === $context->accountId
            && (int) $invitation->contract_id === (int) $contract->id
            && $invitation->owner_email === $continuation['owner_email'], 404);

        $owner = $authenticatedOwner === null
            ? null
            : User::query()->whereKey($authenticatedOwner->id)->lockForUpdate()->first();
        if ($owner !== null) {
            abort_unless(mb_strtolower(trim($owner->email)) === $continuation['owner_email'], 403, 'invitation_owner_mismatch');
        } else {
            app(AccessAccounts::class)->advisory('email:'.$continuation['owner_email']);
            abort_if(User::query()->where('email', $continuation['owner_email'])->lockForUpdate()->exists(), 409, 'login_required');
        }

        if ($invitation->status === 'consumed') {
            abort_unless($contract->agreement_status === 'accepted'
                && $contract->erp_company_id !== null
                && ($targetCompanyId === null || (int) $contract->erp_company_id === (int) $targetCompanyId)
                && $allocation?->state === 'occupied'
                && (int) $allocation->erp_company_id === (int) $contract->erp_company_id, 409, 'invitation_acceptance_conflict');

            return;
        }

        abort_unless($subscription?->status === 'active' && $allowance['enabled'], 403, 'resale_disabled');
        abort_unless($invitation->status === 'pending'
            && $invitation->expires_at !== null
            && $invitation->expires_at->isFuture(), 410, 'invitation_expired');
        abort_unless($contract->agreement_status === 'awaiting_owner'
            && $contract->erp_company_id === null
            && $contract->currentRevision !== null, 409, 'contract_not_awaiting_owner');
        abort_unless($allocation?->state === 'reserved'
            && $allocation->expires_at !== null
            && $allocation->expires_at->isFuture(), 410, 'licence_reservation_expired');
        $moduleKeys = $contract->currentRevision->module_keys;
        abort_unless(is_array($moduleKeys) && array_diff($moduleKeys, $allowance['module_keys']) === [], 403, 'commercial_module_not_allowed');
        $usage = $this->admission->usage($context);
        $used = $usage['reserved'] + $usage['occupied'];
        $limit = $allowance['licence_limit'];
        abort_if($limit !== null && $used > $limit, 409, 'licence_capacity_reached');

        if ($company !== null) {
            $client = Client::query()->whereKey($contract->client_id)->firstOrFail();
            abort_unless($client->status === ClientStatus::Active, 409, 'client_inactive');
            abort_unless($this->taxId->normalize((string) $company->cnpj) === $this->taxId->normalize((string) $client->tax_id), 422, 'company_cnpj_mismatch');
            abort_unless($owner !== null, 403, 'direct_company_owner_required');
        }
    }
}
