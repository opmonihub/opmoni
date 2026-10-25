<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpOperations;
use App\Erp\Commercial\OfficeErpOwnerInvitations;
use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpClientController;
use App\Http\Controllers\Api\V1\OfficeErp\OfficeErpInvitationController;
use App\Http\Middleware\ResolveOfficeErpCommercial;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpCommercialSetting;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class OwnerInvitationTest extends OfficeErpCommercialTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', ResolveOfficeErpCommercial::class])
            ->prefix('api/v1/erp-commercial')
            ->group(function (): void {
                Route::get('/clients', [OfficeErpClientController::class, 'index']);
                Route::post('/clients/{client}/onboarding-preview', [OfficeErpClientController::class, 'preview'])->whereNumber('client');
                Route::post('/clients/{client}/invitations', [OfficeErpInvitationController::class, 'store'])->whereNumber('client');
                Route::post('/invitations/{invitation}/revoke', [OfficeErpInvitationController::class, 'revoke'])->whereNumber('invitation');
                Route::post('/invitations/{invitation}/reissue', [OfficeErpInvitationController::class, 'reissue'])->whereNumber('invitation');
            });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_preview_is_reviewable_and_creates_no_contract_or_reservation(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 3, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx, ['price' => '321.98', 'interval_months' => 3, 'due_in_days' => 12]);
        OfficeErpCommercialSetting::factory()->create(['account_id' => $ctx->accountId, 'billing_timezone' => 'America/Fortaleza']);

        $preview = $this->asContext($ctx, fn (): array => app(OfficeErpOwnerInvitations::class)->preview($ctx, $client->id, $revision->id, $this->owner()));

        self::assertSame($client->id, $preview['client']['id']);
        self::assertSame($ctx->accountId, $preview['seller']['account_id']);
        self::assertSame('owner@example.test', $preview['owner']['email']);
        self::assertSame('321.98', $preview['offer']['price']);
        self::assertSame(3, $preview['offer']['interval_months']);
        self::assertTrue($preview['offer']['published']);
        self::assertSame(12, $preview['offer']['due_in_days']);
        self::assertSame('activation_date', $preview['service']['start_mode']);
        self::assertSame('America/Fortaleza', $preview['service']['billing_timezone']);
        self::assertSame(12, $preview['service']['due_in_days']);
        self::assertTrue($preview['capacity']['can_issue']);
        self::assertSame(0, OfficeErpContract::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpLicenceAllocation::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)->count());
    }

    public function test_preview_remains_reviewable_but_disables_issue_when_offer_modules_exceed_grant(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 3, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx, ['module_keys' => ['purchases', 'sales']]);

        $preview = $this->asContext($ctx, fn (): array => app(OfficeErpOwnerInvitations::class)->preview($ctx, $client->id, $revision->id, $this->owner()));

        self::assertSame($revision->id, $preview['offer']['revision_id']);
        self::assertFalse($preview['capacity']['can_issue']);
        self::assertSame('commercial_module_not_allowed', $preview['capacity']['reason']);
        self::assertSame(0, OfficeErpContract::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpLicenceAllocation::query()->where('account_id', $ctx->accountId)->count());
    }

    public function test_client_list_searches_and_paginates_only_active_clients_in_selected_office(): void
    {
        $ctx = $this->officeContext();
        $foreignContext = $this->officeContext();
        for ($number = 1; $number <= 26; $number++) {
            Client::factory()->company()->create([
                'account_id' => $ctx->accountId,
                'name' => sprintf('Carteira teste %02d', $number),
                'status' => 'active',
            ]);
        }
        Client::factory()->company()->create(['account_id' => $ctx->accountId, 'name' => 'Carteira inativa', 'status' => 'inactive']);
        Client::factory()->company()->create(['account_id' => $foreignContext->accountId, 'name' => 'Carteira teste estrangeira', 'status' => 'active']);

        $service = app(OfficeErpOwnerInvitations::class);
        $firstPage = $this->asContext($ctx, fn (): array => $service->clients($ctx, 'Carteira teste', 1, 25));
        $secondPage = $this->asContext($ctx, fn (): array => $service->clients($ctx, 'Carteira teste', 2, 25));

        self::assertSame(['current_page' => 1, 'last_page' => 2, 'per_page' => 25, 'total' => 26], $firstPage['meta']);
        self::assertCount(25, $firstPage['data']);
        self::assertCount(1, $secondPage['data']);
        self::assertSame('Carteira teste 26', $secondPage['data'][0]['name']);
        self::assertStringNotContainsString('estrangeira', json_encode([$firstPage, $secondPage], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('inativa', json_encode([$firstPage, $secondPage], JSON_THROW_ON_ERROR));
    }

    public function test_temporary_http_routes_exercise_client_preview_issue_reissue_and_revoke_controllers(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx);

        $this->asOffice($ctx)->getJson('/api/v1/erp-commercial/clients?q='.$client->name)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $client->id);
        $this->asOffice($ctx)->postJson('/api/v1/erp-commercial/clients/'.$client->id.'/onboarding-preview', [
            'revision_id' => $revision->id,
            'owner' => $this->owner(),
        ])->assertOk()->assertJsonPath('data.offer.revision_id', $revision->id);

        $issued = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'controller-issue')
            ->postJson('/api/v1/erp-commercial/clients/'.$client->id.'/invitations', [
                'revision_id' => $revision->id,
                'owner' => $this->owner(),
            ])->assertOk()->assertJsonPath('data.status', 'pending');
        $invitationId = $issued->json('data.invitation_id');
        self::assertStringContainsString('#token=', $issued->json('data.url'));
        self::assertStringContainsString('no-store', (string) $issued->headers->get('Cache-Control'));
        $issuedOperation = OfficeErpCommercialOperation::query()->where('idempotency_key', 'controller-issue')->firstOrFail();
        self::assertSame($issuedOperation->outcome['data'], array_intersect_key($issued->json('data'), array_flip(['invitation_id', 'expires_at', 'status'])));

        $reissued = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'controller-reissue')
            ->postJson('/api/v1/erp-commercial/invitations/'.$invitationId.'/reissue')
            ->assertOk()->assertJsonPath('data.status', 'pending');
        $revoked = $this->asOffice($ctx)->withHeader('Idempotency-Key', 'controller-revoke')
            ->postJson('/api/v1/erp-commercial/invitations/'.$reissued->json('data.invitation_id').'/revoke', ['reason' => 'Requested by the invited owner.'])
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        $revokedOperation = OfficeErpCommercialOperation::query()->where('idempotency_key', 'controller-revoke')->firstOrFail();
        self::assertSame($revokedOperation->outcome, $revoked->json());
    }

    public function test_issue_creates_seven_day_secret_hash_snapshot_and_one_reservation(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 12:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx, ['price' => '123.45', 'due_in_days' => 14]);
        OfficeErpCommercialSetting::factory()->create(['account_id' => $ctx->accountId, 'billing_timezone' => 'America/Fortaleza']);
        Log::spy();

        $result = $this->asContext($ctx, fn (OfficeErpOwnerInvitations $service): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-first'));

        self::assertGreaterThan(0, $result['invitation_id']);
        self::assertSame(now()->addDays(7)->toDateTimeString(), $result['expires_at']);
        self::assertSame('pending', $result['status']);
        self::assertStringStartsWith('http://localhost:3000/erp/ativar#token=', $result['url']);
        $rawToken = substr($result['url'], strpos($result['url'], '#token=') + strlen('#token='));
        $invitation = OfficeErpOwnerInvitation::query()->findOrFail($result['invitation_id']);
        self::assertNotSame($rawToken, $invitation->token_hash);
        self::assertSame(hash('sha256', $rawToken), $invitation->token_hash);
        self::assertSame($invitation->id, $result['invitation_id']);
        self::assertSame('owner@example.test', $invitation->owner_email);
        self::assertSame('Maria da Silva', $invitation->owner_name);
        self::assertSame(['account_id' => $ctx->accountId, 'name' => $ctx->account->name], $invitation->seller_snapshot);
        self::assertSame($ctx->accountId, $invitation->account_id);

        $contract = OfficeErpContract::query()->findOrFail($invitation->contract_id);
        self::assertSame('awaiting_owner', $contract->agreement_status);
        self::assertSame($client->id, $contract->client_id);
        self::assertNull($contract->erp_company_id);
        self::assertSame('America/Fortaleza', $contract->billing_timezone);
        self::assertSame('123.45', (string) $contract->currentRevision->price);
        self::assertSame($revision->id, $contract->currentRevision->sales_plan_revision_id);
        self::assertSame(14, $contract->currentRevision->due_in_days);
        self::assertSame(['purchases'], $contract->currentRevision->module_keys);

        $allocation = OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->firstOrFail();
        self::assertSame('reserved', $allocation->state);
        self::assertSame(now()->addDays(7)->toDateTimeString(), $allocation->expires_at->toDateTimeString());
        self::assertSame(1, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx))['usage']['reserved']);

        $stored = json_encode([
            OfficeErpCommercialOperation::query()->firstOrFail()->toArray(),
            OfficeErpAuditEvent::query()->firstOrFail()->toArray(),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($rawToken, $stored);
        self::assertSame([
            'data' => ['invitation_id' => $invitation->id, 'expires_at' => $result['expires_at'], 'status' => 'pending'],
        ], OfficeErpCommercialOperation::query()->firstOrFail()->outcome);
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_equal_issue_replay_recovers_status_without_secret_and_changed_payload_conflicts(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx);
        $service = app(OfficeErpOwnerInvitations::class);
        $first = $this->asContext($ctx, fn (): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-replay'));

        $replay = $this->asContext($ctx, fn (): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-replay'));

        self::assertSame($first['invitation_id'], $replay['invitation_id']);
        self::assertSame($first['expires_at'], $replay['expires_at']);
        self::assertSame('pending', $replay['status']);
        self::assertArrayNotHasKey('url', $replay);
        self::assertSame(1, OfficeErpContract::query()->count());
        self::assertSame(1, OfficeErpOwnerInvitation::query()->count());
        self::assertSame(1, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx))['usage']['reserved']);

        $lookedUp = $this->asContext($ctx, fn (): array => app(OfficeErpOperations::class)->lookup(
            $ctx,
            'invite-replay',
            fn (string $action, array $payload): mixed => $service->authorizeOperation($ctx, app(OfficeErpAdmission::class), $action, $payload, 'invite-replay'),
        ));
        self::assertSame([
            'data' => ['invitation_id' => $first['invitation_id'], 'expires_at' => $first['expires_at'], 'status' => 'pending'],
        ], $lookedUp);

        try {
            $this->asContext($ctx, fn (): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(['email' => 'different@example.test']), 'invite-replay'));
            self::fail('Changed owner payload must conflict with the original operation.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }
    }

    public function test_equal_issue_replay_recovers_durable_status_after_capacity_is_reassigned(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 12:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $firstClient = $this->clientFor($ctx);
        $secondClient = Client::factory()->company()->create(['account_id' => $ctx->accountId, 'status' => 'active']);
        $revision = $this->publishedRevision($ctx);
        $service = app(OfficeErpOwnerInvitations::class);
        $first = $this->asContext($ctx, fn (): array => $service->issue($ctx, $firstClient->id, $revision->id, $this->owner(), 'replay-after-expiry-first'));
        CarbonImmutable::setTestNow(now()->addDays(8));
        $this->asContext($ctx, fn (): array => $service->issue($ctx, $secondClient->id, $revision->id, $this->owner(['email' => 'second@example.test']), 'replay-after-expiry-second'));

        $replay = $this->asContext($ctx, fn (): array => $service->issue($ctx, $firstClient->id, $revision->id, $this->owner(), 'replay-after-expiry-first'));

        self::assertSame($first['invitation_id'], $replay['invitation_id']);
        self::assertSame('pending', $replay['status']);
        self::assertArrayNotHasKey('url', $replay);
        self::assertSame('expired', OfficeErpOwnerInvitation::query()->findOrFail($first['invitation_id'])->status);
        self::assertSame(1, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx))['usage']['reserved']);
    }

    public function test_foreign_or_inactive_client_is_rejected_without_contract_or_journal_writes(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $foreignContext = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $foreignClient = $this->clientFor($foreignContext);
        $inactiveClient = $this->clientFor($ctx);
        $inactiveClient->forceFill(['status' => 'inactive'])->save();
        $revision = $this->publishedRevision($ctx);
        $service = app(OfficeErpOwnerInvitations::class);

        try {
            $this->asContext($ctx, fn (): array => $service->preview($ctx, $foreignClient->id, $revision->id, $this->owner()));
            self::fail('Foreign portfolio Client must be hidden.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
        try {
            $this->asContext($ctx, fn (): array => $service->issue($ctx, $inactiveClient->id, $revision->id, $this->owner(), 'inactive-client'));
            self::fail('Inactive portfolio Client cannot be invited.');
        } catch (HttpException $exception) {
            self::assertSame(422, $exception->getStatusCode());
        }

        self::assertSame(0, OfficeErpContract::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpAuditEvent::query()->where('account_id', $ctx->accountId)->count());
    }

    public function test_unpublished_foreign_and_archived_revisions_are_rejected_before_writes(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 4, 'module_keys' => ['purchases']]);
        $foreignContext = $this->officeContext(['enabled' => true, 'licence_limit' => 4, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $unpublished = $this->publishedRevision($ctx, ['published_at' => null]);
        $foreignRevision = $this->publishedRevision($foreignContext);
        $archived = $this->publishedRevision($ctx, ['archived' => true]);
        $service = app(OfficeErpOwnerInvitations::class);

        foreach ([[$unpublished, 422], [$foreignRevision, 404], [$archived, 409]] as [$revision, $status]) {
            try {
                $this->asContext($ctx, fn (): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(), 'invalid-revision-'.$revision->id));
                self::fail('Unusable retail revisions must be rejected.');
            } catch (HttpException $exception) {
                self::assertSame($status, $exception->getStatusCode());
            }
        }

        self::assertSame(0, OfficeErpContract::query()->where('account_id', $ctx->accountId)->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)->count());
    }

    public function test_expiry_reissue_and_revocation_release_or_replace_capacity_once(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 12:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx);
        $service = app(OfficeErpOwnerInvitations::class);
        $first = $this->asContext($ctx, fn (): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-release-first'));

        $second = $this->asContext($ctx, fn (): array => $service->reissue($ctx, $first['invitation_id'], 'invite-release-second'));
        $secondReplay = $this->asContext($ctx, fn (): array => $service->reissue($ctx, $first['invitation_id'], 'invite-release-second'));

        self::assertNotSame($first['invitation_id'], $second['invitation_id']);
        self::assertSame($second['invitation_id'], $secondReplay['invitation_id']);
        self::assertArrayNotHasKey('url', $secondReplay);
        self::assertStringStartsWith('http://localhost:3000/erp/ativar#token=', $second['url']);
        self::assertSame('reissued', OfficeErpOwnerInvitation::query()->findOrFail($first['invitation_id'])->status);
        self::assertSame(1, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx))['usage']['reserved']);
        $this->asContext($ctx, fn () => $service->revoke($ctx, $second['invitation_id'], 'Owner requested cancellation', 'invite-revoke'));
        self::assertSame('revoked', OfficeErpOwnerInvitation::query()->findOrFail($second['invitation_id'])->status);
        self::assertSame(0, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx))['usage']['reserved']);
        $this->asContext($ctx, fn () => $service->revoke($ctx, $second['invitation_id'], 'Owner requested cancellation', 'invite-revoke-retry'));
        self::assertSame(0, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx))['usage']['reserved']);

        CarbonImmutable::setTestNow(now()->addDays(8));
        $third = $this->asContext($ctx, fn (): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-expired-replacement'));
        CarbonImmutable::setTestNow(now()->addDays(8));
        $fourth = $this->asContext($ctx, fn (): array => $service->reissue($ctx, $third['invitation_id'], 'invite-expired-reissue'));
        self::assertSame('expired', OfficeErpOwnerInvitation::query()->findOrFail($third['invitation_id'])->status);
        self::assertNotSame($third['invitation_id'], $fourth['invitation_id']);
        self::assertSame(1, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx))['usage']['reserved']);
    }

    public function test_expiry_is_attributed_and_releases_its_reservation_once_on_the_next_office_read(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 12:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx);
        $issued = $this->asContext($ctx, fn (): array => app(OfficeErpOwnerInvitations::class)->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-expiry-attribution'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($issued['expires_at'])->addSecond());

        $this->asContext($ctx, fn (): array => app(OfficeErpOwnerInvitations::class)->clients($ctx));
        $invitation = OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id']);
        self::assertSame('expired', $invitation->status);
        self::assertSame('released', OfficeErpLicenceAllocation::query()->firstOrFail()->state);
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('action', 'invitation.expire')->count());
        self::assertSame(1, OfficeErpAuditEvent::query()->where('action', 'invitation.expire')->count());

        $this->asContext($ctx, fn (): array => app(OfficeErpOwnerInvitations::class)->clients($ctx));
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('action', 'invitation.expire')->count());
        self::assertSame(0, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx))['usage']['reserved']);
    }

    public function test_invitation_expiration_is_inclusive_at_the_exact_expiry_instant(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 12:00:00');
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx);
        $issued = $this->asContext($ctx, fn (): array => app(OfficeErpOwnerInvitations::class)->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-expiry-equality'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($issued['expires_at']));

        $this->asContext($ctx, fn (): array => app(OfficeErpOwnerInvitations::class)->clients($ctx));

        self::assertSame('expired', OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->status);
        self::assertSame('released', OfficeErpLicenceAllocation::query()->firstOrFail()->state);
        self::assertSame(1, OfficeErpCommercialOperation::query()->where('action', 'invitation.expire')->count());
    }

    public function test_reissue_rechecks_new_sale_authority(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $revision = $this->publishedRevision($ctx);
        $service = app(OfficeErpOwnerInvitations::class);
        $issued = $this->asContext($ctx, fn (): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-reissue-authority'));
        $subscription = $ctx->account->subscription()->firstOrFail();
        $subscription->forceFill(['status' => 'canceled'])->save();

        try {
            $this->asContext($ctx, fn (): array => $service->reissue($ctx, $issued['invitation_id'], 'invite-reissue-denied'));
            self::fail('Reissue after resale authority loss must be denied.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        self::assertSame(1, OfficeErpContract::query()->count());
        self::assertSame('awaiting_owner', OfficeErpContract::query()->firstOrFail()->agreement_status);
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->status);
        self::assertSame(1, $this->asContext($ctx, fn (): array => app(OfficeErpAdmission::class)->usage($ctx))['reserved']);

        $recovered = $this->asContext($ctx, fn (): array => $service->issue($ctx, $client->id, $revision->id, $this->owner(), 'invite-reissue-authority'));
        self::assertSame($issued['invitation_id'], $recovered['invitation_id']);
        self::assertArrayNotHasKey('url', $recovered);
    }

    /** @param array<string, mixed> $overrides */
    private function publishedRevision(OfficeErpExecutionContext $ctx, array $overrides = []): OfficeErpSalesPlanRevision
    {
        $plan = OfficeErpSalesPlan::factory()->create([
            'account_id' => $ctx->accountId,
            'name' => 'ERP para varejo',
            'status' => $overrides['archived'] ?? false ? 'archived' : 'active',
            'archived_at' => $overrides['archived'] ?? false ? now() : null,
        ]);
        unset($overrides['archived']);
        $revision = OfficeErpSalesPlanRevision::factory()->create(array_replace([
            'account_id' => $ctx->accountId,
            'sales_plan_id' => $plan->id,
            'name' => 'ERP para varejo',
            'price' => '100.00',
            'interval_months' => 1,
            'due_in_days' => 10,
            'module_keys' => ['purchases'],
            'published_at' => now(),
        ], $overrides));
        $plan->forceFill(['current_revision_id' => $revision->id])->save();

        return $revision;
    }

    /** @return array{full_name: string, email: string} */
    private function owner(array $overrides = []): array
    {
        return array_replace(['full_name' => 'Maria da Silva', 'email' => 'owner@example.test'], $overrides);
    }

    private function asContext(OfficeErpExecutionContext $ctx, Closure $callback): mixed
    {
        return $this->withOfficeAccount($ctx, fn (): mixed => $callback(app(OfficeErpOwnerInvitations::class)));
    }

    private function asOffice(OfficeErpExecutionContext $ctx): self
    {
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        return $this->withHeader('X-Account-Id', (string) $ctx->accountId);
    }
}
