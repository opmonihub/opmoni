<?php

namespace App\Erp\Commercial;

use App\Jobs\DeliverOfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\Commercial\OfficeErpMemberInvitation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class OfficeErpAccessDeliveries
{
    /**
     * @param  array<string, mixed>  $authority
     * @param  array{token: string}  $secret
     * @return array{id: int, state: string}
     */
    public function prepare(
        OfficeErpExecutionContext $ctx,
        string $operationKey,
        string $purpose,
        array $authority,
        array $secret,
        CarbonImmutable $expiresAt,
    ): array {
        $this->assertInTransaction();
        $ctx->assertVerified();
        $this->validateInputs($ctx, $operationKey, $purpose, $authority, $secret, $expiresAt);

        $operation = $this->operationQuery($ctx, $operationKey)->lockForUpdate()->firstOrFail();
        $registration = OfficeErpRecords::query($ctx, OfficeErpCompanyRegistration::class)
            ->whereKey((int) $authority['registration_id'])
            ->where('erp_company_id', (int) $authority['company_id'])
            ->lockForUpdate()
            ->firstOrFail();

        if ($purpose === 'member_invitation') {
            $invitation = OfficeErpRecords::query($ctx, OfficeErpMemberInvitation::class)
                ->whereKey((int) $authority['member_invitation_id'])
                ->where('erp_company_id', $registration->erp_company_id)
                ->where('registration_id', $registration->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_unless($invitation->status === 'pending'
                && $invitation->expires_at->isFuture()
                && $expiresAt->lessThanOrEqualTo($invitation->expires_at)
                && $invitation->email === mb_strtolower(trim((string) $authority['recipient_email'])), 409);
        } else {
            $membership = DB::table('erp_company_memberships')
                ->where('id', (int) $authority['membership_id'])
                ->where('erp_company_id', $registration->erp_company_id)
                ->where('user_id', (int) $authority['recipient_user_id'])
                ->exists();
            $recipient = User::query()->whereKey((int) $authority['recipient_user_id'])->firstOrFail();
            abort_unless($membership
                && mb_strtolower(trim((string) $recipient->email)) === mb_strtolower(trim((string) $authority['recipient_email']))
                && (int) $recipient->access_auth_revision === (int) $authority['access_auth_revision'], 409);
        }

        $existing = OfficeErpRecords::query($ctx, OfficeErpAccessDelivery::class)
            ->where('operation_id', $operation->getKey())
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            $this->assertSameAuthority($existing, $ctx, $purpose, $authority, $expiresAt);

            return ['id' => (int) $existing->getKey(), 'state' => (string) $existing->state];
        }

        $recipientEmail = mb_strtolower(trim((string) $authority['recipient_email']));
        $recipientUserId = isset($authority['recipient_user_id']) ? (int) $authority['recipient_user_id'] : null;
        if ($purpose === 'password_recovery') {
            abort_unless($recipientUserId !== null, 422);
            $this->invalidateOtherRecoveryAuthorities($ctx, $recipientUserId);
        }

        $delivery = new OfficeErpAccessDelivery;
        $delivery->forceFill([
            'account_id' => $ctx->accountId,
            'erp_company_id' => (int) $authority['company_id'],
            'registration_id' => (int) $authority['registration_id'],
            'operation_id' => (int) $operation->getKey(),
            'purpose' => $purpose,
            'member_invitation_id' => $authority['member_invitation_id'] ?? null,
            'membership_id' => $authority['membership_id'] ?? null,
            'recipient_user_id' => $recipientUserId,
            'recipient_email' => $recipientEmail,
            'access_auth_revision' => isset($authority['access_auth_revision']) ? (int) $authority['access_auth_revision'] : null,
            'state' => 'prepared',
            'payload' => ['token' => $secret['token']],
            'expires_at' => $expiresAt,
        ]);
        /** @var OfficeErpAccessDelivery $delivery */
        $delivery = OfficeErpRecords::persist($ctx, $delivery);
        $deliveryId = (int) $delivery->getKey();

        DB::afterCommit(static function () use ($deliveryId): void {
            app(OfficeErpAccessDeliveries::class)->dispatchPrepared($deliveryId);
        });

        return ['id' => $deliveryId, 'state' => 'prepared'];
    }

    public function deliver(int $deliveryId): void
    {
        $claimed = DB::transaction(function () use ($deliveryId): ?OfficeErpAccessDelivery {
            $delivery = OfficeErpAccessDelivery::query()
                ->whereKey($deliveryId)
                ->where('state', 'prepared')
                ->lockForUpdate()
                ->first();
            if ($delivery === null) {
                return null;
            }

            $now = CarbonImmutable::now();
            if ($delivery->expires_at->lessThanOrEqualTo($now)) {
                $delivery->forceFill(['state' => 'expired', 'payload' => null, 'completed_at' => $now]);
                $delivery->save();

                return null;
            }

            $delivery->forceFill(['state' => 'sending', 'claimed_at' => $now, 'attempted_at' => $now]);
            $delivery->save();

            return $delivery->fresh();
        }, 3);

        if ($claimed === null) {
            return;
        }

        DB::transaction(function () use ($claimed): void {
            $delivery = OfficeErpAccessDelivery::query()->whereKey($claimed->getKey())->first();
            if ($delivery === null || $delivery->state !== 'sending') {
                return;
            }

            $now = CarbonImmutable::now();
            if ($delivery->expires_at->lessThanOrEqualTo($now)) {
                $delivery->forceFill(['state' => 'expired', 'payload' => null, 'completed_at' => $now])->save();

                return;
            }
            if ($delivery->authority_invalidated_at !== null) {
                $delivery->forceFill(['state' => 'failed', 'payload' => null, 'completed_at' => $now])->save();

                return;
            }

            try {
                app(OfficeErpMemberAuthority::class)->assertDelivery($delivery, true);
            } catch (Throwable $exception) {
                if ($exception instanceof ModelNotFoundException
                    || $exception instanceof HttpExceptionInterface && in_array($exception->getStatusCode(), [403, 404, 410], true)) {
                    OfficeErpAccessDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail()->forceFill([
                        'state' => 'failed',
                        'authority_invalidated_at' => $now,
                        'payload' => null,
                        'completed_at' => $now,
                    ])->save();

                    return;
                }

                OfficeErpAccessDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail()
                    ->forceFill(['state' => 'failed', 'completed_at' => null])->save();

                return;
            }

            $delivery = OfficeErpAccessDelivery::query()->whereKey($delivery->getKey())->lockForUpdate()->first();
            if ($delivery === null || $delivery->state !== 'sending' || $delivery->authority_invalidated_at !== null) {
                return;
            }

            $payload = $delivery->payload;
            if (! is_array($payload) || ! is_string($payload['token'] ?? null)) {
                $delivery->forceFill(['state' => 'failed', 'payload' => null, 'completed_at' => $now])->save();

                return;
            }

            $transport = app()->bound(OfficeErpAccessDeliveryTransport::class)
                    ? app(OfficeErpAccessDeliveryTransport::class)
                    : app(LaravelOfficeErpAccessDeliveryTransport::class);
            try {
                $url = $this->fragmentUrl($delivery, $payload['token']);
                $transport->assertAvailable();
            } catch (Throwable) {
                $delivery->forceFill(['state' => 'failed', 'completed_at' => null])->save();

                return;
            }

            try {
                $transport->send(
                    (string) $delivery->recipient_email,
                    $delivery->purpose === 'member_invitation' ? 'Convite para acessar uma empresa no Opmoni' : 'Recuperação de acesso à conta Opmoni',
                    $url,
                );
            } catch (OfficeErpAccessDeliveryTransportRejected) {
                $delivery->forceFill(['state' => 'failed', 'completed_at' => null])->save();

                return;
            } catch (Throwable) {
                $delivery->forceFill([
                    'state' => 'unknown',
                    'payload' => null,
                    'completed_at' => CarbonImmutable::now(),
                ])->save();

                return;
            }

            $delivery->forceFill([
                'state' => 'transport_accepted',
                'payload' => null,
                'completed_at' => CarbonImmutable::now(),
            ])->save();
        });
    }

    public function retryDefiniteFailure(int $deliveryId): void
    {
        DB::transaction(function () use ($deliveryId): void {
            $delivery = OfficeErpAccessDelivery::query()->whereKey($deliveryId)->lockForUpdate()->firstOrFail();
            abort_unless($delivery->state === 'failed'
                && is_array($delivery->payload)
                && $delivery->expires_at->isFuture()
                && $delivery->authority_invalidated_at === null, 409);
            $delivery->forceFill([
                'state' => 'prepared',
                'claimed_at' => null,
                'attempted_at' => null,
                'completed_at' => null,
            ])->save();
            DB::afterCommit(static function () use ($deliveryId): void {
                app(OfficeErpAccessDeliveries::class)->dispatchPrepared($deliveryId);
            });
        }, 3);
    }

    public function dispatchPrepared(int $deliveryId): void
    {
        try {
            DeliverOfficeErpAccessDelivery::dispatch($deliveryId);
        } catch (Throwable) {
            OfficeErpAccessDelivery::query()
                ->whereKey($deliveryId)
                ->where('state', 'prepared')
                ->update(['state' => 'failed', 'updated_at' => CarbonImmutable::now()]);
        }
    }

    public function markWorkerFailureUnknown(int $deliveryId): void
    {
        OfficeErpAccessDelivery::query()
            ->whereKey($deliveryId)
            ->where('state', 'sending')
            ->update([
                'state' => 'unknown',
                'payload' => null,
                'completed_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    public function expireDue(int $batch = 100): int
    {
        abort_unless($batch >= 1 && $batch <= 100, 422);
        $now = CarbonImmutable::now();

        return DB::transaction(function () use ($batch, $now): int {
            $deliveries = OfficeErpAccessDelivery::query()
                ->where('expires_at', '<=', $now)
                ->whereNull('authority_invalidated_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit($batch)
                ->get();

            foreach ($deliveries as $delivery) {
                $changes = [
                    'authority_invalidated_at' => $now,
                    'payload' => null,
                ];

                if (in_array($delivery->state, ['prepared', 'failed'], true)) {
                    $changes['state'] = 'expired';
                    $changes['completed_at'] = $now;
                } elseif ($delivery->state === 'sending') {
                    $changes['state'] = 'unknown';
                    $changes['completed_at'] = $now;
                }

                $delivery->forceFill($changes)->save();
            }

            return $deliveries->count();
        }, 3);
    }

    public function invalidateRecoveryForMembership(OfficeErpExecutionContext $ctx, int $membershipId): void
    {
        $this->assertInTransaction();
        OfficeErpRecords::query($ctx, OfficeErpAccessDelivery::class)
            ->where('membership_id', $membershipId)
            ->where('purpose', 'password_recovery')
            ->whereNull('authority_invalidated_at')
            ->update(['authority_invalidated_at' => CarbonImmutable::now(), 'payload' => null, 'updated_at' => CarbonImmutable::now()]);
    }

    public function invalidateMemberInvitation(OfficeErpExecutionContext $ctx, int $invitationId): void
    {
        $this->assertInTransaction();
        OfficeErpRecords::query($ctx, OfficeErpAccessDelivery::class)
            ->where('member_invitation_id', $invitationId)
            ->where('purpose', 'member_invitation')
            ->whereNull('authority_invalidated_at')
            ->update(['authority_invalidated_at' => CarbonImmutable::now(), 'payload' => null, 'updated_at' => CarbonImmutable::now()]);
    }

    public function invalidateOtherRecoveryAuthorities(OfficeErpExecutionContext $ctx, int $recipientUserId, ?int $exceptDeliveryId = null): void
    {
        $this->assertInTransaction();
        $ctx->assertVerified();
        abort_unless($recipientUserId > 0 && User::query()->whereKey($recipientUserId)->exists(), 404);
        $query = OfficeErpAccessDelivery::query()
            ->where('recipient_user_id', $recipientUserId)
            ->where('purpose', 'password_recovery')
            ->whereNull('authority_invalidated_at');

        if ($exceptDeliveryId !== null) {
            $query->where('id', '<>', $exceptDeliveryId);
        }

        $query->update(['authority_invalidated_at' => CarbonImmutable::now(), 'payload' => null, 'updated_at' => CarbonImmutable::now()]);
    }

    private function assertInTransaction(): void
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'access_delivery_requires_transaction');
    }

    /** @param array<string, mixed> $authority @param array<string, mixed> $secret */
    private function validateInputs(OfficeErpExecutionContext $ctx, string $operationKey, string $purpose, array $authority, array $secret, CarbonImmutable $expiresAt): void
    {
        abort_unless(in_array($purpose, ['member_invitation', 'password_recovery'], true), 422);
        abort_unless(array_keys($secret) === ['token']
            && is_string($secret['token'])
            && preg_match('/^[a-f0-9]{64}$/', $secret['token']) === 1, 422);
        $now = CarbonImmutable::now();
        abort_unless($expiresAt->isFuture()
            && $expiresAt->lessThanOrEqualTo($purpose === 'member_invitation' ? $now->addDays(7) : $now->addMinutes(60)), 422);

        $required = ['registration_id', 'company_id', 'recipient_email'];
        $required = $purpose === 'member_invitation'
            ? [...$required, 'member_invitation_id']
            : [...$required, 'membership_id', 'recipient_user_id', 'access_auth_revision'];
        foreach ($required as $key) {
            abort_unless(isset($authority[$key]), 422);
        }

        if ($purpose === 'member_invitation') {
            abort_unless(! isset($authority['membership_id']) && ! isset($authority['recipient_user_id']) && ! isset($authority['access_auth_revision']), 422);
        } else {
            abort_unless(! isset($authority['member_invitation_id']), 422);
            abort_unless((int) $authority['recipient_user_id'] > 0 && (int) $authority['membership_id'] > 0 && (int) $authority['access_auth_revision'] >= 0, 422);
        }

        $email = mb_strtolower(trim((string) ($authority['recipient_email'] ?? '')));
        Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();
        Validator::make(['key' => $operationKey], ['key' => ['required', 'string', 'max:191', 'not_regex:/[\x00-\x1F\x7F]/']])->validate();

        abort_unless((int) $authority['registration_id'] > 0 && (int) $authority['company_id'] > 0, 422);
        if (isset($authority['member_invitation_id'])) {
            abort_unless((int) $authority['member_invitation_id'] > 0, 422);
        }
        abort_unless(isset($ctx->accountId), 403);
    }

    /** @param array<string, mixed> $authority */
    private function assertSameAuthority(OfficeErpAccessDelivery $delivery, OfficeErpExecutionContext $ctx, string $purpose, array $authority, CarbonImmutable $expiresAt): void
    {
        $matches = (int) $delivery->account_id === $ctx->accountId
            && $delivery->purpose === $purpose
            && (int) $delivery->registration_id === (int) $authority['registration_id']
            && (int) $delivery->erp_company_id === (int) $authority['company_id']
            && (string) $delivery->recipient_email === mb_strtolower(trim((string) $authority['recipient_email']))
            && ($delivery->member_invitation_id === null ? null : (int) $delivery->member_invitation_id) === ($authority['member_invitation_id'] ?? null)
            && ($delivery->membership_id === null ? null : (int) $delivery->membership_id) === ($authority['membership_id'] ?? null)
            && ($delivery->recipient_user_id === null ? null : (int) $delivery->recipient_user_id) === ($authority['recipient_user_id'] ?? null)
            && ($delivery->access_auth_revision === null ? null : (int) $delivery->access_auth_revision) === ($authority['access_auth_revision'] ?? null)
            && $delivery->expires_at->equalTo($expiresAt);
        abort_unless($matches, 409);
    }

    /** @return Builder<OfficeErpCommercialOperation> */
    private function operationQuery(OfficeErpExecutionContext $ctx, string $key): Builder
    {
        return OfficeErpRecords::query($ctx, OfficeErpCommercialOperation::class)
            ->where('actor_kind', $ctx->actorKind)
            ->where('actor_identity', $ctx->actorIdentity)
            ->where('idempotency_key', $key);
    }

    private function fragmentUrl(OfficeErpAccessDelivery $delivery, string $token): string
    {
        $configuredOrigin = trim((string) config('access.origin'));
        $parts = parse_url($configuredOrigin);
        $path = $parts['path'] ?? '';
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || ! isset($parts['host'])
            || ! in_array($path, ['', '/'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw new OfficeErpAccessDeliveryTransportRejected('The access delivery frontend is unavailable.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
            throw new OfficeErpAccessDeliveryTransportRejected('The access delivery payload is unavailable.');
        }

        $origin = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        if ($delivery->purpose === 'member_invitation') {
            return $origin.'/erp/convite#token='.$token;
        }

        return $origin.'/redefinir-senha#token='.$token.'&delivery='.$delivery->getKey();
    }
}
