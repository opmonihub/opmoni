<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Closure;

/** @property-read string $actorIdentity */
final class OfficeErpExecutionContext
{
    public readonly int $accountId;

    private string $verifiedActorIdentity = '';

    private bool $verified = false;

    public function __construct(
        public readonly Account $account,
        public readonly ?User $actor,
        public readonly string $actorKind,
        public readonly string $purpose,
    ) {
        abort_unless(in_array($actorKind, ['office_admin', 'owner', 'system'], true), 403);
        abort_unless($account->exists && (int) $account->getKey() > 0, 403);
        abort_unless(trim($purpose) !== '' && strlen($purpose) <= 180 && preg_match('/[\x00-\x1F\x7F]/', $purpose) !== 1, 403);

        $this->accountId = (int) $account->getKey();

        if ($actorKind === 'office_admin') {
            abort_unless($actor instanceof User && $actor->exists, 403);
            $this->assertSelectedOfficeAdmin($actor);
            $this->verifiedActorIdentity = 'office-admin:user:'.(int) $actor->getKey();
            $this->verified = true;
        }
    }

    public static function officeAdmin(Account $account, User $actor, string $purpose): self
    {
        return new self($account, $actor, 'office_admin', $purpose);
    }

    public static function owner(Account $account, ?User $actor, string $purpose, string $verifiedInvitationIdentity): self
    {
        abort_unless($account->status === 'active', 403);
        abort_unless(trim($verifiedInvitationIdentity) !== '' && strlen($verifiedInvitationIdentity) <= 255, 403);

        $context = new self($account, $actor, 'owner', $purpose);
        $context->verifiedActorIdentity = 'owner-invitation:'.hash('sha256', $verifiedInvitationIdentity);
        $context->verified = true;

        return $context;
    }

    public static function system(Account $account, string $verifiedResourcePurpose): self
    {
        abort_unless($account->status === 'active', 403);
        abort_unless(trim($verifiedResourcePurpose) !== '' && strlen($verifiedResourcePurpose) <= 180, 403);

        $context = new self($account, null, 'system', $verifiedResourcePurpose);
        $context->verifiedActorIdentity = 'system-resource:'.hash('sha256', $verifiedResourcePurpose);
        $context->verified = true;

        return $context;
    }

    public function assertVerified(): void
    {
        abort_unless($this->verified && $this->verifiedActorIdentity !== '', 403);
    }

    public function run(Closure $callback): mixed
    {
        $this->assertVerified();

        return app(CurrentTenant::class)->run($this->accountId, $callback);
    }

    private function assertSelectedOfficeAdmin(User $actor): void
    {
        $selected = CurrentAccount::fromRequest();
        $membership = $selected->membership;
        $actorId = (int) $actor->getKey();

        abort_unless(
            $selected->account->exists
                && (int) $selected->account->getKey() === $this->accountId
                && $membership->exists
                && (int) $membership->getKey() > 0
                && (int) $membership->account_id === $this->accountId
                && (int) $membership->user_id === $actorId
                && $membership->role === 'admin'
                && Account::query()->whereKey($this->accountId)->where('status', 'active')->exists()
                && User::query()->whereKey($actorId)->exists()
                && AccountMembership::query()
                    ->whereKey($membership->getKey())
                    ->where('account_id', $this->accountId)
                    ->where('user_id', $actorId)
                    ->where('role', 'admin')
                    ->exists(),
            403,
        );
    }

    public function __get(string $name): mixed
    {
        if ($name === 'actorIdentity') {
            $this->assertVerified();

            return $this->verifiedActorIdentity;
        }

        throw new \LogicException('Undefined Office ERP execution context property.');
    }

    public function __set(string $name, mixed $value): void
    {
        throw new \LogicException('Office ERP execution context is immutable.');
    }
}
