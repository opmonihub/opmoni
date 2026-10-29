<?php

namespace App\Erp\Commercial;

use App\Actions\AccessAccounts;
use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\OwnerActivation;
use App\Models\User;
use App\Support\AccessSession;
use App\Support\ErpSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

final class OfficeErpOwnerSetup
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
    ) {}

    /** @param array<string, mixed> $owner @return array<string, mixed> */
    public function issue(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, array $owner, string $key): array
    {
        $owner = $this->normalizeOwner($owner);
        $payload = ['contract_id' => (int) $contract->id, 'owner' => $owner];
        $fragmentUrl = null;

        $outcome = $this->operations->execute(
            $ctx,
            $key,
            'owner.setup.issue',
            $payload,
            fn (string $action, array $operationPayload): mixed => $this->authorizeIssue($ctx, $action, $operationPayload, $key),
            function () use ($ctx, $contract, $owner, &$fragmentUrl): array {
                $lockedContract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                    ->whereKey($contract->id)
                    ->with(['client', 'currentRevision'])
                    ->lockForUpdate()
                    ->firstOrFail();
                $company = ErpCompany::query()->whereKey($lockedContract->erp_company_id)->lockForUpdate()->firstOrFail();
                abort_unless($company->owner_setup_status === 'unclaimed', 409, 'company_owner_already_claimed');
                abort_unless(ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('role', 'owner')->doesntExist(), 409, 'company_owner_already_claimed');

                $existing = OfficeErpRecords::query($ctx, OfficeErpOwnerInvitation::class)
                    ->where('contract_id', $lockedContract->id)
                    ->where('purpose', 'access_setup')
                    ->where('status', 'pending')
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->get();
                foreach ($existing as $previous) {
                    $previous->forceFill([
                        'status' => 'revoked',
                        'revoked_at' => now(),
                        'revocation_reason' => 'Replaced by an explicitly requested owner setup continuation.',
                    ]);
                    OfficeErpRecords::persist($ctx, $previous);
                }
                $secret = bin2hex(random_bytes(32));
                $invitation = new OfficeErpOwnerInvitation;
                $invitation->forceFill([
                    'contract_id' => $lockedContract->id,
                    'owner_name' => $owner['full_name'],
                    'owner_email' => $owner['email'],
                    'seller_snapshot' => ['account_id' => $ctx->accountId, 'name' => $ctx->account->name],
                    'token_hash' => hash('sha256', $secret),
                    'status' => 'pending',
                    'expires_at' => now()->addDays(7),
                    'created_by_user_id' => $ctx->actor?->getKey(),
                    'purpose' => 'access_setup',
                ]);
                /** @var OfficeErpOwnerInvitation $invitation */
                $invitation = OfficeErpRecords::persist($ctx, $invitation);
                $fragmentUrl = $this->fragmentUrl($secret);

                return ['data' => [
                    'invitation_id' => (int) $invitation->id,
                    'contract_id' => (int) $lockedContract->id,
                    'company_id' => (int) $company->id,
                    'owner_setup_status' => 'unclaimed',
                    'expires_at' => $invitation->expires_at->toDateTimeString(),
                    'status' => 'pending',
                ]];
            },
        );

        $data = $outcome['data'] ?? [];

        return $fragmentUrl === null ? $data : $data + ['url' => $fragmentUrl];
    }

    /** @param array<string, mixed> $input */
    public function accept(Request $request, array $input, string $key): OfficeErpContract
    {
        AccessSession::assertOwnership($request);
        $continuation = app(OfficeErpInvitationContinuation::class)->verifiedContinuation($request);
        abort_unless(($continuation['purpose'] ?? null) === 'access_setup', 409, 'invitation_purpose_mismatch');
        abort_unless(hash_equals((string) $continuation['operation_key'], $key), 409, 'idempotency_key_mismatch');
        $owner = $request->user();
        if ($owner !== null) {
            abort_unless(mb_strtolower(trim($owner->email)) === $continuation['owner_email'], 403, 'invitation_owner_mismatch');
            abort_if(array_key_exists('password', $input), 422, 'password_not_allowed_for_existing_identity');
        } elseif (User::query()->where('email', $continuation['owner_email'])->exists()) {
            abort(response()->json(['code' => 'login_required', 'message' => 'Entre com a conta indicada para continuar.'], 409));
        }

        $account = Account::query()->find($continuation['account_id']);
        abort_unless($account && $account->status === 'active', 410, 'invitation_expired');
        $ctx = OfficeErpExecutionContext::owner(
            $account,
            $owner,
            'commercial.owner.setup',
            'invitation:'.$continuation['invitation_id'].':'.$continuation['owner_identity'],
        );
        $payload = [
            'invitation_id' => (int) $continuation['invitation_id'],
            'owner_identity' => (string) $continuation['owner_identity'],
        ];
        $createdOwner = null;
        $result = $this->operations->execute(
            $ctx,
            $key,
            'owner.accept',
            $payload,
            fn (string $action, array $operationPayload): mixed => $this->authorizeAccept($ctx, $continuation, $request, $action, $operationPayload, $key),
            function () use ($ctx, $continuation, $request, $input, $key, &$createdOwner): array {
                return DB::transaction(function () use ($ctx, $continuation, $request, $input, $key, &$createdOwner): array {
                    [, $contract, $company, , $invitation, $owner] = $this->lockAcceptanceState($ctx, $continuation, $request, $key, false);
                    if (! $owner) {
                        $email = (string) $continuation['owner_email'];
                        abort_if(User::query()->where('email', $email)->lockForUpdate()->exists(), 409, 'login_required');
                        $password = $input['password'] ?? null;
                        abort_unless(is_string($password) && mb_strlen($password) >= 8 && mb_strlen($password) <= 128, 422, 'invalid_password');
                        $owner = User::query()->create([
                            'name' => $invitation->owner_name,
                            'email' => $email,
                            'password' => Hash::make($password),
                            'is_super_admin' => false,
                        ]);
                        $createdOwner = $owner;
                    }

                    $membership = ErpCompanyMembership::query()->firstOrCreate([
                        'erp_company_id' => $company->id,
                        'user_id' => $owner->id,
                        'role' => 'owner',
                    ]);
                    abort_unless($membership->wasRecentlyCreated, 409, 'company_owner_already_claimed');
                    $company->forceFill(['owner_setup_status' => 'claimed'])->save();
                    $invitation->forceFill(['status' => 'consumed', 'consumed_at' => now()]);
                    OfficeErpRecords::persist($ctx, $invitation);

                    return ['data' => ['company_id' => (int) $company->id, 'contract_id' => (int) $contract->id]];
                }, 3);
            },
        );

        $companyId = (int) ($result['data']['company_id'] ?? 0);
        abort_unless($companyId > 0, 503, 'owner_setup_outcome_unavailable');
        if ($createdOwner instanceof User) {
            AccessSession::authenticate($request, $createdOwner, null, 0);
        }
        $authenticatedOwner = $createdOwner ?? $request->user();
        $membership = ErpCompanyMembership::query()
            ->where('erp_company_id', $companyId)
            ->where('user_id', $authenticatedOwner?->id)
            ->where('role', 'owner')
            ->firstOrFail();
        ErpSession::applyCompanySelection($request, $companyId, ['kind' => 'direct', 'membership' => $membership]);

        return $ctx->run(fn (): OfficeErpContract => OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($continuation['contract_id'])
            ->with('company')
            ->firstOrFail());
    }

    /** @param array<string, mixed> $payload */
    private function authorizeIssue(OfficeErpExecutionContext $ctx, string $action, array $payload, string $key): void
    {
        abort_unless($action === 'owner.setup.issue' && $ctx->actorKind === 'office_admin', 403);
        $contractId = $payload['contract_id'] ?? null;
        abort_unless(is_int($contractId) && $contractId > 0, 409);
        $owner = $this->normalizeOwner($payload['owner'] ?? []);
        $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->with(['currentRevision', 'company'])
            ->whereKey($contractId)
            ->firstOrFail();
        $this->admission->lockCommercialAggregate($ctx);
        $company = $contract->company === null
            ? null
            : ErpCompany::query()->whereKey($contract->company->id)->lockForUpdate()->first();
        abort_unless($company && (int) $contract->account_id === $ctx->accountId, 404);
        $operationExists = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)
            ->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key)
            ->where('action', 'owner.setup.issue')
            ->exists();
        abort_unless($company->owner_setup_status === 'unclaimed' || $operationExists, 409, 'company_owner_already_claimed');
        $allocation = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
            ->where('contract_id', $contract->id)->where('erp_company_id', $company->id)->first();
        $this->admission->assertCommand($ctx, 'owner.setup.issue', $contract, $contract->currentRevision?->module_keys ?? [], $allocation);
        abort_unless($owner['email'] !== '', 422);
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload, string $key): void
    {
        $this->authorizeIssue($ctx, $action, $payload, $key);
    }

    /** @param array<string, mixed> $continuation @param array<string, mixed> $payload */
    private function authorizeAccept(OfficeErpExecutionContext $ctx, array $continuation, Request $request, string $action, array $payload, string $key): void
    {
        abort_unless($action === 'owner.accept', 403);
        abort_unless((int) ($payload['invitation_id'] ?? 0) === (int) $continuation['invitation_id']
            && hash_equals((string) $continuation['owner_identity'], (string) ($payload['owner_identity'] ?? '')), 409);

        $this->lockAcceptanceState($ctx, $continuation, $request, $key);
    }

    /** @param array<string, mixed> $continuation @return array{Account, OfficeErpContract, ErpCompany, OfficeErpLicenceAllocation, OfficeErpOwnerInvitation, ?User} */
    private function lockAcceptanceState(OfficeErpExecutionContext $ctx, array $continuation, Request $request, string $key, bool $acquireEmailAdvisory = true): array
    {
        $owner = $request->user();
        if ($acquireEmailAdvisory) {
            app(AccessAccounts::class)->advisory('email:'.$continuation['owner_email']);
        }
        if ($owner !== null) {
            $owner = User::query()->whereKey($owner->id)->lockForUpdate()->firstOrFail();
            abort_unless(mb_strtolower(trim($owner->email)) === $continuation['owner_email'], 403, 'invitation_owner_mismatch');
        } else {
            abort_if(User::query()->where('email', $continuation['owner_email'])->lockForUpdate()->exists(), 409, 'login_required');
        }

        [$account] = $this->admission->lockCommercialAggregate($ctx);
        abort_unless($account->status === 'active', 410, 'invitation_expired');
        if ($owner === null) {
            $pendingActivation = OwnerActivation::query()
                ->where('email', $continuation['owner_email'])
                ->whereNull('consumed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            abort_if($pendingActivation !== null, 409, 'owner_activation_pending');
        }
        $contractIdentity = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($continuation['contract_id'])
            ->firstOrFail();
        abort_unless($contractIdentity->erp_company_id !== null, 409, 'owner_setup_not_pending');
        $company = ErpCompany::query()->whereKey($contractIdentity->erp_company_id)->lockForUpdate()->firstOrFail();
        $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($continuation['contract_id'])->lockForUpdate()->firstOrFail();
        $allocation = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
            ->where('contract_id', $contract->id)
            ->where('erp_company_id', $company->id)
            ->lockForUpdate()
            ->first();
        $invitation = OfficeErpRecords::query($ctx, OfficeErpOwnerInvitation::class)
            ->whereKey($continuation['invitation_id'])->lockForUpdate()->firstOrFail();

        abort_unless((int) $invitation->account_id === $ctx->accountId
            && (int) $invitation->contract_id === (int) $contract->id
            && (int) $contract->account_id === $ctx->accountId
            && (int) $contract->erp_company_id === (int) $company->id
            && in_array($contract->agreement_status, ['accepted', 'effective'], true)
            && $invitation->purpose === 'access_setup'
            && $invitation->owner_email === $continuation['owner_email'], 404);
        abort_unless($allocation?->state === 'occupied', 409, 'licence_allocation_not_occupied');

        $operationExists = OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)
            ->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key)
            ->where('action', 'owner.accept')
            ->exists();
        $consumedReplay = $invitation->status === 'consumed' && $operationExists;
        if ($consumedReplay) {
            abort_unless($company->owner_setup_status === 'claimed'
                && $owner !== null
                && ErpCompanyMembership::query()->where('erp_company_id', $company->id)
                    ->where('user_id', $owner->id)->where('role', 'owner')->exists(), 409, 'owner_setup_replay_conflict');
        } else {
            abort_unless((int) $continuation['expires_at'] > now()->timestamp, 410, 'invitation_continuation_expired');
            abort_unless($invitation->status === 'pending', 409, 'owner_setup_not_pending');
            abort_unless($invitation->expires_at !== null && $invitation->expires_at->isFuture(), 410, 'invitation_expired');
            abort_unless($company->owner_setup_status === 'unclaimed'
                && ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('role', 'owner')->doesntExist(), 409, 'company_owner_already_claimed');
        }

        return [$account, $contract, $company, $allocation, $invitation, $owner];
    }

    /** @param array<string, mixed> $owner @return array{full_name: string, email: string} */
    private function normalizeOwner(array $owner): array
    {
        $owner = Validator::make($owner, [
            'full_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ])->validate();

        return ['full_name' => trim($owner['full_name']), 'email' => mb_strtolower(trim($owner['email']))];
    }

    private function fragmentUrl(string $secret): string
    {
        $origin = (string) config('access.origin');
        abort_unless(filter_var($origin, FILTER_VALIDATE_URL) && parse_url($origin, PHP_URL_SCHEME) === 'https'
            || app()->environment('local', 'testing') && filter_var($origin, FILTER_VALIDATE_URL), 500);

        return rtrim($origin, '/').'/erp/ativar#token='.$secret;
    }
}
