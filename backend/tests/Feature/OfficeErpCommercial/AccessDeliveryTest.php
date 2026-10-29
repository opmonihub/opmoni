<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\LaravelOfficeErpAccessDeliveryTransport;
use App\Erp\Commercial\OfficeErpAccessDeliveries;
use App\Erp\Commercial\OfficeErpAccessDeliveryTransport;
use App\Erp\Commercial\OfficeErpAccessDeliveryTransportRejected;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpOperations;
use App\Http\Middleware\AccessSecurity;
use App\Http\Middleware\ResolveOfficeErpCommercial;
use App\Http\Middleware\SerializedAccessSession;
use App\Http\Middleware\TrustedIngress;
use App\Jobs\DeliverOfficeErpAccessDelivery;
use App\Mail\OfficeErpAccessDeliveryMail;
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
use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class AccessDeliveryTest extends OfficeErpCommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Route::has('office-erp-commercial.access-deliveries.show')) {
            Route::prefix('api/v1')->middleware([
                TrustedIngress::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                SerializedAccessSession::class,
                AccessSecurity::class,
                'auth',
            ])->group(function (): void {
                Route::prefix('erp-commercial')->middleware(ResolveOfficeErpCommercial::class)->group(function (): void {
                    require base_path('routes/office-erp-access-deliveries.php');
                });
            });
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_prepare_persists_only_encrypted_secret_and_queues_delivery_id_after_commit(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $token = str_repeat('a', 64);
        $result = $this->prepare($ctx, $registration, $invitation, $token);

        self::assertSame(['id' => $result['id'], 'state' => 'prepared'], $result);
        $delivery = OfficeErpAccessDelivery::query()->findOrFail($result['id']);
        self::assertSame(['token' => $token], $delivery->payload);
        self::assertStringNotContainsString($token, (string) $delivery->getRawOriginal('payload'));
        self::assertSame($invitation->email, $delivery->recipient_email);
        self::assertSame('member_invitation', $delivery->purpose);
        self::assertArrayNotHasKey('payload', $delivery->toArray());
        self::assertStringNotContainsString($token, json_encode($result, JSON_THROW_ON_ERROR));
        self::assertSame($result['id'], OfficeErpCommercialOperation::query()->where('idempotency_key', 'delivery-prepare')->firstOrFail()->outcome['id']);
        self::assertStringNotContainsString($token, json_encode(OfficeErpCommercialOperation::query()->where('idempotency_key', 'delivery-prepare')->firstOrFail()->outcome, JSON_THROW_ON_ERROR));
        $replayed = $this->prepare($ctx, $registration, $invitation, str_repeat('d', 64));
        self::assertSame($result, $replayed);
        self::assertSame(1, OfficeErpAccessDelivery::query()->count());
        Bus::assertDispatchedTimes(DeliverOfficeErpAccessDelivery::class, 1);
        Bus::assertDispatched(DeliverOfficeErpAccessDelivery::class, fn (DeliverOfficeErpAccessDelivery $job): bool => $job->deliveryId === $result['id']);
        self::assertSame($result['id'], (new DeliverOfficeErpAccessDelivery($result['id']))->deliveryId);
        self::assertStringNotContainsString($token, serialize(new DeliverOfficeErpAccessDelivery($result['id'])));
    }

    public function test_prepare_requires_the_callers_transaction_and_rollback_leaves_no_delivery_or_job(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);

        try {
            DB::transaction(function () use ($ctx, $registration, $invitation): void {
                $this->prepare($ctx, $registration, $invitation, str_repeat('b', 64));
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            self::assertSame('rollback', $exception->getMessage());
        }

        self::assertSame(0, OfficeErpAccessDelivery::query()->count());
        Bus::assertNothingDispatched();
    }

    public function test_real_delivery_transport_refuses_log_and_array_mailers(): void
    {
        $transport = new LaravelOfficeErpAccessDeliveryTransport;
        config(['mail.default' => 'log']);

        try {
            $transport->send('recipient@example.test', 'Recovery', 'https://frontend.test/redefinir-senha#token='.str_repeat('a', 64));
            self::fail('A log mailer must never receive a bearer URL.');
        } catch (OfficeErpAccessDeliveryTransportRejected) {
            self::assertTrue(true);
        }

        config(['mail.default' => 'array']);

        try {
            $transport->send('recipient@example.test', 'Recovery', 'https://frontend.test/redefinir-senha#token='.str_repeat('a', 64));
            self::fail('An array mailer must never receive a bearer URL.');
        } catch (OfficeErpAccessDeliveryTransportRejected) {
            self::assertTrue(true);
        }

        config(['mail.default' => 'failover', 'mail.mailers.failover.mailers' => ['smtp', 'log']]);

        try {
            $transport->send('recipient@example.test', 'Recovery', 'https://frontend.test/redefinir-senha#token='.str_repeat('a', 64));
            self::fail('A failover to log must never receive a bearer URL.');
        } catch (OfficeErpAccessDeliveryTransportRejected) {
            self::assertTrue(true);
        }
    }

    public function test_allowlisted_mailer_sends_the_configured_fragment_url(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);

        $url = 'https://configured-frontend.example/redefinir-senha#token='.str_repeat('c', 64).'&delivery=42';
        $mail = new OfficeErpAccessDeliveryMail('Recuperação de acesso', $url);
        self::assertStringContainsString($url, $mail->render());
        $invitationMail = new OfficeErpAccessDeliveryMail('Convite para acessar uma empresa no Opmoni', 'https://frontend.test/erp/convite#token='.str_repeat('c', 64));
        self::assertStringContainsString('Você recebeu um convite para acessar uma empresa', $invitationMail->render());
        (new LaravelOfficeErpAccessDeliveryTransport)->send('recipient@example.test', 'Recuperação de acesso', $url);

        Mail::assertSent(OfficeErpAccessDeliveryMail::class, fn (OfficeErpAccessDeliveryMail $sentMail): bool => $sentMail->url === $url);
    }

    public function test_expiry_command_clears_failed_and_sending_payloads_without_inventing_send_results(): void
    {
        CarbonImmutable::setTestNow('2026-10-28 12:00:00');
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $failedId = $this->prepare($ctx, $registration, $invitation, str_repeat('e', 64))['id'];
        $failed = OfficeErpAccessDelivery::query()->findOrFail($failedId);
        $failed->forceFill(['state' => 'failed', 'expires_at' => CarbonImmutable::now()])->save();
        $sendingId = $this->prepareWithKey($ctx, $registration, $invitation, str_repeat('f', 64), 'delivery-sending')['id'];
        OfficeErpAccessDelivery::query()->whereKey($sendingId)->update(['state' => 'sending', 'expires_at' => CarbonImmutable::now()]);
        $acceptedId = $this->prepareWithKey($ctx, $registration, $invitation, str_repeat('1', 64), 'delivery-accepted')['id'];
        OfficeErpAccessDelivery::query()->whereKey($acceptedId)->update(['state' => 'transport_accepted', 'payload' => null, 'expires_at' => CarbonImmutable::now()]);

        $this->artisan('office-erp:expire-access-deliveries', ['--batch' => 100])->assertSuccessful();

        self::assertSame('expired', OfficeErpAccessDelivery::query()->findOrFail($failedId)->state);
        self::assertNull(OfficeErpAccessDelivery::query()->findOrFail($failedId)->payload);
        self::assertNotNull(OfficeErpAccessDelivery::query()->findOrFail($failedId)->authority_invalidated_at);
        self::assertSame('unknown', OfficeErpAccessDelivery::query()->findOrFail($sendingId)->state);
        self::assertNull(OfficeErpAccessDelivery::query()->findOrFail($sendingId)->payload);
        self::assertSame('transport_accepted', OfficeErpAccessDelivery::query()->findOrFail($acceptedId)->state);
        self::assertNull(OfficeErpAccessDelivery::query()->findOrFail($acceptedId)->payload);
    }

    public function test_expiry_command_enforces_its_batch_bound(): void
    {
        $this->artisan('office-erp:expire-access-deliveries', ['--batch' => 101])->assertFailed();
    }

    public function test_member_invitation_delivery_cannot_outlive_seven_days(): void
    {
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $this->expectException(HttpException::class);

        $this->prepareWithKey(
            $ctx,
            $registration,
            $invitation,
            str_repeat('8', 64),
            'delivery-too-long',
            CarbonImmutable::now()->addDays(8),
        );
    }

    public function test_password_recovery_delivery_cannot_outlive_sixty_minutes(): void
    {
        $ctx = $this->officeContext();
        $user = User::factory()->create(['email' => 'recipient@example.test', 'access_auth_revision' => 0]);
        $this->expectException(HttpException::class);

        $this->recoveryFor($ctx, $user, 'recovery-too-long', str_repeat('9', 64), CarbonImmutable::now()->addMinutes(61));
    }

    public function test_failed_queue_worker_marks_a_possible_send_unknown_without_secret_job_data(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $token = str_repeat('2', 64);
        $deliveryId = $this->prepare($ctx, $registration, $invitation, $token)['id'];
        OfficeErpAccessDelivery::query()->whereKey($deliveryId)->update(['state' => 'sending']);
        $job = new DeliverOfficeErpAccessDelivery($deliveryId);

        $job->failed(new RuntimeException('simulated worker interruption '.$token));

        self::assertSame('unknown', OfficeErpAccessDelivery::query()->findOrFail($deliveryId)->state);
        self::assertNull(OfficeErpAccessDelivery::query()->findOrFail($deliveryId)->payload);
        self::assertStringNotContainsString($token, serialize($job));
    }

    public function test_definite_pre_send_failure_can_retry_same_token_and_acceptance_clears_payload(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $token = str_repeat('3', 64);
        $deliveryId = $this->prepare($ctx, $registration, $invitation, $token)['id'];
        config(['access.origin' => 'https://trusted-frontend.example']);
        $transport = new class implements OfficeErpAccessDeliveryTransport
        {
            public array $sent = [];

            public bool $reject = true;

            public function assertAvailable(): void {}

            public function send(string $recipient, string $subject, string $url): void
            {
                if ($this->reject) {
                    throw new OfficeErpAccessDeliveryTransportRejected('transport unavailable');
                }

                $this->sent[] = [$recipient, $subject, $url];
            }
        };
        app()->instance(OfficeErpAccessDeliveryTransport::class, $transport);
        $service = app(OfficeErpAccessDeliveries::class);

        config(['access.origin' => 'https://trusted-frontend.example/path?unexpected=1']);
        $service->deliver($deliveryId);
        self::assertSame('failed', OfficeErpAccessDelivery::query()->findOrFail($deliveryId)->state);
        self::assertSame(['token' => $token], OfficeErpAccessDelivery::query()->findOrFail($deliveryId)->payload);
        self::assertSame([], $transport->sent);
        config(['access.origin' => 'https://trusted-frontend.example']);
        $service->retryDefiniteFailure($deliveryId);

        $service->deliver($deliveryId);
        self::assertSame('failed', OfficeErpAccessDelivery::query()->findOrFail($deliveryId)->state);
        self::assertSame(['token' => $token], OfficeErpAccessDelivery::query()->findOrFail($deliveryId)->payload);
        $service->retryDefiniteFailure($deliveryId);
        $transport->reject = false;
        $service->deliver($deliveryId);

        $delivery = OfficeErpAccessDelivery::query()->findOrFail($deliveryId);
        self::assertSame('transport_accepted', $delivery->state);
        self::assertNull($delivery->payload);
        self::assertSame('https://trusted-frontend.example/erp/convite#token='.$token, $transport->sent[0][2]);
        Bus::assertDispatchedTimes(DeliverOfficeErpAccessDelivery::class, 3);
    }

    public function test_possible_send_timeout_becomes_unknown_and_is_never_automatically_retried(): void
    {
        Bus::fake();
        Log::spy();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $token = str_repeat('4', 64);
        $deliveryId = $this->prepare($ctx, $registration, $invitation, $token)['id'];
        $transport = new class implements OfficeErpAccessDeliveryTransport
        {
            public int $attempts = 0;

            public function assertAvailable(): void {}

            public function send(string $recipient, string $subject, string $url): void
            {
                $this->attempts++;
                throw new RuntimeException('possibly transmitted '.$url);
            }
        };
        app()->instance(OfficeErpAccessDeliveryTransport::class, $transport);
        $service = app(OfficeErpAccessDeliveries::class);

        $service->deliver($deliveryId);
        $service->deliver($deliveryId);

        self::assertSame('unknown', OfficeErpAccessDelivery::query()->findOrFail($deliveryId)->state);
        self::assertNull(OfficeErpAccessDelivery::query()->findOrFail($deliveryId)->payload);
        self::assertSame(1, $transport->attempts);
        Bus::assertDispatchedTimes(DeliverOfficeErpAccessDelivery::class, 1);
        Log::shouldNotHaveReceived('error');
    }

    public function test_real_authority_role_revocation_invalidates_and_clears_unsent_secret(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $deliveryId = $this->prepare($ctx, $registration, $invitation, str_repeat('5', 64))['id'];
        AccountMembership::query()->where('account_id', $ctx->accountId)->where('user_id', $ctx->actor->id)->update(['role' => 'operador']);
        $transport = new class implements OfficeErpAccessDeliveryTransport
        {
            public function assertAvailable(): void {}

            public function send(string $recipient, string $subject, string $url): void
            {
                throw new RuntimeException('Transport must not run.');
            }
        };
        app()->instance(OfficeErpAccessDeliveryTransport::class, $transport);

        app(OfficeErpAccessDeliveries::class)->deliver($deliveryId);

        $delivery = OfficeErpAccessDelivery::query()->findOrFail($deliveryId);
        self::assertSame('failed', $delivery->state);
        self::assertNull($delivery->payload);
        self::assertNotNull($delivery->authority_invalidated_at);
    }

    public function test_real_authority_lost_management_relationship_invalidates_and_clears_unsent_secret(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $deliveryId = $this->prepare($ctx, $registration, $invitation, str_repeat('b', 64))['id'];
        OfficeErpContract::query()
            ->where('account_id', $ctx->accountId)
            ->where('erp_company_id', $registration->erp_company_id)
            ->update(['agreement_status' => 'canceled']);
        $transport = new class implements OfficeErpAccessDeliveryTransport
        {
            public function assertAvailable(): void {}

            public function send(string $recipient, string $subject, string $url): void
            {
                throw new RuntimeException('Transport must not run.');
            }
        };
        app()->instance(OfficeErpAccessDeliveryTransport::class, $transport);

        app(OfficeErpAccessDeliveries::class)->deliver($deliveryId);

        $delivery = OfficeErpAccessDelivery::query()->findOrFail($deliveryId);
        self::assertSame('failed', $delivery->state);
        self::assertNull($delivery->payload);
        self::assertNotNull($delivery->authority_invalidated_at);
    }

    public function test_newer_recovery_authority_invalidates_old_membership_and_uses_private_fragment_url(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        $user = User::factory()->create(['email' => 'recipient@example.test', 'access_auth_revision' => 0]);
        $first = $this->recoveryFor($ctx, $user, 'recovery-first', str_repeat('6', 64));
        $second = $this->recoveryFor($ctx, $user, 'recovery-second', str_repeat('7', 64));
        $firstDelivery = OfficeErpAccessDelivery::query()->findOrFail($first['id']);
        $secondDelivery = OfficeErpAccessDelivery::query()->findOrFail($second['id']);

        self::assertNotNull($firstDelivery->authority_invalidated_at);
        self::assertNull($firstDelivery->payload);
        self::assertNull($secondDelivery->authority_invalidated_at);
        config(['access.origin' => 'https://configured-frontend.example']);
        $transport = new class implements OfficeErpAccessDeliveryTransport
        {
            public array $sent = [];

            public function assertAvailable(): void {}

            public function send(string $recipient, string $subject, string $url): void
            {
                $this->sent[] = [$recipient, $subject, $url];
            }
        };
        app()->instance(OfficeErpAccessDeliveryTransport::class, $transport);
        $service = app(OfficeErpAccessDeliveries::class);
        $service->deliver($first['id']);
        $service->deliver($second['id']);

        self::assertSame('failed', OfficeErpAccessDelivery::query()->findOrFail($first['id'])->state);
        self::assertSame('transport_accepted', OfficeErpAccessDelivery::query()->findOrFail($second['id'])->state);
        self::assertCount(1, $transport->sent);
        self::assertSame(
            'https://configured-frontend.example/redefinir-senha#token='.str_repeat('7', 64).'&delivery='.$second['id'],
            $transport->sent[0][2],
        );
    }

    public function test_office_delivery_state_route_is_current_company_scoped_and_secret_free(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $token = str_repeat('a', 64);
        $deliveryId = $this->prepare($ctx, $registration, $invitation, $token)['id'];

        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor)->withHeader('X-Account-Id', (string) $ctx->accountId);
        $response = $this->getJson('/api/v1/erp-commercial/companies/'.$registration->erp_company_id.'/access-deliveries/'.$deliveryId);

        $response->assertOk()
            ->assertJsonPath('data.id', $deliveryId)
            ->assertJsonPath('data.state', 'prepared')
            ->assertJsonPath('data.authority_invalidated_at', null);
        self::assertArrayHasKey('expires_at', $response->json('data'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $projection = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach ([$token, $invitation->email, 'payload', 'recipient_email', 'url'] as $secretField) {
            self::assertStringNotContainsString($secretField, $projection);
        }
        Bus::assertDispatchedTimes(DeliverOfficeErpAccessDelivery::class, 1);
    }

    public function test_office_delivery_state_route_returns_not_found_for_foreign_company(): void
    {
        Bus::fake();
        $ctx = $this->officeContext();
        [$registration, $invitation] = $this->invitationFor($ctx);
        $deliveryId = $this->prepare($ctx, $registration, $invitation, str_repeat('b', 64))['id'];
        $foreignCompany = ErpCompany::factory()->create(['status' => 'active']);

        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor)->withHeader('X-Account-Id', (string) $ctx->accountId);
        $this->getJson('/api/v1/erp-commercial/companies/'.$foreignCompany->id.'/access-deliveries/'.$deliveryId)->assertNotFound();
    }

    /** @return array{OfficeErpCompanyRegistration, OfficeErpMemberInvitation} */
    private function invitationFor(OfficeErpExecutionContext $ctx): array
    {
        [$company, $registration] = $this->managedCompanyFor($ctx);
        $invitation = OfficeErpMemberInvitation::factory()->create([
            'account_id' => $ctx->accountId,
            'erp_company_id' => $company->id,
            'registration_id' => $registration->id,
        ]);

        return [$registration, $invitation];
    }

    /** @return array{id: int, state: string} */
    private function recoveryFor(OfficeErpExecutionContext $ctx, User $user, string $key, string $token, ?CarbonImmutable $expiresAt = null): array
    {
        [$company, $registration] = $this->managedCompanyFor($ctx);
        $membership = ErpCompanyMembership::factory()->create([
            'erp_company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'viewer',
        ]);

        return $ctx->run(fn (): array => app(OfficeErpOperations::class)->execute(
            $ctx,
            $key,
            'credential.recovery.email',
            ['membership_id' => (int) $membership->id, 'recipient_user_id' => (int) $user->id],
            static function (): void {},
            fn (): array => app(OfficeErpAccessDeliveries::class)->prepare(
                $ctx,
                $key,
                'password_recovery',
                [
                    'registration_id' => (int) $registration->id,
                    'company_id' => (int) $company->id,
                    'membership_id' => (int) $membership->id,
                    'recipient_user_id' => (int) $user->id,
                    'recipient_email' => $user->email,
                    'access_auth_revision' => (int) $user->access_auth_revision,
                ],
                ['token' => $token],
                $expiresAt ?? CarbonImmutable::now()->addHour(),
            ),
        ));
    }

    /** @return array{ErpCompany, OfficeErpCompanyRegistration} */
    private function managedCompanyFor(OfficeErpExecutionContext $ctx): array
    {
        $company = ErpCompany::factory()->create(['status' => 'active']);
        $client = $this->clientFor($ctx);
        $registration = OfficeErpCompanyRegistration::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'erp_company_id' => $company->id,
        ]);
        $contract = $this->acceptedContract($ctx);
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        ErpSubscription::factory()->active()->create([
            'erp_company_id' => $company->id,
            'office_erp_contract_id' => $contract->id,
        ]);

        return [$company, $registration];
    }

    /** @return array{id: int, state: string} */
    private function prepare(OfficeErpExecutionContext $ctx, OfficeErpCompanyRegistration $registration, OfficeErpMemberInvitation $invitation, string $token): array
    {
        return $this->prepareWithKey($ctx, $registration, $invitation, $token, 'delivery-prepare');
    }

    /** @return array{id: int, state: string} */
    private function prepareWithKey(OfficeErpExecutionContext $ctx, OfficeErpCompanyRegistration $registration, OfficeErpMemberInvitation $invitation, string $token, string $key, ?CarbonImmutable $expiresAt = null): array
    {
        return $ctx->run(fn (): array => app(OfficeErpOperations::class)->execute(
            $ctx,
            $key,
            'member.invitation.issue',
            ['invitation_id' => (int) $invitation->id],
            static function (): void {},
            fn (): array => app(OfficeErpAccessDeliveries::class)->prepare(
                $ctx,
                $key,
                'member_invitation',
                [
                    'registration_id' => (int) $registration->id,
                    'company_id' => (int) $registration->erp_company_id,
                    'member_invitation_id' => (int) $invitation->id,
                    'recipient_email' => $invitation->email,
                ],
                ['token' => $token],
                $expiresAt ?? $invitation->expires_at,
            ),
        ));
    }
}
