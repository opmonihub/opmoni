<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpOwnerInvitations;
use App\Erp\ErpCompanyProvisioning;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpAuditEvent;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\Erp\ErpSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Testing\TestResponse;
use Tests\OfficeErpCommercialTestCase;

class OwnerAcceptanceTest extends OfficeErpCommercialTestCase
{
    private string $testClientIp;

    protected function setUp(): void
    {
        $this->testClientIp = sprintf('198.%d.%d.%d', random_int(18, 19), random_int(0, 255), random_int(1, 254));
        parent::setUp();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_fragment_exchange_is_session_bound_and_never_persists_the_raw_secret(): void
    {
        $issued = $this->issueInvitation();
        $token = $issued['token'];

        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp-invitations/resolve', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('state', 'valid')
            ->assertJsonPath('data.invitation.owner_email', 'owner@example.test');

        self::assertStringNotContainsString($token, serialize(session()->all()));
        self::assertStringNotContainsString($token, $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->getContent());
        $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->assertOk()->assertJsonPath('state', 'valid');
    }

    public function test_new_owner_has_no_office_membership_and_repeated_acceptance_recovers_the_original_company(): void
    {
        $issued = $this->issueInvitation();
        $this->resolve($issued['token']);
        $key = $this->operationKey();

        $first = $this->accept($key, ['password' => 'new-owner-secret']);
        $first->assertCreated();
        $companyId = (int) $first->json('data.company.id');
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

        self::assertSame(0, AccountMembership::query()->where('user_id', $owner->id)->count());
        self::assertSame($owner->id, ErpCompanyMembership::query()->where('erp_company_id', $companyId)->where('role', 'owner')->value('user_id'));
        self::assertSame($companyId, OfficeErpContract::query()->findOrFail($issued['contract_id'])->erp_company_id);
        self::assertSame('occupied', OfficeErpLicenceAllocation::query()->where('contract_id', $issued['contract_id'])->value('state'));
        self::assertSame('pending', ErpSubscription::query()->where('erp_company_id', $companyId)->value('status'));
        $journal = json_encode([
            OfficeErpCommercialOperation::query()->get()->toArray(),
            OfficeErpAuditEvent::query()->get()->toArray(),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($issued['token'], $journal);
        self::assertStringNotContainsString('new-owner-secret', $journal);

        $this->login($owner, 'new-owner-secret');
        $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->assertOk()->assertJsonPath('state', 'accepted');
        self::assertSame($key, $this->operationKey());
        $retry = $this->accept($key);

        $retry->assertOk();
        self::assertSame($companyId, (int) $retry->json('data.company.id'));
        self::assertSame(1, ErpCompany::query()->whereKey($companyId)->count());
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $companyId)->where('user_id', $owner->id)->count());
    }

    public function test_committed_acceptance_replays_after_seller_loses_resale_rights(): void
    {
        $issued = $this->issueInvitation();
        $this->resolve($issued['token']);
        $key = $this->operationKey();
        $first = $this->accept($key, ['password' => 'new-owner-secret'])->assertCreated();
        $companyId = (int) $first->json('data.company.id');
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->login($owner, 'new-owner-secret');

        $subscription = $issued['context']->account->subscription()->firstOrFail();
        $plan = $subscription->plan()->firstOrFail();
        $allowance = $plan->erp_commercial;
        $allowance['enabled'] = false;
        $plan->forceFill(['erp_commercial' => $allowance])->save();

        $this->accept($key)->assertOk()->assertJsonPath('data.company.id', $companyId);

        self::assertSame('accepted', OfficeErpContract::query()->findOrFail($issued['contract_id'])->agreement_status);
        self::assertSame('occupied', OfficeErpLicenceAllocation::query()->where('contract_id', $issued['contract_id'])->value('state'));
        self::assertSame(1, ErpCompany::query()->whereKey($companyId)->count());
    }

    public function test_legacy_token_reexchange_keeps_the_original_acceptance_operation_key(): void
    {
        $issued = $this->issueInvitation();
        $invitation = OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id']);
        $identity = hash('sha256', $invitation->id.':'.mb_strtolower(trim($invitation->owner_email)));
        $originalKey = hash_hmac('sha256', 'office-erp-owner-acceptance:'.$invitation->id.':'.$identity, (string) config('app.key'));
        $legacyContinuation = [
            'invitation_id' => (int) $invitation->id,
            'account_id' => (int) $invitation->account_id,
            'contract_id' => (int) $invitation->contract_id,
            'owner_email' => $invitation->owner_email,
            'owner_name' => $invitation->owner_name,
            'owner_identity' => $identity,
            'operation_key' => $originalKey,
            'expires_at' => now()->addMinutes(15)->timestamp,
        ];
        $legacyContinuation['signature'] = hash_hmac('sha256', json_encode($legacyContinuation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
        $accepted = $this->requestFromIsolatedIngress()->withSession(['office_erp.owner_continuation' => $legacyContinuation])
            ->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $originalKey])
            ->postJson('/api/v1/erp-invitations/accept', ['password' => 'new-owner-secret'])->assertCreated();
        $companyId = (int) $accepted->json('data.company.id');
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $counts = [
            ErpCompany::query()->count(),
            ErpCompanyMembership::query()->count(),
            ErpSubscription::query()->count(),
            OfficeErpCommercialOperation::query()->count(),
        ];

        $this->login($owner, 'new-owner-secret');
        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp-invitations/resolve', ['token' => $issued['token']])
            ->assertOk()->assertJsonPath('state', 'accepted');

        self::assertSame($originalKey, $this->operationKey());
        $this->accept($originalKey)->assertOk()->assertJsonPath('data.company.id', $companyId);
        self::assertSame($counts, [
            ErpCompany::query()->count(),
            ErpCompanyMembership::query()->count(),
            ErpSubscription::query()->count(),
            OfficeErpCommercialOperation::query()->count(),
        ]);
    }

    public function test_committed_acceptance_replays_after_contract_becomes_effective_without_new_writes(): void
    {
        $issued = $this->issueInvitation();
        $this->resolve($issued['token']);
        $key = $this->operationKey();
        $first = $this->accept($key, ['password' => 'new-owner-secret'])->assertCreated();
        $companyId = (int) $first->json('data.company.id');
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        $this->login($owner, 'new-owner-secret');

        OfficeErpContract::query()->findOrFail($issued['contract_id'])
            ->forceFill(['agreement_status' => 'effective'])
            ->save();
        $contractBefore = OfficeErpContract::query()->findOrFail($issued['contract_id'])->getAttributes();
        $invitationBefore = OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->getAttributes();
        $allocationBefore = OfficeErpLicenceAllocation::query()->where('contract_id', $issued['contract_id'])->firstOrFail()->getAttributes();
        $before = [
            ErpCompany::query()->count(),
            ErpCompanyMembership::query()->count(),
            ErpSubscription::query()->count(),
            OfficeErpCommercialOperation::query()->count(),
            OfficeErpAuditEvent::query()->count(),
        ];

        $this->accept($key)->assertOk()->assertJsonPath('data.company.id', $companyId);

        self::assertSame($before, [
            ErpCompany::query()->count(),
            ErpCompanyMembership::query()->count(),
            ErpSubscription::query()->count(),
            OfficeErpCommercialOperation::query()->count(),
            OfficeErpAuditEvent::query()->count(),
        ]);
        self::assertSame('effective', OfficeErpContract::query()->findOrFail($issued['contract_id'])->agreement_status);
        self::assertSame($contractBefore, OfficeErpContract::query()->findOrFail($issued['contract_id'])->getAttributes());
        self::assertSame($invitationBefore, OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->getAttributes());
        self::assertSame($allocationBefore, OfficeErpLicenceAllocation::query()->where('contract_id', $issued['contract_id'])->firstOrFail()->getAttributes());
    }

    public function test_existing_identity_keeps_password_and_office_memberships(): void
    {
        $issued = $this->issueInvitation();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $office = $this->officeContext()->account;
        $membership = AccountMembership::factory()->create([
            'account_id' => $office->id,
            'user_id' => $owner->id,
            'role' => 'admin',
        ]);
        $oldPasswordHash = $owner->password;
        $oldOfficeMemberships = AccountMembership::query()->where('user_id', $owner->id)->orderBy('account_id')->pluck('account_id')->all();
        $this->resolve($issued['token']);
        $this->login($owner);

        $this->accept($this->operationKey())->assertCreated();

        self::assertSame($oldPasswordHash, $owner->fresh()->password);
        self::assertSame($oldOfficeMemberships, AccountMembership::query()->where('user_id', $owner->id)->orderBy('account_id')->pluck('account_id')->all());
        self::assertTrue(AccountMembership::query()->whereKey($membership->id)->exists());
    }

    public function test_foreign_intended_owner_cannot_accept_or_recover_the_invitation(): void
    {
        $issued = $this->issueInvitation();
        $other = User::factory()->create(['email' => 'other-owner@example.test']);
        $this->resolve($issued['token']);
        $this->login($other);

        $this->accept($this->operationKey())->assertForbidden();

        self::assertSame(0, ErpCompany::query()->count());
        self::assertSame(0, ErpCompanyMembership::query()->count());
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->status);
        self::assertSame('reserved', OfficeErpLicenceAllocation::query()->where('contract_id', $issued['contract_id'])->value('state'));
    }

