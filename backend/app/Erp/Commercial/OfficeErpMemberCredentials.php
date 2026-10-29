<?php

namespace App\Erp\Commercial;

use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\User;
use App\Support\AccessRecoveryBudget;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

final class OfficeErpMemberCredentials
{
    public function __construct(
        private readonly OfficeErpMemberAuthority $authority,
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
        private readonly OfficeErpAccessDeliveries $deliveries,
        private readonly AccessRecoveryBudget $budget,
    ) {}

    /** @return array<string, mixed> */
    public function reset(Request $request, OfficeErpExecutionContext $ctx, int $companyId, int $membershipId, int $expectedRevision, string $password, string $key): array
    {
        $payload = [
            'company_id' => $companyId,
            'membership_id' => $membershipId,
            'expected_auth_revision' => $expectedRevision,
        ];
        $authority = null;

        return $ctx->run(fn (): array => $this->operations->execute(
            $ctx,
            $key,
            'member.password_reset',
            $payload,
            function (string $action, array $operationPayload) use ($ctx, &$authority): void {
                $authority = $this->resolveAuthorizedOperation($ctx, $action, $operationPayload);
            },
            function () use ($request, $ctx, $payload, $password, &$authority): array {
                abort_unless(is_array($authority), 404);
                $this->budget->sensitive($request, (int) $ctx->actor?->getKey());
                /** @var User $user */
                $user = $authority['user'];
                abort_unless((int) $user->access_auth_revision === $payload['expected_auth_revision'], 409, 'authentication_revision_changed');
                $revision = (int) $user->access_auth_revision + 1;
                try {
                    $user->forceFill(['password' => Hash::make($password), 'access_auth_revision' => $revision])->save();
                    Password::broker()->deleteToken($user);
                    $this->deliveries->invalidateOtherRecoveryAuthorities($ctx, (int) $user->getKey());
                } catch (QueryException) {
                    abort(503, 'credential_store_unavailable');
                }

                return ['data' => [
                    'company_id' => $payload['company_id'],
                    'membership_id' => $payload['membership_id'],
                    'auth_revision' => $revision,
                    'changed' => true,
                ]];
            },
        ));
    }

    /** @return array<string, mixed> */
    public function issueRecovery(Request $request, OfficeErpExecutionContext $ctx, int $companyId, int $membershipId, string $key): array
    {
        $payload = ['company_id' => $companyId, 'membership_id' => $membershipId];
        $authority = null;

        return $ctx->run(fn (): array => $this->operations->execute(
            $ctx,
            $key,
            'member.password_recovery.issue',
            $payload,
            function (string $action, array $operationPayload) use ($ctx, &$authority): void {
                $authority = $this->resolveAuthorizedOperation($ctx, $action, $operationPayload);
            },
            function () use ($request, $ctx, $payload, $key, &$authority): array {
                abort_unless(is_array($authority), 404);
                $this->budget->sensitive($request, (int) $ctx->actor?->getKey());
                /** @var User $user */
                $user = $authority['user'];
                $this->budget->recipient((string) $user->email);
                try {
                    $token = Password::broker()->createToken($user);
                    $delivery = $this->deliveries->prepare(
                        $ctx,
                        $key,
                        'password_recovery',
                        [
                            'registration_id' => (int) $authority['registration']->getKey(),
                            'company_id' => $payload['company_id'],
                            'membership_id' => $payload['membership_id'],
                            'recipient_user_id' => (int) $user->getKey(),
                            'recipient_email' => (string) $user->email,
                            'access_auth_revision' => (int) $user->access_auth_revision,
                        ],
                        ['token' => $token],
                        CarbonImmutable::now()->addMinutes(min(60, $this->passwordBrokerExpiryMinutes())),
                    );
                } catch (QueryException) {
                    abort(503, 'recovery_store_unavailable');
                }

                return ['data' => [
                    'company_id' => $payload['company_id'],
                    'membership_id' => $payload['membership_id'],
                    'delivery_id' => (int) $delivery['id'],
                    'delivery_state' => (string) $delivery['state'],
                ]];
            },
        ));
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload, string $operationKey = ''): void
    {
        $this->resolveAuthorizedOperation($ctx, $action, $payload);
    }

