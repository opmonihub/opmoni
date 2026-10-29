<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class OfficeErpPasswordRecovery
{
    private const SESSION_KEY = 'office_erp.password_recovery_continuation';

    private const TTL_SECONDS = 900;

    public function __construct(private readonly OfficeErpMemberAuthority $authority) {}

    /** @return array{state:string} */
    public function exchange(Request $request, string $token, int $deliveryId): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1 || $deliveryId < 1) {
            return ['state' => 'invalid'];
        }

        $candidate = OfficeErpAccessDelivery::query()
            ->whereKey($deliveryId)
            ->where('purpose', 'password_recovery')
            ->first();
        if ($candidate === null || $candidate->recipient_user_id === null) {
            return ['state' => 'invalid'];
        }

        $continuation = $this->withRecoveryStore(function () use ($candidate, $deliveryId, $token): ?array {
            if (! $this->assertDeliveryAuthority($candidate)) {
                return null;
            }
            $user = User::query()->find((int) $candidate->recipient_user_id);
            if ($user === null) {
                return null;
            }
            $resetToken = $this->resetTokenRow((string) $user->email, true);
            $delivery = OfficeErpAccessDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if (! $delivery || ! $resetToken || (int) $delivery->recipient_user_id !== (int) $user->getKey() || ! $this->deliveryIsCurrent($delivery, $user)) {
                return null;
            }

            if (! Password::broker()->tokenExists($user, $token)) {
                return null;
            }
            $payload = [
                'delivery_id' => (int) $delivery->getKey(),
                'recipient_user_id' => (int) $user->getKey(),
                'account_id' => (int) $delivery->account_id,
                'company_id' => (int) $delivery->erp_company_id,
                'membership_id' => (int) $delivery->membership_id,
                'auth_revision' => (int) $delivery->access_auth_revision,
                'broker_token_verifier' => (string) $resetToken->token,
                'receiver_nonce' => bin2hex(random_bytes(32)),
                'expires_at' => min(now()->addSeconds(self::TTL_SECONDS)->timestamp, $delivery->expires_at?->timestamp ?? now()->timestamp),
            ];

            return [...$payload, 'signature' => $this->sign($payload)];
        });

        if ($continuation === null) {
            $this->clear($request);

            return ['state' => 'invalid'];
        }
        $request->session()->put(self::SESSION_KEY, $continuation);

        if ($this->hasIdentityMismatch($request, $continuation)) {
            return ['state' => 'identity_mismatch'];
        }

        return ['state' => 'valid'];
    }

    /** @return array<string, mixed>|null */
    public static function preservableForAuthentication(Request $request): ?array
    {
        return app(self::class)->preservableContinuation($request);
    }

    /** @param array<string, mixed>|null $continuation */
    public static function restoreAfterAuthentication(Request $request, ?array $continuation): void
    {
        app(self::class)->restoreContinuation($request, $continuation);
    }

    /** @return array<string, mixed>|null */
    public static function preservableForLogout(Request $request): ?array
    {
        return app(self::class)->preservableContinuation($request);
    }

    /** @param array<string, mixed>|null $continuation */
    public static function restoreAfterLogout(Request $request, ?array $continuation): void
    {
        app(self::class)->restoreContinuation($request, $continuation);
    }

    /** @return array<string, mixed>|null */
    public static function preservableCompletedForRevisionMismatch(Request $request, int $userId, int $capturedRevision): ?array
    {
        return app(self::class)->completedContinuationForRevisionMismatch($request, $userId, $capturedRevision);
    }

    /** @param array<string, mixed>|null $continuation */
    public static function restoreAfterRevisionMismatch(Request $request, ?array $continuation): void
    {
        app(self::class)->restoreContinuation($request, $continuation);
    }

    /** @return array{state:string} */
    public function current(Request $request): array
    {
        $continuation = $this->verifiedPayload($request);
        if ($continuation !== null && $this->hasIdentityMismatch($request, $continuation)) {
            return ['state' => 'identity_mismatch'];
        }
        if ($continuation !== null && $this->hasCompletedReceipt($continuation)) {
            return ['state' => 'completed'];
        }

        return $this->isCurrent($request) ? ['state' => 'valid'] : ['state' => 'invalid'];
    }

    /** @return array{state:string} */
    public function complete(Request $request, string $password): array
    {
        $continuation = $this->verifiedPayload($request);
        if ($continuation !== null && $this->hasIdentityMismatch($request, $continuation)) {
            return ['state' => 'identity_mismatch'];
        }
        if ($continuation !== null && $this->hasCompletedReceipt($continuation)) {
            return ['state' => 'completed'];
        }
        if ($continuation === null || ! $this->isCurrent($request)) {
            $this->clear($request);

            return ['state' => 'invalid'];
        }

        $completed = $this->withRecoveryStore(function () use ($continuation, $password): bool {
            $candidate = OfficeErpAccessDelivery::query()->whereKey($continuation['delivery_id'])->first();
            if ($candidate === null || ! $this->assertDeliveryAuthority($candidate)) {
                return false;
            }
            $user = User::query()->find((int) $candidate->recipient_user_id);
            if ($user === null) {
                return false;
            }
            $resetToken = $this->resetTokenRow((string) $user->email, true);
            $delivery = OfficeErpAccessDelivery::query()->whereKey($continuation['delivery_id'])->lockForUpdate()->first();
            if (! $delivery || (int) $delivery->recipient_user_id !== (int) $user->getKey()) {
                return false;
            }
            if ($this->completedReceiptMatches($delivery, $user, $continuation)) {
                return true;
            }
            if (! $this->deliveryIsCurrent($delivery, $user)) {
                return false;
            }
            if ($resetToken === null
                || ! hash_equals((string) $continuation['broker_token_verifier'], (string) $resetToken->token)
                || (int) $user->access_auth_revision !== (int) $continuation['auth_revision']) {
                return false;
            }

            $user->forceFill([
                'password' => Hash::make($password),
                'access_auth_revision' => (int) $user->access_auth_revision + 1,
            ])->save();
            Password::broker()->deleteToken($user);
            $delivery->forceFill([
                'password_recovered_at' => now(),
                'password_recovered_auth_revision' => (int) $user->access_auth_revision,
                'password_recovery_completion_nonce_hash' => hash('sha256', (string) $continuation['receiver_nonce']),
            ])->save();
            $account = Account::query()->whereKey($delivery->account_id)->firstOrFail();
            app(OfficeErpAccessDeliveries::class)->invalidateOtherRecoveryAuthorities(
                OfficeErpExecutionContext::system($account, 'password-recovery-complete:'.$delivery->getKey()),
                (int) $user->getKey(),
            );
            $audit = new OfficeErpAuditEvent;
            $audit->forceFill([
                'account_id' => (int) $delivery->account_id,
                'actor_kind' => 'recipient',
                'actor_identity' => 'recipient:user:'.(int) $user->getKey(),
                'actor_user_id' => (int) $user->getKey(),
                'action' => 'member.password_recovery.complete',
                'subject_type' => 'erp_company_membership',
                'subject_id' => (int) $delivery->membership_id,
                'metadata' => [
                    'delivery_id' => (int) $delivery->getKey(),
                    'company_id' => (int) $delivery->erp_company_id,
                    'membership_id' => (int) $delivery->membership_id,
                    'auth_revision' => (int) $user->access_auth_revision,
                ],
            ])->save();

            return true;
        });

        return ['state' => $completed ? 'completed' : 'invalid'];
    }

    private function isCurrent(Request $request): bool
    {
        $continuation = $this->verifiedPayload($request);
        if ($continuation === null) {
            return false;
        }
        if ($this->hasCompletedReceipt($continuation)) {
            return true;
        }

        $current = $this->withRecoveryStore(function () use ($continuation): bool {
            $candidate = OfficeErpAccessDelivery::query()->whereKey($continuation['delivery_id'])->first();
            if ($candidate === null || ! $this->assertDeliveryAuthority($candidate)) {
                return false;
            }
            $user = User::query()->find((int) $candidate->recipient_user_id);
            if ($user === null) {
                return false;
            }
            $resetToken = $this->resetTokenRow((string) $user->email, true);
            $delivery = OfficeErpAccessDelivery::query()->whereKey($continuation['delivery_id'])->lockForUpdate()->first();
            if (! $delivery || (int) $delivery->recipient_user_id !== (int) $user->getKey()) {
                return false;
            }
            if ($this->completedReceiptMatches($delivery, $user, $continuation)) {
                return true;
            }
            if (! $this->deliveryIsCurrent($delivery, $user)
                || ! $resetToken
                || (int) $user->access_auth_revision !== (int) $continuation['auth_revision']) {
                return false;
            }

            return $resetToken !== null
                && hash_equals((string) $continuation['broker_token_verifier'], (string) $resetToken->token);
        });

        if (! $current) {
            $this->clear($request);
        }

        return $current;
    }

    /** @return array<string, mixed>|null */
    private function verifiedPayload(Request $request): ?array
    {
        $value = $request->session()->get(self::SESSION_KEY);
        if (! is_array($value)
            || ! is_string($value['signature'] ?? null)
            || ! is_int($value['expires_at'] ?? null)
            || ! is_string($value['receiver_nonce'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $value['receiver_nonce']) !== 1) {
            return null;
        }
        $signature = $value['signature'];
        unset($value['signature']);
        if ($value['expires_at'] <= now()->timestamp || ! hash_equals($this->sign($value), $signature)) {
            return null;
        }

        return [...$value, 'signature' => $signature];
    }

    /** @return array<string, mixed>|null */
    private function preservableContinuation(Request $request): ?array
    {
        $continuation = $this->verifiedPayload($request);

        return $continuation !== null && $this->isCurrent($request) ? $continuation : null;
    }

    /** @return array<string, mixed>|null */
    private function completedContinuationForRevisionMismatch(Request $request, int $userId, int $capturedRevision): ?array
    {
        $continuation = $this->verifiedPayload($request);
        if ($continuation === null
            || (int) ($continuation['recipient_user_id'] ?? 0) !== $userId
            || (int) ($continuation['auth_revision'] ?? -1) !== $capturedRevision
            || ! $this->hasCompletedReceipt($continuation)) {
            return null;
        }

        return $continuation;
    }

    /** @param array<string, mixed>|null $continuation */
    private function restoreContinuation(Request $request, ?array $continuation): void
    {
        if ($continuation === null || ! $this->signatureIsValid($continuation)) {
            $this->clear($request);

            return;
        }

        $request->session()->put(self::SESSION_KEY, $continuation);
        if (! $this->isCurrent($request)) {
            $this->clear($request);
        }
    }

    /** @param array<string, mixed> $continuation */
    private function signatureIsValid(array $continuation): bool
    {
        if (! is_string($continuation['signature'] ?? null)
            || ! is_int($continuation['expires_at'] ?? null)
            || ! is_string($continuation['receiver_nonce'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/', $continuation['receiver_nonce']) !== 1) {
            return false;
        }

        $signature = $continuation['signature'];
        unset($continuation['signature']);

        return $continuation['expires_at'] > now()->timestamp
            && hash_equals($this->sign($continuation), $signature);
    }

    private function deliveryIsCurrent(OfficeErpAccessDelivery $delivery, User $user): bool
    {
        return $delivery->purpose === 'password_recovery'
            && $delivery->authority_invalidated_at === null
            && $delivery->access_auth_revision !== null
            && $delivery->membership_id !== null
            && $delivery->expires_at !== null
            && $delivery->expires_at->isFuture()
            && (int) $delivery->recipient_user_id === (int) $user->getKey()
            && (int) $delivery->access_auth_revision === (int) $user->access_auth_revision;
    }

    /** @param array<string, mixed> $continuation */
    private function hasCompletedReceipt(array $continuation): bool
    {
        return $this->withRecoveryStore(function () use ($continuation): bool {
            $delivery = OfficeErpAccessDelivery::query()->whereKey($continuation['delivery_id'])->first();
            $user = User::query()->find((int) $continuation['recipient_user_id']);

            return $delivery !== null && $user !== null && $this->completedReceiptMatches($delivery, $user, $continuation);
        });
    }

    /** @param array<string, mixed> $continuation */
    private function completedReceiptMatches(OfficeErpAccessDelivery $delivery, User $user, array $continuation): bool
    {
        return $delivery->purpose === 'password_recovery'
            && $delivery->password_recovered_at !== null
            && (int) $delivery->recipient_user_id === (int) $continuation['recipient_user_id']
            && (int) $delivery->membership_id === (int) $continuation['membership_id']
            && (int) $delivery->erp_company_id === (int) $continuation['company_id']
            && (int) $user->getKey() === (int) $continuation['recipient_user_id']
            && (int) $user->access_auth_revision === (int) ($delivery->password_recovered_auth_revision ?? -1)
            && (int) $user->access_auth_revision === (int) $continuation['auth_revision'] + 1
            && is_string($continuation['receiver_nonce'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $continuation['receiver_nonce']) === 1
            && is_string($delivery->password_recovery_completion_nonce_hash)
            && hash_equals(
                $delivery->password_recovery_completion_nonce_hash,
                hash('sha256', $continuation['receiver_nonce']),
            );
    }

    private function assertDeliveryAuthority(OfficeErpAccessDelivery $delivery): bool
    {
        try {
            $this->authority->assertDelivery($delivery, true);

            return true;
        } catch (ModelNotFoundException) {
            return false;
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() < 500) {
                return false;
            }

            throw $exception;
        }
    }

    private function resetTokenRow(string $email, bool $lock): ?object
    {
        $broker = (string) config('auth.defaults.passwords', 'users');
        $query = DB::table((string) config("auth.passwords.{$broker}.table"))
            ->where('email', $email);
        if ($lock) {
            $query->lockForUpdate();
        }

        $row = $query->first();
        $expiryMinutes = min(60, max(0, (int) config("auth.passwords.{$broker}.expire", 60)));
        if ($row === null || CarbonImmutable::parse($row->created_at)->addMinutes($expiryMinutes)->lessThanOrEqualTo(CarbonImmutable::now())) {
            return null;
        }

        return $row;
    }

    private function clear(Request $request): void
    {
        $request->session()->forget(self::SESSION_KEY);
    }

    /** @param array<string, mixed> $continuation */
    private function hasIdentityMismatch(Request $request, array $continuation): bool
    {
        $user = $request->user();

        return $user !== null && (int) $user->getKey() !== (int) ($continuation['recipient_user_id'] ?? 0);
    }

    private function withRecoveryStore(Closure $operation): mixed
    {
        try {
            return DB::transaction($operation, 3);
        } catch (QueryException) {
            abort(503, 'recovery_store_unavailable');
        }
    }

    /** @param array<string, mixed> $payload */
    private function sign(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
    }
}