    public function test_late_acceptance_after_seller_right_loss_has_no_partial_owner_or_allocation_effects(): void
    {
        $issued = $this->issueInvitation();
        $this->resolve($issued['token']);
        $subscription = $issued['context']->account->subscription()->firstOrFail();
        $plan = $subscription->plan()->firstOrFail();
        $allowance = $plan->erp_commercial;
        $allowance['enabled'] = false;
        $plan->forceFill(['erp_commercial' => $allowance])->save();
        $companiesBefore = ErpCompany::query()->count();
        $membersBefore = ErpCompanyMembership::query()->count();
        $linksBefore = OfficeErpContract::query()->whereNotNull('erp_company_id')->count();
        $occupiedBefore = OfficeErpLicenceAllocation::query()->where('state', 'occupied')->count();

        $this->accept($this->operationKey(), ['password' => 'new-owner-secret'])->assertForbidden();

        $companiesAfter = ErpCompany::query()->count();
        $membersAfter = ErpCompanyMembership::query()->count();
        $linksAfter = OfficeErpContract::query()->whereNotNull('erp_company_id')->count();
        $occupiedAfter = OfficeErpLicenceAllocation::query()->where('state', 'occupied')->count();
        self::assertSame([$companiesBefore, $membersBefore, $linksBefore, 0], [
            $companiesAfter,
            $membersAfter,
            $linksAfter,
            $occupiedAfter - $occupiedBefore,
        ]);
        self::assertDatabaseMissing('users', ['email' => 'owner@example.test']);
    }

