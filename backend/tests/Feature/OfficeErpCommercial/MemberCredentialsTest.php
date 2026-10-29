<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpMemberCredentials;
use App\Erp\ErpCommercialCatalog;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpAccessDelivery;
use App\Models\Erp\Commercial\OfficeErpCompanyRegistration;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\OfficeErpCommercialTestCase;

class MemberCredentialsTest extends OfficeErpCommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertNotNull(app('router')->getRoutes()->getByName('office-erp-commercial.members.password-reset'));
        self::assertNotNull(app('router')->getRoutes()->getByName('office-erp-password-recovery.resolve'));
        self::assertTrue(Schema::hasColumn('office_erp_access_deliveries', 'password_recovery_completion_nonce_hash'));
    }

    public function test_direct_reset_is_revision_bound_secret_free_and_same_key_replay_does_not_apply_new_password(): void
    {
        $ctx = $this->officeContext();
        [$membership, $registration] = $this->companyMember($ctx);
        $request = Request::create('/api/v1/erp-commercial/companies', 'POST', server: ['REMOTE_ADDR' => '192.0.2.50']);
        $credentials = app(OfficeErpMemberCredentials::class);

        $first = $this->withOfficeAccount($ctx, fn (): array => $credentials->reset(
            $request, $ctx, (int) $registration->erp_company_id, (int) $membership->id, 0, 'first-password', 'reset-member-first',
        ));
        self::assertTrue(Hash::check('first-password', $membership->user->fresh()->password));
        self::assertSame(1, $membership->user->fresh()->access_auth_revision);

        $replay = $this->withOfficeAccount($ctx, fn (): array => $credentials->reset(
            $request, $ctx, (int) $registration->erp_company_id, (int) $membership->id, 0, 'ignored-password', 'reset-member-first',
        ));
        self::assertSame($first, $replay);
        self::assertTrue(Hash::check('first-password', $membership->user->fresh()->password));
        self::assertFalse(Hash::check('ignored-password', $membership->user->fresh()->password));

        try {
            $this->withOfficeAccount($ctx, fn (): array => $credentials->reset(
                $request, $ctx, (int) $registration->erp_company_id, (int) $membership->id, 0, 'stale-password', 'reset-member-stale',
            ));
            self::fail('A second operation key with a stale authentication revision must fail.');
        } catch (HttpExceptionInterface $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }

        $operation = DB::table('office_erp_commercial_operations')->where('idempotency_key', 'reset-member-first')->first();
        $audit = DB::table('office_erp_audit_events')->where('operation_id', $operation->id)->first();
        $persisted = json_encode([$operation, $audit], JSON_THROW_ON_ERROR);
        foreach (['first-password', 'ignored-password', 'stale-password'] as $secret) {
            self::assertStringNotContainsString($secret, $persisted);
        }
        self::assertStringNotContainsString($membership->user->fresh()->password, $persisted);
        self::assertSame(1, $membership->user->fresh()->access_auth_revision);
        self::assertSame((int) $ctx->actor->id, (int) $registration->created_by_user_id);
    }

    public function test_registered_member_show_exposes_scoped_direct_reset_and_recovery_capabilities(): void
    {
        $ctx = $this->officeContext();
        [$ordinary, $registration] = $this->companyMember($ctx);
        $this->actingAs($ctx->actor, 'web')
            ->getJson('/api/v1/erp-commercial/companies/'.$registration->erp_company_id.'/members/'.$ordinary->id)
            ->assertOk()
            ->assertJsonPath('data.credential_capabilities.can_direct_reset', true)
            ->assertJsonPath('data.credential_capabilities.can_email_recovery', true)
            ->assertJsonPath('data.credential_capabilities.direct_reset_unavailable_reason', null);

        $protectedUsers = [
            $ctx->actor,
            User::factory()->create(['is_super_admin' => true]),
        ];
        $accountAdmin = User::factory()->create();
        AccountMembership::factory()->create([
            'account_id' => $ctx->accountId,
            'user_id' => $accountAdmin->id,
            'role' => 'admin',
        ]);
        $protectedUsers[] = $accountAdmin;

        foreach ($protectedUsers as $user) {
            $membership = ErpCompanyMembership::factory()->role(ErpCompanyRole::Operator)->create([
                'erp_company_id' => $registration->erp_company_id,
                'user_id' => $user->id,
            ]);
            $this->actingAs($ctx->actor, 'web')
                ->getJson('/api/v1/erp-commercial/companies/'.$registration->erp_company_id.'/members/'.$membership->id)
                ->assertOk()
                ->assertJsonPath('data.credential_capabilities.can_direct_reset', false)
                ->assertJsonPath('data.credential_capabilities.can_email_recovery', true)
                ->assertJsonPath('data.credential_capabilities.direct_reset_unavailable_reason', 'protected_identity');
        }
    }

    public function test_registered_member_show_does_not_disclose_foreign_company_member_capabilities(): void
    {
        $ctx = $this->officeContext();
        $foreignCtx = $this->officeContext();
        [$foreignMembership, $foreignRegistration] = $this->companyMember($foreignCtx);

        $this->actingAs($ctx->actor, 'web')
            ->getJson('/api/v1/erp-commercial/companies/'.$foreignRegistration->erp_company_id.'/members/'.$foreignMembership->id)
            ->assertNotFound()
            ->assertJsonMissingPath('data.credential_capabilities');
    }

    public function test_direct_reset_denies_self_super_admin_and_any_account_admin_targets(): void
    {
        $ctx = $this->officeContext();
        $credentials = app(OfficeErpMemberCredentials::class);
        $request = Request::create('/api/v1/erp-commercial/companies', 'POST', server: ['REMOTE_ADDR' => '192.0.2.51']);
        [$company, $registration] = $this->company($ctx);
        $targets = [
            ErpCompanyMembership::factory()->role(ErpCompanyRole::Operator)->create(['erp_company_id' => $company->id, 'user_id' => $ctx->actor->id]),
            ErpCompanyMembership::factory()->role(ErpCompanyRole::Operator)->create(['erp_company_id' => $company->id, 'user_id' => User::factory()->create(['is_super_admin' => true])->id]),
        ];
        $admin = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $ctx->accountId, 'user_id' => $admin->id, 'role' => 'admin']);
        $targets[] = ErpCompanyMembership::factory()->role(ErpCompanyRole::Operator)->create(['erp_company_id' => $company->id, 'user_id' => $admin->id]);

        foreach ($targets as $index => $target) {
            try {
                $this->withOfficeAccount($ctx, fn () => $credentials->reset(
                    $request, $ctx, (int) $company->id, (int) $target->id, 0, 'denied-password-'.$index, 'reset-denied-'.$index,
                ));
                self::fail('Protected credential targets must not be reset directly.');
            } catch (HttpExceptionInterface $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
            self::assertSame(0, $target->user->fresh()->access_auth_revision);
        }
        self::assertSame((int) $company->id, (int) $registration->erp_company_id);
    }

    public function test_recovery_issue_uses_server_recipient_encrypted_delivery_and_same_key_replay(): void
    {
        Queue::fake();
        $ctx = $this->officeContext();
        [$membership, $registration] = $this->companyMember($ctx);
        $request = Request::create('/api/v1/erp-commercial/companies', 'POST', server: ['REMOTE_ADDR' => '192.0.2.52']);
        $credentials = app(OfficeErpMemberCredentials::class);

        $first = $this->withOfficeAccount($ctx, fn (): array => $credentials->issueRecovery(
            $request, $ctx, (int) $registration->erp_company_id, (int) $membership->id, 'recovery-member-first',
        ));
        $replay = $this->withOfficeAccount($ctx, fn (): array => $credentials->issueRecovery(
            $request, $ctx, (int) $registration->erp_company_id, (int) $membership->id, 'recovery-member-first',
        ));
        self::assertSame($first, $replay);

        $delivery = OfficeErpAccessDelivery::query()->findOrFail($first['data']['delivery_id']);
        self::assertSame('password_recovery', $delivery->purpose);
        self::assertSame($membership->user_id, $delivery->recipient_user_id);
        self::assertSame($membership->user->email, $delivery->recipient_email);
        self::assertSame(64, strlen($delivery->payload['token']));
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $delivery->payload['token']);
        self::assertNull($delivery->password_recovery_completion_nonce_hash);
        self::assertStringNotContainsString($delivery->payload['token'], (string) DB::table('office_erp_commercial_operations')->where('idempotency_key', 'recovery-member-first')->value('outcome'));
        self::assertArrayNotHasKey('recipient_email', $first['data']);
        self::assertArrayNotHasKey('token', $first['data']);
        self::assertTrue(Password::broker()->tokenExists($membership->user, $delivery->payload['token']));

        try {
            $this->withOfficeAccount($ctx, fn () => $credentials->issueRecovery(
                $request, $ctx, (int) $registration->erp_company_id, (int) $membership->id, 'recovery-member-second',
            ));
            self::fail('A second recovery issuance for the normalized recipient must be rate limited.');
        } catch (HttpResponseException $exception) {
            self::assertSame(429, $exception->getResponse()->getStatusCode());
        }

        self::assertSame(1, OfficeErpAccessDelivery::query()->where('purpose', 'password_recovery')->count());
    }

    public function test_registered_public_receiver_exchanges_bearer_and_never_completes_for_another_logged_in_identity(): void
    {
        Queue::fake();
        $ctx = $this->officeContext();
        [$membership, $registration] = $this->companyMember($ctx);
        $this->actingAs($ctx->actor, 'web')->withHeader('Idempotency-Key', 'route-recovery-key')
            ->postJson('/api/v1/erp-commercial/companies/'.$registration->erp_company_id.'/members/'.$membership->id.'/recovery', [])
            ->assertAccepted()->assertJsonMissingPath('data.recipient_email')->assertJsonMissingPath('data.token');
        $delivery = OfficeErpAccessDelivery::query()->where('purpose', 'password_recovery')->sole();
        $token = $delivery->payload['token'];

        $csrf = $this->app['session.store']->token();
        $this->withHeader('X-CSRF-Token', 'invalid-csrf')
            ->postJson('/api/v1/password-recovery/resolve', ['token' => $token, 'delivery_id' => $delivery->id])
            ->assertForbidden()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->withHeader('X-CSRF-Token', $csrf);
        $this->postJson('/api/v1/password-recovery/resolve', ['token' => $token, 'delivery_id' => $delivery->id])
            ->assertOk()->assertExactJson(['state' => 'identity_mismatch']);
        $firstReceiverContinuation = $this->app['session.store']->get('office_erp.password_recovery_continuation');
        self::assertIsArray($firstReceiverContinuation);
        self::assertArrayHasKey('receiver_nonce', $firstReceiverContinuation);
        $legacyContinuation = $firstReceiverContinuation;
        unset($legacyContinuation['receiver_nonce']);
        unset($legacyContinuation['signature']);
        $legacyContinuation['signature'] = hash_hmac(
            'sha256',
            json_encode($legacyContinuation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            (string) config('app.key'),
        );
        $this->app['session.store']->put('office_erp.password_recovery_continuation', $legacyContinuation);
        $this->getJson('/api/v1/password-recovery/current')->assertOk()->assertExactJson(['state' => 'invalid']);
        self::assertSame(0, $membership->user->fresh()->access_auth_revision);
        $this->app['session.store']->put('office_erp.password_recovery_continuation', $firstReceiverContinuation);
        self::assertSame(0, $membership->user->fresh()->access_auth_revision);

        $this->postJson('/api/v1/logout', [])->assertNoContent();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf)->postJson('/api/v1/login', [
            'email' => $membership->user->email,
            'password' => 'password',
        ])->assertOk();
        $this->getJson('/api/v1/password-recovery/current')->assertOk()->assertExactJson(['state' => 'valid']);

        // Create independent anonymous receiver continuations through the registered resolve route.
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/logout', [])->assertNoContent();
        $csrf = $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf);
        $this->postJson('/api/v1/password-recovery/resolve', ['token' => $token, 'delivery_id' => $delivery->id])
            ->assertOk()->assertExactJson(['state' => 'valid']);
        $firstAnonymousContinuation = $this->app['session.store']->get('office_erp.password_recovery_continuation');
        self::assertIsArray($firstAnonymousContinuation);
        self::assertFalse(Auth::guard('web')->check());
        $this->postJson('/api/v1/password-recovery/resolve', ['token' => $token, 'delivery_id' => $delivery->id])
            ->assertOk()->assertExactJson(['state' => 'valid']);
        $secondReceiverContinuation = $this->app['session.store']->get('office_erp.password_recovery_continuation');
        self::assertIsArray($secondReceiverContinuation);
        self::assertNotSame($firstAnonymousContinuation['receiver_nonce'], $secondReceiverContinuation['receiver_nonce']);
        self::assertFalse(Auth::guard('web')->check());

        $this->postJson('/api/v1/password-recovery/complete', [
            'password' => 'recipient-new-password', 'password_confirmation' => 'recipient-new-password',
        ])->assertOk()->assertExactJson(['state' => 'completed']);
        $this->getJson('/api/v1/password-recovery/current')->assertOk()->assertExactJson(['state' => 'completed']);
        self::assertFalse(Auth::guard('web')->check());
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $replay = $this->postJson('/api/v1/password-recovery/complete', [
            'password' => 'different-replay-password', 'password_confirmation' => 'different-replay-password',
        ]);
        self::assertSame(200, $replay->status(), 'Completion receipt replay failed: '.$replay->getContent());
        $replay->assertExactJson(['state' => 'completed']);
        // A second array-session snapshot represents the first anonymous receiver after the winner commits.
        $this->app['session.store']->put('office_erp.password_recovery_continuation', $firstAnonymousContinuation);
        $this->getJson('/api/v1/password-recovery/current')->assertOk()->assertExactJson(['state' => 'invalid']);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/password-recovery/complete', [
            'password' => 'independent-loser-password', 'password_confirmation' => 'independent-loser-password',
        ])->assertGone()->assertExactJson(['state' => 'invalid']);
        self::assertTrue(Hash::check('recipient-new-password', $membership->user->fresh()->password));
        self::assertFalse(Hash::check('different-replay-password', $membership->user->fresh()->password));
        self::assertSame(1, $membership->user->fresh()->access_auth_revision);
        self::assertFalse(Auth::guard('web')->check());
        self::assertNotNull($delivery->fresh()->password_recovered_at);
        self::assertSame(1, $delivery->fresh()->password_recovered_auth_revision);
        self::assertSame(hash('sha256', $secondReceiverContinuation['receiver_nonce']), $delivery->fresh()->password_recovery_completion_nonce_hash);
        self::assertArrayNotHasKey('password_recovery_completion_nonce_hash', $delivery->fresh()->toArray());
        self::assertSame(1, DB::table('office_erp_audit_events')->where('action', 'member.password_recovery.complete')->count());
        $audit = DB::table('office_erp_audit_events')->where('action', 'member.password_recovery.complete')->sole();
        self::assertSame('recipient', $audit->actor_kind);
        self::assertSame((string) $membership->user->id, (string) $audit->actor_user_id);
        self::assertSame((string) $membership->id, (string) $audit->subject_id);
        $auditJson = json_encode($audit, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($token, $auditJson);
        self::assertStringNotContainsString('recipient-new-password', $auditJson);
    }

    public function test_authenticated_recipient_recovers_completion_receipt_after_revision_mismatch_logs_out(): void
    {
        Queue::fake();
        $ctx = $this->officeContext();
        [$membership, $registration] = $this->companyMember($ctx);
        $this->actingAs($ctx->actor, 'web')->withHeader('Idempotency-Key', 'authenticated-recovery-receipt')
            ->postJson('/api/v1/erp-commercial/companies/'.$registration->erp_company_id.'/members/'.$membership->id.'/recovery', [])
            ->assertAccepted();
        $delivery = OfficeErpAccessDelivery::query()->where('purpose', 'password_recovery')->sole();
        $token = $delivery->payload['token'];

        $this->actingAs($membership->user, 'web');
        $csrf = $this->app['session.store']->token();
        $this->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/password-recovery/resolve', ['token' => $token, 'delivery_id' => $delivery->id])
            ->assertOk()->assertExactJson(['state' => 'valid']);
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/password-recovery/complete', [
            'password' => 'authenticated-recovery-password',
            'password_confirmation' => 'authenticated-recovery-password',
        ])->assertOk()->assertExactJson(['state' => 'completed']);

        // The auth revision change logs out the recipient but keeps the winning receiver receipt.
        $this->getJson('/api/v1/password-recovery/current')->assertOk()->assertExactJson(['state' => 'completed']);
        self::assertFalse(Auth::guard('web')->check());
        $this->withHeader('X-CSRF-Token', $this->getJson('/api/v1/csrf')->assertOk()->json('csrf_token'));
        $this->postJson('/api/v1/password-recovery/complete', [
            'password' => 'ignored-replay-password',
            'password_confirmation' => 'ignored-replay-password',
        ])->assertOk()->assertExactJson(['state' => 'completed']);
        self::assertTrue(Hash::check('authenticated-recovery-password', $membership->user->fresh()->password));
        self::assertFalse(Hash::check('ignored-replay-password', $membership->user->fresh()->password));
        self::assertSame(1, $membership->user->fresh()->access_auth_revision);
        self::assertSame(1, DB::table('office_erp_audit_events')
            ->where('action', 'member.password_recovery.complete')
            ->where('subject_id', $membership->id)
            ->count());
    }

    public function test_password_persistence_failure_is_redacted_and_rolls_back_the_operation(): void
    {
        $ctx = $this->officeContext();
        [$membership, $registration] = $this->companyMember($ctx);
        $initialHash = $membership->user->password;
        $secret = 'failure-path-password';
        $requestData = null;
        $injected = false;
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        DB::listen(function (QueryExecuted $event) use (&$requestData, &$injected, $secret): void {
            if (! $injected && str_starts_with(strtolower($event->sql), 'update "users"') && str_contains($event->sql, '"password"')) {
                $requestData = request()->all();
                $injected = true;
                throw new QueryException('sqlite', $event->sql, $event->bindings, new \PDOException($secret));
            }
        });

        $this->actingAs($ctx->actor, 'web')->withHeader('Idempotency-Key', 'credential-store-failure')
            ->postJson('/api/v1/erp-commercial/companies/'.$registration->erp_company_id.'/members/'.$membership->id.'/password-reset', [
                'expected_auth_revision' => 0,
                'password' => $secret,
                'password_confirmation' => $secret,
            ])->assertServiceUnavailable()->assertJsonMissing(['message' => $secret])->assertHeader('Referrer-Policy', 'no-referrer');

        self::assertTrue($injected);
        self::assertSame([], $requestData);
        self::assertSame($initialHash, $membership->user->fresh()->password);
        self::assertSame(0, $membership->user->fresh()->access_auth_revision);
        self::assertDatabaseMissing('office_erp_commercial_operations', ['idempotency_key' => 'credential-store-failure']);
        self::assertStringNotContainsString($secret, json_encode($logs, JSON_THROW_ON_ERROR));
    }

    public function test_reset_revokes_a_pending_recovery_token_and_its_delivery_authority(): void
    {
        Queue::fake();
        $ctx = $this->officeContext();
        [$membership, $registration] = $this->companyMember($ctx);
        $request = Request::create('/api/v1/erp-commercial/companies', 'POST', server: ['REMOTE_ADDR' => '192.0.2.53']);
        $credentials = app(OfficeErpMemberCredentials::class);
        $issued = $this->withOfficeAccount($ctx, fn (): array => $credentials->issueRecovery(
            $request, $ctx, (int) $registration->erp_company_id, (int) $membership->id, 'recovery-before-reset',
        ));
        $delivery = OfficeErpAccessDelivery::query()->findOrFail($issued['data']['delivery_id']);
        $token = $delivery->payload['token'];
        self::assertTrue(Password::broker()->tokenExists($membership->user, $token));

        $this->withOfficeAccount($ctx, fn (): array => $credentials->reset(
            $request, $ctx, (int) $registration->erp_company_id, (int) $membership->id, 0, 'reset-with-pending-token', 'reset-after-recovery',
        ));

        self::assertFalse(Password::broker()->tokenExists($membership->user->fresh(), $token));
        self::assertNotNull($delivery->fresh()->authority_invalidated_at);
        self::assertNull($delivery->fresh()->payload);
    }

    public function test_revoking_old_membership_recovery_preserves_a_newer_user_wide_authority(): void
    {
        Queue::fake();
        $ctx = $this->officeContext();
        [$firstMembership] = $this->companyMember($ctx);
        $secondUser = $firstMembership->user;
        [$secondCompany, $secondRegistration] = $this->company($ctx);
        $secondMembership = ErpCompanyMembership::factory()->role(ErpCompanyRole::Operator)->create([
            'erp_company_id' => $secondCompany->id,
            'user_id' => $secondUser->id,
        ]);
        $request = Request::create('/api/v1/erp-commercial/companies', 'POST', server: ['REMOTE_ADDR' => '192.0.2.54']);
        $credentials = app(OfficeErpMemberCredentials::class);
        $first = $this->withOfficeAccount($ctx, fn (): array => $credentials->issueRecovery(
            $request, $ctx, (int) $firstMembership->erp_company_id, (int) $firstMembership->id, 'old-membership-recovery',
        ));
        $oldDelivery = OfficeErpAccessDelivery::query()->findOrFail($first['data']['delivery_id']);
        $oldToken = $oldDelivery->payload['token'];
        $this->travel(61)->seconds();
        $second = $this->withOfficeAccount($ctx, fn (): array => $credentials->issueRecovery(
            $request, $ctx, (int) $secondRegistration->erp_company_id, (int) $secondMembership->id, 'new-membership-recovery',
        ));
        $newDelivery = OfficeErpAccessDelivery::query()->findOrFail($second['data']['delivery_id']);
        $newToken = $newDelivery->payload['token'];

        self::assertNotNull($oldDelivery->fresh()->authority_invalidated_at);
        self::assertNull($oldDelivery->fresh()->payload);
        self::assertTrue(Password::broker()->tokenExists($secondUser->fresh(), $newToken));

        $this->withOfficeAccount($ctx, fn (): mixed => DB::transaction(fn () => $credentials->invalidateRecoveryForMembership(
            $ctx, (int) $firstMembership->id, (int) $secondUser->id,
        )));

        self::assertTrue(Password::broker()->tokenExists($secondUser->fresh(), $newToken));
        self::assertNull($newDelivery->fresh()->authority_invalidated_at);
        self::assertFalse(Password::broker()->tokenExists($secondUser->fresh(), $oldToken));
    }

    public function test_public_resolve_store_outage_returns_retryable_response_without_reporting_the_bearer(): void
    {
        Queue::fake();
        $ctx = $this->officeContext();
        [$membership, $registration] = $this->companyMember($ctx);
        $this->actingAs($ctx->actor, 'web')->withHeader('Idempotency-Key', 'resolve-outage-issue')
            ->postJson('/api/v1/erp-commercial/companies/'.$registration->erp_company_id.'/members/'.$membership->id.'/recovery', [])
            ->assertAccepted();
        $delivery = OfficeErpAccessDelivery::query()->where('purpose', 'password_recovery')->sole();
        $token = $delivery->payload['token'];
        $queryFailure = false;
        $requestData = null;
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logs): void {
            $logs[] = [$event->message, $event->context];
        });
        DB::listen(function (QueryExecuted $event) use (&$queryFailure, &$requestData, $token): void {
            if (! $queryFailure && str_contains(strtolower($event->sql), 'password_reset_tokens')) {
                $queryFailure = true;
                $requestData = request()->all();
                throw new QueryException('sqlite', $event->sql, $event->bindings, new \PDOException($token));
            }
        });

        Auth::logout();
        $response = $this->postJson('/api/v1/password-recovery/resolve', ['token' => $token, 'delivery_id' => $delivery->id]);
        $response->assertServiceUnavailable()->assertJsonMissing(['message' => $token])->assertHeader('Referrer-Policy', 'no-referrer');
        self::assertTrue($queryFailure);
        self::assertSame([], $requestData);
        self::assertStringNotContainsString($token, json_encode($logs, JSON_THROW_ON_ERROR));
    }

    /** @return array{ErpCompany,OfficeErpCompanyRegistration} */
    private function company(OfficeErpExecutionContext $ctx): array
    {
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id, 'agreement_status' => 'accepted'])->save();
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'erp_plan_id' => app(ErpCommercialCatalog::class)->ensureBasicPlan()->id,
            'office_erp_contract_id' => $contract->id,
        ]);
        $registration = OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $contract->client_id,
            'erp_company_id' => $company->id,
            'created_by_user_id' => $ctx->actor->id,
        ]);

        return [$company, $registration];
    }

    /** @return array{ErpCompanyMembership,OfficeErpCompanyRegistration} */
    private function companyMember(OfficeErpExecutionContext $ctx): array
    {
        [$company, $registration] = $this->company($ctx);
        $membership = ErpCompanyMembership::factory()->role(ErpCompanyRole::Operator)->create([
            'erp_company_id' => $company->id,
            'user_id' => User::factory()->create(['email' => fake()->unique()->safeEmail()])->id,
        ]);

        return [$membership, $registration];
    }
}
