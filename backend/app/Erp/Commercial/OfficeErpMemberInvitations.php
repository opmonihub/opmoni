<?php

namespace App\Erp\Commercial;

use App\Erp\ErpCompanyMemberGrants;
use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\User;
use App\Support\AccessRecoveryBudget;
use App\Support\AccessSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class OfficeErpMemberInvitations
{
    public function __construct(
        private readonly OfficeErpMemberAuthority $authority,
        private readonly OfficeErpCompanyMembers $members,
        private readonly OfficeErpOperations $operations,
        private readonly OfficeErpMemberInvitationContinuation $continuations,
    ) {}

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function issue(OfficeErpExecutionContext $ctx, int $companyId, array $input, string $key): array
    {
        $normalized = $this->members->validateInvitation($input);
        $payload = ['company_id' => $companyId, 'invitation' => $normalized];
        $outcome = $this->operations->execute($ctx, $key, 'member.invitation.issue', $payload,
            fn (string $action, array $stored) => $this->authorizeOperation($ctx, $action, $stored),
            fn (): array => $this->createInvitation($ctx, $companyId, $normalized, $key));

        return $outcome['data'];
    }

    /** @return array<string, mixed> */
    public function reissue(OfficeErpExecutionContext $ctx, int $companyId, int $invitationId, string $key): array
    {
        $payload = ['company_id' => $companyId, 'invitation_id' => $invitationId];
        $outcome = $this->operations->execute($ctx, $key, 'member.invitation.reissue', $payload,
            fn (string $action, array $stored) => $this->authorizeOperation($ctx, $action, $stored),
            function () use ($ctx, $companyId, $invitationId, $key): array {
                $access = $this->authority->resolve($ctx, $companyId, null, true);
                $previous = $this->invitation($ctx, $companyId, $invitationId, true);
                abort_unless($previous->status === 'pending' || $previous->status === 'expired', 409, 'invitation_not_reissuable');
                $this->assertInviteeEligible($ctx, (string) $previous->email, (int) $access['company']->id);
                $this->revokePrior($ctx, $previous, 'reissued');
                $normalized = [
                    'name' => $previous->name, 'email' => $previous->email, 'role' => $previous->role,
                    'permissions_mode' => $previous->permissions_mode, 'permissions' => $previous->permissions,
                    'establishment_scope' => $previous->establishment_scope, 'establishment_ids' => $previous->establishment_ids,
                ];

                return $this->createInvitation($ctx, $companyId, $normalized, $key, $access);
            });

        return $outcome['data'];
    }

    /** @return array<string, mixed> */
    public function revoke(OfficeErpExecutionContext $ctx, int $companyId, int $invitationId, string $key): array
    {
        $payload = ['company_id' => $companyId, 'invitation_id' => $invitationId];
        $outcome = $this->operations->execute($ctx, $key, 'member.invitation.revoke', $payload,
            fn (string $action, array $stored) => $this->authorizeOperation($ctx, $action, $stored),
            function () use ($ctx, $companyId, $invitationId): array {
                $this->authority->resolve($ctx, $companyId, null, true);
                $invitation = $this->invitation($ctx, $companyId, $invitationId, true);
                abort_unless($invitation->status === 'pending', 409, 'invitation_not_pending');
                $this->revokePrior($ctx, $invitation, 'revoked');

                return ['data' => ['company_id' => $companyId, 'invitation_id' => $invitationId, 'status' => 'revoked']];
            });

        return $outcome['data'];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function accept(Request $request, array $input, string $key): array
    {
        AccessSession::assertOwnership($request);
        $continuation = $this->continuations->verifiedContinuation($request);
        abort_unless(hash_equals((string) $continuation['operation_key'], $key), 409, 'idempotency_key_mismatch');
        $state = $this->continuations->current($request);
        abort_if(in_array($state['state'] ?? null, ['invalid', 'expired', 'missing'], true), 410, 'invitation_expired');
        abort_if(($state['state'] ?? null) === 'identity_mismatch', 403, 'invitation_identity_mismatch');
        abort_if(($state['state'] ?? null) === 'login_required', 409, 'login_required');

        $recipient = $request->user();
        $newIdentity = $recipient === null;
        if ($recipient !== null) {
            abort_unless(mb_strtolower(trim((string) $recipient->email)) === $continuation['email'], 403, 'invitation_identity_mismatch');
            abort_if(array_key_exists('password', $input) || array_key_exists('password_confirmation', $input), 422, 'password_not_allowed_for_existing_identity');
            $validated = $this->acceptanceInput($input, false);
        } else {
            abort_if(User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$continuation['email']])->exists(), 409, 'login_required');
            $validated = $this->acceptanceInput($input, true);
        }
        $account = Account::query()->whereKey($continuation['account_id'])->where('status', 'active')->firstOrFail();
        $ctx = OfficeErpExecutionContext::system($account, 'member-invitation:'.$continuation['invitation_id']);
        $payload = [
            'company_id' => (int) $continuation['company_id'],
            'invitation_id' => (int) $continuation['invitation_id'],
            'invitation_identity' => (string) $continuation['invitation_identity'],
        ];
        $createdUser = null;
        $outcome = $this->operations->execute($ctx, $key, 'member.invitation.accept', $payload,
            fn (string $action, array $stored) => $this->authorizeAcceptance($ctx, $continuation, $request, $action, $stored),
            function () use ($ctx, $continuation, $request, $validated, $newIdentity, &$createdUser): array {
                $resolved = $this->authority->resolveInvitationAcceptance(
                    $ctx->account,
                    (int) $continuation['company_id'],
                    (int) $continuation['invitation_id'],
                    (string) $continuation['email'],
                    $request->user(),
                    true,
                );
                $invitation = $resolved['invitation'];
                abort_unless($invitation->status === 'pending', 409, 'invitation_not_pending');
                $user = $resolved['user'];
                if ($user === null) {
                    abort_unless($newIdentity, 409, 'login_required');
                    try {
                        $user = User::query()->create([
                            'name' => $validated['name'], 'email' => $continuation['email'],
                            'password' => Hash::make($validated['password']), 'is_super_admin' => false,
                        ]);
                    } catch (QueryException) {
                        abort(503, 'member_identity_unavailable');
                    }
                    $createdUser = $user;
                } else {
                    abort_unless($request->user() !== null && (int) $request->user()->id === (int) $user->id, 409, 'login_required');
                }
                $ids = array_map(intval(...), $invitation->establishment_ids ?? []);
                app(ErpCompanyMemberGrants::class)->assertEstablishments((int) $invitation->erp_company_id, $ids, true);
                $membership = ErpCompanyMembership::query()->create([
                    'erp_company_id' => $invitation->erp_company_id,
                    'user_id' => $user->id,
                    'role' => $invitation->role,
                    'action_permissions' => $invitation->permissions_mode === 'defaults' ? null : ($invitation->permissions === [] ? null : $invitation->permissions),
                    'access_all_establishments' => $invitation->establishment_scope === 'all',
                ]);
                $membership->establishments()->sync(array_fill_keys($ids, ['erp_company_id' => $invitation->erp_company_id]));
                $invitation->forceFill([
                    'status' => 'accepted', 'accepted_at' => now(), 'accepted_by_user_id' => $user->id,
                ])->save();

                return ['data' => [
                    'company_id' => (int) $invitation->erp_company_id,
                    'membership_id' => (int) $membership->id,
                    'invitation_id' => (int) $invitation->id,
                    'status' => 'accepted',
                ]];
            });

        if ($createdUser instanceof User) {
            AccessSession::authenticate($request, $createdUser, null, (int) $createdUser->access_auth_revision);
        }
        AccessSession::assertOwnership($request);

        return $outcome['data'];
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload): void
    {
        $companyId = $payload['company_id'] ?? null;
        abort_unless(is_int($companyId) && in_array($action, [
            'member.invitation.issue', 'member.invitation.reissue', 'member.invitation.revoke',
        ], true), 409);
        $emails = is_string($payload['invitation']['email'] ?? null) ? [$payload['invitation']['email']] : [];
        $this->authority->resolve($ctx, $companyId, null, true, $emails);
        if (in_array($action, ['member.invitation.reissue', 'member.invitation.revoke'], true)) {
            $invitationId = $payload['invitation_id'] ?? null;
            abort_unless(is_int($invitationId), 409);
            $this->invitation($ctx, $companyId, $invitationId, true);
        }
    }

    /** @param array<string, mixed> $continuation @param array<string, mixed> $payload */
    private function authorizeAcceptance(OfficeErpExecutionContext $ctx, array $continuation, Request $request, string $action, array $payload): void
    {
        abort_unless($action === 'member.invitation.accept'
            && (int) ($payload['invitation_id'] ?? 0) === (int) $continuation['invitation_id']
            && (int) ($payload['company_id'] ?? 0) === (int) $continuation['company_id']
            && hash_equals((string) $continuation['invitation_identity'], (string) ($payload['invitation_identity'] ?? '')), 409);
        $this->authority->resolveInvitationAcceptance(
            $ctx->account,
            (int) $continuation['company_id'],
            (int) $continuation['invitation_id'],
            (string) $continuation['email'],
            $request->user(),
            true,
        );
    }

    /** @param array<string, mixed> $input @return array<string, string> */
    private function acceptanceInput(array $input, bool $newIdentity): array
    {
        $allowed = $newIdentity ? ['name', 'password', 'password_confirmation'] : [];
        $unexpected = array_diff(array_keys($input), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'Campo não permitido.'));
        }
        if (! $newIdentity) {
            return [];
        }

        return Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
        ])->validate();
    }

    /** @param array<string, mixed> $normalized @param array<string, mixed>|null $resolved @return array<string, mixed> */
    private function createInvitation(OfficeErpExecutionContext $ctx, int $companyId, array $normalized, string $key, ?array $resolved = null): array
    {
        $resolved ??= $this->authority->resolve($ctx, $companyId, null, true, [(string) $normalized['email']]);
        $email = (string) $normalized['email'];
        app(ErpCompanyMemberGrants::class)->assertEstablishments(
            $companyId,
            array_map(intval(...), $normalized['establishment_ids'] ?? []),
            true,
        );
        $this->assertInviteeEligible($ctx, $email, $companyId);
        abort_if(ErpCompanyMembership::query()->where('erp_company_id', $companyId)
            ->whereIn('user_id', User::query()->select('id')->whereRaw('LOWER(TRIM(email)) = ?', [$email]))->exists(), 409, 'member_already_exists');
        $stale = OfficeErpMemberInvitation::query()->where('account_id', $ctx->accountId)->where('erp_company_id', $companyId)
            ->where('purpose', 'member_access')->where('status', 'pending')->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('expires_at', '<=', now())->lockForUpdate()->get();
        foreach ($stale as $expired) {
            app(OfficeErpAccessDeliveries::class)->invalidateMemberInvitation($ctx, (int) $expired->id);
            $expired->forceFill(['status' => 'expired'])->save();
        }
        abort_if(OfficeErpMemberInvitation::query()->where('account_id', $ctx->accountId)->where('erp_company_id', $companyId)
            ->where('purpose', 'member_access')->where('status', 'pending')->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('expires_at', '>', now())->exists(), 409, 'member_invitation_pending');
        app(AccessRecoveryBudget::class)->sensitive(request(), (int) $ctx->actor?->id, null);

        $token = bin2hex(random_bytes(32));
        $invitation = new OfficeErpMemberInvitation;
        $invitation->forceFill([
            'account_id' => $ctx->accountId,
            'erp_company_id' => $companyId,
            'registration_id' => $resolved['registration']->id,
            'purpose' => 'member_access',
            'name' => $normalized['name'],
            'email' => $email,
            'role' => $normalized['role'],
            'permissions_mode' => $normalized['permissions_mode'],
            'permissions' => $normalized['permissions'],
            'establishment_scope' => $normalized['establishment_scope'],
            'establishment_ids' => $normalized['establishment_ids'],
            'token_hash' => hash('sha256', $token),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
            'created_by_user_id' => $ctx->actor?->id,
        ]);
        $invitation->save();
        $delivery = app(OfficeErpAccessDeliveries::class)->prepare(
            $ctx,
            $key,
            'member_invitation',
            [
                'registration_id' => (int) $resolved['registration']->id,
                'company_id' => $companyId,
                'member_invitation_id' => (int) $invitation->id,
                'recipient_email' => $email,
            ],
            ['token' => $token],
            CarbonImmutable::instance($invitation->expires_at),
        );

        return ['data' => [
            'company_id' => $companyId,
            'invitation' => $this->invitationPayload($invitation),
            'delivery' => ['id' => $delivery['id'], 'state' => $delivery['state']],
        ]];
    }

    private function assertInviteeEligible(OfficeErpExecutionContext $ctx, string $email, int $companyId): void
    {
        $user = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower(trim($email))])->first();
        if ($user === null) {
            return;
        }
        abort_unless((int) $user->id !== (int) $ctx->actor?->id, 403, 'protected_company_member');
        abort_if(DB::table('account_memberships')->where('account_id', $ctx->accountId)->where('user_id', $user->id)->where('role', 'admin')->exists(), 403, 'protected_company_member');
        abort_if(ErpCompanyMembership::query()->where('erp_company_id', $companyId)->where('user_id', $user->id)->exists(), 409, 'member_already_exists');
    }

    private function revokePrior(OfficeErpExecutionContext $ctx, OfficeErpMemberInvitation $invitation, string $reason): void
    {
        app(OfficeErpAccessDeliveries::class)->invalidateMemberInvitation($ctx, (int) $invitation->id);
        $invitation->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
    }

    private function invitation(OfficeErpExecutionContext $ctx, int $companyId, int $invitationId, bool $lock): OfficeErpMemberInvitation
    {
        $query = OfficeErpMemberInvitation::query()->where('account_id', $ctx->accountId)
            ->where('erp_company_id', $companyId)->where('purpose', 'member_access')->whereKey($invitationId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function invitationPayload(OfficeErpMemberInvitation $invitation): array
    {
        return [
            'kind' => 'invitation', 'id' => (int) $invitation->id,
            'name' => (string) $invitation->name, 'email' => (string) $invitation->email,
            'role' => (string) $invitation->role, 'permissions_mode' => (string) $invitation->permissions_mode,
            'action_permissions' => $invitation->permissions,
            'establishment_scope' => (string) $invitation->establishment_scope,
            'establishment_ids' => $invitation->establishment_ids,
            'status' => (string) $invitation->status,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
        ];
    }
}