    public function test_existing_company_adoption_requires_direct_owner_and_matching_cnpj_without_changing_subscription(): void
    {
        $issued = $this->issueInvitation();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $client = $issued['client'];
        $company = ErpCompany::factory()->create(['cnpj' => $client->tax_id]);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'office_erp_contract_id' => null,
            'status' => 'active',
        ]);
        $originalSubscriptionAttributes = $subscription->getAttributes();
        ksort($originalSubscriptionAttributes);
        $this->resolve($issued['token']);
        $this->login($owner);

        $this->accept($this->operationKey(), ['company_id' => $company->id])
            ->assertCreated()
            ->assertJsonPath('data.company.id', $company->id);

        $currentSubscriptionAttributes = $subscription->fresh()->getAttributes();
        ksort($currentSubscriptionAttributes);
        self::assertSame($originalSubscriptionAttributes, $currentSubscriptionAttributes);
        self::assertSame($company->id, OfficeErpContract::query()->findOrFail($issued['contract_id'])->erp_company_id);
    }

    public function test_cnpj_match_never_adopts_an_existing_company_without_explicit_owner_choice(): void
    {
        $issued = $this->issueInvitation();
        $company = ErpCompany::factory()->create(['cnpj' => $issued['client']->tax_id]);
        $this->resolve($issued['token']);
        $countsBefore = [
            User::query()->count(),
            ErpCompany::query()->count(),
            ErpCompanyMembership::query()->count(),
            ErpEstablishment::query()->count(),
            ErpSubscription::query()->count(),
            OfficeErpCommercialOperation::query()->count(),
            OfficeErpAuditEvent::query()->count(),
        ];

        $this->accept($this->operationKey(), ['password' => 'new-owner-secret'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'cnpj_company_conflict');

        self::assertSame($countsBefore, [
            User::query()->count(),
            ErpCompany::query()->count(),
            ErpCompanyMembership::query()->count(),
            ErpEstablishment::query()->count(),
            ErpSubscription::query()->count(),
            OfficeErpCommercialOperation::query()->count(),
            OfficeErpAuditEvent::query()->count(),
        ]);
        self::assertSame(1, ErpCompany::query()->where('cnpj', $issued['client']->tax_id)->count());
        self::assertDatabaseMissing('users', ['email' => 'owner@example.test']);
        self::assertNull(OfficeErpContract::query()->findOrFail($issued['contract_id'])->erp_company_id);
        self::assertSame('reserved', OfficeErpLicenceAllocation::query()->where('contract_id', $issued['contract_id'])->value('state'));
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->status);
    }

    public function test_unrelated_company_provisioning_unique_violation_is_propagated(): void
    {
        $issued = $this->issueInvitation();
        $this->resolve($issued['token']);
        $pdoException = new \PDOException('Unrelated membership unique violation');
        $pdoException->errorInfo = [
            '23000',
            19,
            'UNIQUE constraint failed: erp_company_memberships.erp_company_id, erp_company_memberships.user_id',
        ];
        $exception = new UniqueConstraintViolationException('sqlite', 'insert into erp_company_memberships', [], $pdoException);
        $this->mock(ErpCompanyProvisioning::class)
            ->shouldReceive('createCompanyForOwner')
            ->once()
            ->andThrow($exception);
        $this->withoutExceptionHandling();

        try {
            $this->accept($this->operationKey(), ['password' => 'new-owner-secret']);
            self::fail('An unrelated unique constraint violation must propagate.');
        } catch (UniqueConstraintViolationException $caught) {
            self::assertSame($exception, $caught);
        }
    }

    public function test_explicit_adoption_rejects_a_directly_owned_company_with_a_different_cnpj(): void
    {
        $issued = $this->issueInvitation();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $company = ErpCompany::factory()->create();
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $this->resolve($issued['token']);
        $this->login($owner);

        $this->accept($this->operationKey(), ['company_id' => $company->id])
            ->assertUnprocessable();

        self::assertNull(OfficeErpContract::query()->findOrFail($issued['contract_id'])->erp_company_id);
        self::assertSame('reserved', OfficeErpLicenceAllocation::query()->where('contract_id', $issued['contract_id'])->value('state'));
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->status);
    }

    public function test_resolve_requires_ingress_origin_and_csrf_guards(): void
    {
        $issued = $this->issueInvitation();
        $csrf = $this->csrf();

        $this->requestFromIsolatedIngress()->withHeader('Origin', 'https://attacker.example')
            ->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp-invitations/resolve', ['token' => $issued['token']])
            ->assertForbidden();
        $this->requestFromIsolatedIngress()->withHeader('Origin', 'http://localhost:3000')
            ->withHeader('X-CSRF-Token', '')
            ->postJson('/api/v1/erp-invitations/resolve', ['token' => $issued['token']])
            ->assertForbidden();
        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp-invitations/resolve', ['token' => $issued['token']])
            ->assertOk();
    }

    public function test_expired_server_continuation_is_bounded_after_invitation_resolution(): void
    {
        CarbonImmutable::setTestNow('2026-10-25 12:00:00');
        $issued = $this->issueInvitation();
        $this->resolve($issued['token']);
        $key = $this->operationKey();
        CarbonImmutable::setTestNow(now()->addMinutes(16));

        $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->assertOk()->assertJsonPath('state', 'expired');
        $this->accept($key, ['password' => 'new-owner-secret'])->assertGone();
        self::assertSame(0, ErpCompany::query()->count());
    }

    /** @return array{context: OfficeErpExecutionContext, client: Client, invitation_id: int, contract_id: int, token: string} */
    private function issueInvitation(): array
    {
        $context = $this->officeContext(['enabled' => true, 'licence_limit' => 3, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($context);
        $revision = $this->publishedRevision($context, ['price' => '321.98', 'interval_months' => 1, 'due_in_days' => 7]);
        $result = $this->asContext($context, fn (): array => app(OfficeErpOwnerInvitations::class)->issue(
            $context,
            $client->id,
            $revision->id,
            ['full_name' => 'New Owner', 'email' => 'owner@example.test'],
            'owner-acceptance-invitation-'.bin2hex(random_bytes(4)),
        ));
        $token = substr($result['url'], strpos($result['url'], '#token=') + strlen('#token='));
        $invitation = OfficeErpOwnerInvitation::query()->findOrFail($result['invitation_id']);

        return [
            'context' => $context,
            'client' => $client,
            'invitation_id' => (int) $invitation->id,
            'contract_id' => (int) $invitation->contract_id,
            'token' => $token,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function publishedRevision(OfficeErpExecutionContext $context, array $overrides = []): OfficeErpSalesPlanRevision
    {
        $plan = OfficeErpSalesPlan::factory()->create([
            'account_id' => $context->accountId,
            'name' => 'ERP para varejo',
            'status' => 'active',
        ]);
        $revision = OfficeErpSalesPlanRevision::factory()->create(array_replace([
            'account_id' => $context->accountId,
            'sales_plan_id' => $plan->id,
            'name' => 'ERP para varejo',
            'price' => '100.00',
            'interval_months' => 1,
            'due_in_days' => 7,
            'module_keys' => ['purchases'],
            'published_at' => now(),
        ], $overrides));
        $plan->forceFill(['current_revision_id' => $revision->id])->save();

        return $revision;
    }

    private function asContext(OfficeErpExecutionContext $context, Closure $callback): mixed
    {
        return $this->withOfficeAccount($context, fn (): mixed => $callback());
    }

    private function resolve(string $token): void
    {
        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp-invitations/resolve', ['token' => $token])
            ->assertOk();
    }

    private function accept(string $key, array $input = []): TestResponse
    {
        return $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $key])
            ->postJson('/api/v1/erp-invitations/accept', $input);
    }

    private function operationKey(): string
    {
        return (string) $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->assertOk()->json('operation_key');
    }

    private function login(User $user, string $password = 'password'): void
    {
        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $user->email, 'password' => $password])
            ->assertOk();
    }

    private function csrf(): string
    {
        return $this->requestFromIsolatedIngress()->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    public function test_resolve_route_throttle_still_rejects_the_eleventh_request_from_one_ingress_ip(): void
    {
        $issued = $this->issueInvitation();
        $csrf = $this->csrf();

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $csrf)
                ->postJson('/api/v1/erp-invitations/resolve', ['token' => $issued['token']])
                ->assertOk();
        }

        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $csrf)
            ->postJson('/api/v1/erp-invitations/resolve', ['token' => $issued['token']])
            ->assertTooManyRequests();
    }

    private function requestFromIsolatedIngress(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $this->testClientIp])
            ->withHeader('X-Opmoni-Client-IP', $this->testClientIp);
    }
}
