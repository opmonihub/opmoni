<?php

namespace App\Erp\Commercial;

use App\Actions\AccessAccounts;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OfficeErpMemberAuthority
{
    public function __construct(private readonly OfficeErpAdmission $admission) {}

    /**
     * @param  list<string>  $emails
     * @return array{registration: OfficeErpCompanyRegistration, company: ErpCompany, contract: OfficeErpContract, membership?: ErpCompanyMembership|null, user?: User|null}
     */
    public function resolve(OfficeErpExecutionContext $ctx, int $companyId, ?int $membershipId = null, bool $lock = false, array $emails = []): array
    {
        abort_unless($ctx->actorKind === 'office_admin' && $ctx->actor !== null, 403);

        return $ctx->run(function () use ($ctx, $companyId, $membershipId, $lock, $emails): array {
            return $this->resolveForContext($ctx, $companyId, $membershipId, $lock, $emails);
        });
    }

    /** @param list<string> $emails @return array<string, mixed> */
    private function resolveForContext(OfficeErpExecutionContext $ctx, int $companyId, ?int $membershipId, bool $lock, array $emails): array
    {
        $preflight = $this->resolveCurrentRelationship($ctx, $companyId, false);
        $membershipIdentity = $membershipId === null
            ? null
            : ErpCompanyMembership::query()->where('erp_company_id', $companyId)->whereKey($membershipId)->firstOrFail();
        if (! $lock) {
            return $preflight + ['membership' => $membershipIdentity, 'user' => $membershipIdentity?->user];
        }

        $normalizedEmails = array_values(array_unique(array_filter(array_map($this->normalizeEmail(...), [...$emails, (string) $ctx->actor->email]))));
        if ($membershipIdentity !== null) {
            $normalizedEmails[] = $this->normalizeEmail((string) $membershipIdentity->user()->value('email'));
            $normalizedEmails = array_values(array_unique($normalizedEmails));
        }
        sort($normalizedEmails, SORT_STRING);
        foreach ($normalizedEmails as $email) {
            app(AccessAccounts::class)->advisory('email:'.$email);
        }

        $emailUsers = $normalizedEmails === []
            ? collect()
            : User::query()->whereIn(DB::raw('LOWER(TRIM(email))'), $normalizedEmails)->get(['id', 'email']);
        $userIds = array_values(array_unique(array_filter([
            (int) $ctx->actor->getKey(),
            $membershipIdentity === null ? null : (int) $membershipIdentity->user_id,
            ...$emailUsers->pluck('id')->map(intval(...))->all(),
        ])));
        sort($userIds, SORT_NUMERIC);
        $users = User::query()->whereIn('id', $userIds)->orderBy('id')->lockForUpdate()->get();
        abort_unless($users->count() === count($userIds), 409);
        $expectedEmails = $emailUsers->pluck('email', 'id');
        if ($membershipIdentity !== null) {
            $expectedEmails->put((int) $membershipIdentity->user_id, (string) $membershipIdentity->user->email);
        }
        $expectedEmails->put((int) $ctx->actor->getKey(), (string) $ctx->actor->email);
        foreach ($users as $user) {
            abort_unless($this->normalizeEmail((string) $expectedEmails->get($user->id)) === $this->normalizeEmail((string) $user->email), 409);
        }
        $actor = $users->firstWhere('id', (int) $ctx->actor->getKey());
        abort_unless($actor !== null, 403);
        $ctx->run(function () use ($ctx): void {
            [$account] = $this->admission->lockCommercialAggregate($ctx);
            abort_unless($account->status === 'active', 403, 'account_inactive');
            abort_unless(AccountMembership::query()->where('account_id', $ctx->accountId)
                ->where('user_id', $ctx->actor?->getKey())->where('role', 'admin')->exists(), 403);
        });

        $current = $this->resolveCurrentRelationship($ctx, $companyId, true);
        $membership = $membershipId === null
            ? null
            : ErpCompanyMembership::query()->where('erp_company_id', $companyId)->whereKey($membershipId)->lockForUpdate()->firstOrFail();
        $user = $membership === null ? null : $users->firstWhere('id', (int) $membership->user_id);
        abort_if($membership !== null && $user === null, 409);

        return $current + ['membership' => $membership, 'user' => $user];
    }

    public function assertDelivery(OfficeErpAccessDelivery $delivery, bool $lock = true): void
    {
        abort_unless($delivery->purpose === 'member_invitation' || $delivery->purpose === 'password_recovery', 404);
        abort_if($delivery->authority_invalidated_at !== null, 410, 'access_delivery_invalidated');
        $operation = OfficeErpCommercialOperation::query()->where('account_id', $delivery->account_id)->whereKey($delivery->operation_id)->firstOrFail();
        abort_unless(preg_match('/\Aoffice-admin:user:([1-9][0-9]*)\z/', (string) $operation->actor_identity, $match) === 1, 403);
        $actorIdentity = User::query()->findOrFail((int) $match[1]);
        $recipientIdentity = $delivery->recipient_user_id === null
            ? null
            : User::query()->find((int) $delivery->recipient_user_id);
        $emailLocks = array_values(array_unique(array_filter([
            $this->normalizeEmail((string) $actorIdentity->email), $this->normalizeEmail((string) $delivery->recipient_email),
        ])));
        sort($emailLocks, SORT_STRING);
        if ($lock) {
            foreach ($emailLocks as $email) {
                app(AccessAccounts::class)->advisory('email:'.$email);
            }
        }
        $userIds = array_values(array_unique(array_filter([(int) $actorIdentity->id, $recipientIdentity?->id])));
        sort($userIds, SORT_NUMERIC);
        $users = User::query()->whereIn('id', $userIds)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
        $actor = $users->get((int) $actorIdentity->id);
        abort_unless($actor !== null, 403);
        $account = Account::query()->whereKey($delivery->account_id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
        $accountMembershipQuery = AccountMembership::query()->where('account_id', $delivery->account_id)
            ->where('user_id', $actor->id)->where('role', 'admin');
        if ($lock) {
            $accountMembershipQuery->lockForUpdate();
        }
        abort_unless($accountMembershipQuery->exists() && $account->status === 'active', 403);
        $ctx = OfficeErpExecutionContext::system($account, 'access.delivery.authority');
        $resolved = $ctx->run(fn (): array => $this->resolveCurrentRelationship($ctx, (int) $delivery->erp_company_id, $lock));
        abort_unless((int) $resolved['registration']->id === (int) $delivery->registration_id, 404);

        if ($delivery->purpose === 'member_invitation') {
            $invitationQuery = OfficeErpMemberInvitation::query()->where('account_id', $delivery->account_id)
                ->where('erp_company_id', $delivery->erp_company_id)->whereKey($delivery->member_invitation_id);
            if ($lock) {
                $invitationQuery->lockForUpdate();
            }
            $invitation = $invitationQuery->firstOrFail();
            abort_unless($invitation->status === 'pending' && $invitation->expires_at?->isFuture()
                && $this->normalizeEmail((string) $invitation->email) === $this->normalizeEmail((string) $delivery->recipient_email), 410);

            return;
        }

        $membershipQuery = ErpCompanyMembership::query()->where('erp_company_id', $delivery->erp_company_id)
            ->whereKey($delivery->membership_id)->where('user_id', $delivery->recipient_user_id);
        if ($lock) {
            $membershipQuery->lockForUpdate();
        }
        $membership = $membershipQuery->firstOrFail();
        $recipient = $users->get((int) $delivery->recipient_user_id);
        abort_unless($membership instanceof ErpCompanyMembership
            && $recipient !== null
            && $this->normalizeEmail((string) $recipient->email) === $this->normalizeEmail((string) $delivery->recipient_email)
            && (int) $recipient->access_auth_revision === (int) $delivery->access_auth_revision, 410);
    }

    /** @return array{context: OfficeErpExecutionContext, registration: OfficeErpCompanyRegistration, company: ErpCompany, contract: OfficeErpContract, invitation: OfficeErpMemberInvitation, user: ?User, membership: ?ErpCompanyMembership} */
    public function resolveInvitationAcceptance(Account $account, int $companyId, int $invitationId, string $email, ?User $authenticated, bool $lock = true): array
    {
        $email = $this->normalizeEmail($email);
        $ctx = OfficeErpExecutionContext::system($account, 'member-invitation:'.$invitationId);

        return $ctx->run(function () use ($ctx, $account, $companyId, $invitationId, $email, $authenticated, $lock): array {
            $invitationIdentity = OfficeErpMemberInvitation::query()->where('account_id', $account->id)
                ->where('erp_company_id', $companyId)->whereKey($invitationId)->where('purpose', 'member_access')
                ->firstOrFail();
            $candidate = User::query()->whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if ($authenticated !== null) {
                abort_unless($this->normalizeEmail((string) $authenticated->email) === $email, 403, 'invitation_identity_mismatch');
                abort_unless($candidate !== null && (int) $candidate->id === (int) $authenticated->id, 409, 'invitation_identity_changed');
            }
            $issuerIdentity = $invitationIdentity->created_by_user_id === null
                ? null
                : User::query()->find((int) $invitationIdentity->created_by_user_id);
            abort_unless($issuerIdentity !== null, 403, 'invitation_issuer_authority_lost');
            $emails = [$email, $this->normalizeEmail((string) $issuerIdentity->email)];
            $emails = array_values(array_unique($emails));
            sort($emails, SORT_STRING);
            if ($lock) {
                foreach ($emails as $emailLock) {
                    app(AccessAccounts::class)->advisory('email:'.$emailLock);
                }
            }
            $userIds = array_values(array_unique(array_filter([$candidate?->id, $issuerIdentity->id])));
            sort($userIds, SORT_NUMERIC);
            $lockedUsers = User::query()->whereIn('id', $userIds)->orderBy('id')
                ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('id');
            $user = $candidate === null ? null : $lockedUsers->get((int) $candidate->id);
            $issuer = $lockedUsers->get((int) $issuerIdentity->id);
            abort_unless($issuer !== null, 403, 'invitation_issuer_authority_lost');
            $lockedAccount = Account::query()->whereKey($account->id)->when($lock, fn ($query) => $query->lockForUpdate())->firstOrFail();
            abort_unless($lockedAccount->status === 'active', 410, 'invitation_expired');
            $issuerMembership = AccountMembership::query()->where('account_id', $account->id)
                ->where('user_id', $issuer->id)->where('role', 'admin');
            if ($lock) {
                $issuerMembership->lockForUpdate();
            }
            abort_unless($issuerMembership->exists(), 403, 'invitation_issuer_authority_lost');
            if ($user !== null) {
                $recipientOfficeAdmin = AccountMembership::query()->where('account_id', $account->id)
                    ->where('user_id', $user->id)->where('role', 'admin');
                if ($lock) {
                    $recipientOfficeAdmin->lockForUpdate();
                }
                abort_if($recipientOfficeAdmin->exists(), 403, 'protected_company_member');
            }
            $relationship = $this->resolveCurrentRelationship($ctx, $companyId, $lock);
            $invitationQuery = OfficeErpMemberInvitation::query()->where('account_id', $account->id)
                ->where('erp_company_id', $companyId)->whereKey($invitationId)->where('purpose', 'member_access');
            if ($lock) {
                $invitationQuery->lockForUpdate();
            }
            $invitation = $invitationQuery->firstOrFail();
            abort_unless($this->normalizeEmail((string) $invitation->email) === $email, 404);
            $membershipQuery = ErpCompanyMembership::query()->where('erp_company_id', $companyId)->where('user_id', $user?->id);
            if ($user === null) {
                $membership = null;
            } else {
                if ($lock) {
                    $membershipQuery->lockForUpdate();
                }
                $membership = $membershipQuery->first();
            }
            if ($invitation->status === 'accepted') {
                abort_unless($user !== null && (int) $invitation->accepted_by_user_id === (int) $user->id
                    && $membership !== null && $membership->role === $invitation->role, 409, 'invitation_acceptance_conflict');
            } else {
                abort_unless($invitation->status === 'pending' && $invitation->expires_at !== null && $invitation->expires_at->isFuture(), 410, 'invitation_expired');
                abort_if($membership !== null, 409, 'member_already_exists');
            }

            return ['context' => $ctx, ...$relationship, 'invitation' => $invitation, 'user' => $user, 'membership' => $membership];
        });
    }

    private function resolveCurrentRelationship(OfficeErpExecutionContext $ctx, int $companyId, bool $lock): array
    {
        $companyQuery = ErpCompany::query()->whereKey($companyId);
        if ($lock) {
            $companyQuery->lockForUpdate();
        }
        $company = $companyQuery->firstOrFail();
        $registrationQuery = OfficeErpRecords::query($ctx, OfficeErpCompanyRegistration::class)->where('erp_company_id', $companyId);
        if ($lock) {
            $registrationQuery->lockForUpdate();
        }
        $registration = $registrationQuery->firstOrFail();
        $subscriptionQuery = ErpSubscription::query()->where('erp_company_id', $companyId);
        if ($lock) {
            $subscriptionQuery->lockForUpdate();
        }
        $subscription = $subscriptionQuery->first();
        abort_unless($subscription?->office_erp_contract_id !== null, 404);
        $contractQuery = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($subscription->office_erp_contract_id)->where('erp_company_id', $companyId)
            ->whereIn('agreement_status', ['accepted', 'effective']);
        if ($lock) {
            $contractQuery->lockForUpdate();
        }
        $contract = $contractQuery->firstOrFail();
        abort_unless($company->status === 'active', 409, 'company_inactive');

        return ['registration' => $registration, 'company' => $company, 'contract' => $contract];
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