    /** @return array{can_direct_reset: bool, can_email_recovery: bool, direct_reset_unavailable_reason: ?string} */
    public function capabilities(OfficeErpExecutionContext $ctx, int $companyId, int $membershipId): array
    {
        $authority = $this->authority->resolve($ctx, $companyId, $membershipId);
        $this->admission->assertCommand($ctx, 'member.password_reset', $authority['contract']);
        $this->admission->assertCommand($ctx, 'member.password_recovery.issue', $authority['contract']);

        $reason = $this->directResetUnavailableReason($ctx, $authority['user']);

        return [
            'can_direct_reset' => $reason === null,
            'can_email_recovery' => true,
            'direct_reset_unavailable_reason' => $reason,
        ];
    }

    public function invalidateRecoveryForMembership(OfficeErpExecutionContext $ctx, int $membershipId, int $recipientUserId): void
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'recovery_invalidation_requires_transaction');
        $user = User::query()->whereKey($recipientUserId)->firstOrFail();
        $broker = (string) config('auth.defaults.passwords', 'users');
        $table = (string) config("auth.passwords.{$broker}.table");
        DB::table($table)
            ->where('email', (string) $user->email)
            ->lockForUpdate()
            ->first();

        $authorities = OfficeErpAccessDelivery::query()
            ->where('recipient_user_id', $recipientUserId)
            ->where('purpose', 'password_recovery')
            ->whereNull('authority_invalidated_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($authorities->count() === 1 && (int) $authorities->first()->membership_id === $membershipId) {
            Password::broker()->deleteToken($user);
        }

        $this->deliveries->invalidateRecoveryForMembership($ctx, $membershipId);
    }

    /** @param array<string, mixed> $payload
     * @return array{registration: mixed,company: mixed,contract: mixed,membership: ErpCompanyMembership,user: User}
     */
    private function resolveAuthorizedOperation(OfficeErpExecutionContext $ctx, string $action, array $payload): array
    {
        $expectedKeys = match ($action) {
            'member.password_reset' => ['company_id', 'membership_id', 'expected_auth_revision'],
            'member.password_recovery.issue' => ['company_id', 'membership_id'],
            default => abort(409),
        };
        abort_unless(array_diff(array_keys($payload), $expectedKeys) === []
            && array_diff($expectedKeys, array_keys($payload)) === [], 409);
        abort_unless(is_int($payload['company_id']) && $payload['company_id'] > 0
            && is_int($payload['membership_id']) && $payload['membership_id'] > 0, 409);
        if ($action === 'member.password_reset') {
            abort_unless(is_int($payload['expected_auth_revision']) && $payload['expected_auth_revision'] >= 0, 409);
        }

        $authority = $this->authority->resolve(
            $ctx,
            (int) $payload['company_id'],
            (int) $payload['membership_id'],
            true,
            [],
        );
        if ($action === 'member.password_reset') {
            $this->assertDirectResetTarget($ctx, $authority['user']);
        }
        $this->admission->assertCommand($ctx, $action, $authority['contract']);

        return $authority;
    }

    private function assertDirectResetTarget(OfficeErpExecutionContext $ctx, User $user): void
    {
        abort_if($this->directResetUnavailableReason($ctx, $user) !== null, 403, 'credential_reset_not_allowed');
    }

    private function directResetUnavailableReason(OfficeErpExecutionContext $ctx, User $user): ?string
    {
        return (int) $user->getKey() === (int) $ctx->actor?->getKey()
            || $user->isSuperAdmin()
            || AccountMembership::query()->where('user_id', $user->getKey())->where('role', 'admin')->exists()
                ? 'protected_identity'
                : null;
    }

    private function passwordBrokerExpiryMinutes(): int
    {
        $broker = (string) config('auth.defaults.passwords', 'users');

        return max(1, (int) config("auth.passwords.{$broker}.expire", 60));
    }
}
