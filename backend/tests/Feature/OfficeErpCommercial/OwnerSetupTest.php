<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpInvoice;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\Commercial\OfficeErpOwnerInvitation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpSubscription;
use App\Models\OwnerActivation;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\OfficeErpCommercialTestCase;

class OwnerSetupTest extends OfficeErpCommercialTestCase
{
    public function test_verified_owner_setup_claims_only_an_unclaimed_company_without_financial_effects(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company, 'subscription' => $subscription] = $this->unclaimedCompany();
        $invoiceCount = OfficeErpInvoice::query()->count();
        $allocationCount = OfficeErpLicenceAllocation::query()->count();
        $subscriptionId = $subscription->id;

        $issueKey = 'owner-setup-issue-0001';
        $issuedResponse = $this->asOffice($context)->withHeaders([
            'X-CSRF-Token' => $this->csrf(),
            'Idempotency-Key' => $issueKey,
        ])->postJson('/api/v1/erp-commercial/contracts/'.$contract->id.'/owner-setup', [
            'owner' => ['full_name' => 'Maria da Silva', 'email' => 'owner@example.test'],
        ])->assertCreated();
        $issued = $issuedResponse->json('data');
        $replay = $this->asOffice($context)->withHeaders([
            'X-CSRF-Token' => $this->csrf(),
            'Idempotency-Key' => $issueKey,
        ])->postJson('/api/v1/erp-commercial/contracts/'.$contract->id.'/owner-setup', [
            'owner' => ['full_name' => 'Maria da Silva', 'email' => 'owner@example.test'],
        ])->assertOk()->assertJsonMissingPath('data.url');
        self::assertSame($issued['invitation_id'], $replay->json('data.invitation_id'));

        $invitation = OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id']);
        self::assertSame('access_setup', $invitation->purpose);
        self::assertSame('owner@example.test', $invitation->owner_email);
        self::assertSame('unclaimed', $company->fresh()->owner_setup_status);
        self::assertSame($subscriptionId, $company->fresh()->subscription->id);
        self::assertSame($invoiceCount, OfficeErpInvoice::query()->count());
        self::assertSame($allocationCount, OfficeErpLicenceAllocation::query()->count());
        $this->asOffice($context)->getJson('/api/v1/erp-commercial/operations/'.$issueKey, ['Idempotency-Key' => $issueKey])
            ->assertOk()->assertJsonMissingPath('data.url');

        Auth::logout();
        $token = substr($issued['url'], strpos($issued['url'], '#token=') + strlen('#token='));
        $this->resolve($token)->assertJsonPath('state', 'valid');
        $operationKey = (string) $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->json('operation_key');
        $accepted = $this->requestFromIsolatedIngress()->withHeaders([
            'X-CSRF-Token' => $this->csrf(),
            'Idempotency-Key' => $operationKey,
        ])->postJson('/api/v1/erp-invitations/accept', ['password' => 'owner-password-123']);

        $accepted->assertCreated()->assertJsonPath('data.company.id', $company->id);
        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $owner->id)->where('role', 'owner')->count());
        self::assertSame('claimed', $company->fresh()->owner_setup_status);
        self::assertSame($subscriptionId, $company->fresh()->subscription->id);
        self::assertSame($invoiceCount, OfficeErpInvoice::query()->count());
        self::assertSame($allocationCount, OfficeErpLicenceAllocation::query()->count());
        $officeSubscription = $context->account->subscription()->firstOrFail();
        $officeSubscription->forceFill(['status' => 'canceled'])->save();
        $before = [OfficeErpInvoice::query()->count(), OfficeErpLicenceAllocation::query()->count(), ErpCompanyMembership::query()->count()];
        $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $operationKey])
            ->postJson('/api/v1/erp-invitations/accept', [])->assertOk()->assertJsonPath('data.owner_setup_status', 'claimed');
        self::assertSame($before, [OfficeErpInvoice::query()->count(), OfficeErpLicenceAllocation::query()->count(), ErpCompanyMembership::query()->count()]);
    }

    public function test_existing_owner_identity_keeps_its_password_and_office_memberships(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company, 'subscription' => $subscription] = $this->unclaimedCompany();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        $officeMemberships = AccountMembership::query()->where('user_id', $owner->id)->count();
        $password = $owner->password;

        $issued = $this->issueSetup($context, $contract, 'owner.setup.existing-user');
        Auth::logout();
        $this->resolve($this->token($issued))->assertJsonPath('purpose', 'access_setup');
        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $owner->email, 'password' => 'password'])->assertOk();
        $operationKey = (string) $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->json('operation_key');

        $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $operationKey])
            ->postJson('/api/v1/erp-invitations/accept', [])
            ->assertCreated()->assertJsonPath('data.owner_setup_status', 'claimed');

        self::assertSame($password, $owner->fresh()->password);
        self::assertSame($officeMemberships, AccountMembership::query()->where('user_id', $owner->id)->count());
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('user_id', $owner->id)->where('role', 'owner')->count());
        self::assertSame($subscription->id, $company->fresh()->subscription->id);
    }

    public function test_foreign_actor_cannot_replay_a_consumed_setup_continuation(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company] = $this->unclaimedCompany();
        $issued = $this->issueSetup($context, $contract, 'owner.setup.foreign-replay');
        Auth::logout();
        $this->resolve($this->token($issued))->assertOk();
        $operationKey = (string) $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->json('operation_key');
        $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $operationKey])
            ->postJson('/api/v1/erp-invitations/accept', ['password' => 'owner-password-123'])->assertCreated();
        $ownerId = ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('role', 'owner')->value('user_id');

        Auth::logout();
        $foreign = User::factory()->create(['email' => 'foreign@example.test']);
        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/login', ['email' => $foreign->email, 'password' => 'password'])->assertOk();
        $this->requestFromIsolatedIngress()->getJson('/api/v1/erp-invitations/current')->assertOk()->assertJsonPath('state', 'identity_mismatch');
        $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $operationKey])
            ->postJson('/api/v1/erp-invitations/accept', [])->assertForbidden();

        self::assertSame($ownerId, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('role', 'owner')->value('user_id'));
        self::assertSame('claimed', $company->fresh()->owner_setup_status);
    }

    public function test_expired_invitation_cannot_be_claimed_after_its_continuation_was_resolved(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company] = $this->unclaimedCompany();
        $issued = $this->issueSetup($context, $contract, 'owner.setup.expired-after-resolve');
        $csrf = $this->csrf();
        Auth::logout();
        $resolved = $this->resolve($this->token($issued));
        $resolved->assertJsonPath('state', 'valid');
        $operationKey = (string) $resolved->json('operation_key');
        OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])
            ->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $operationKey])
            ->postJson('/api/v1/erp-invitations/accept', ['password' => 'owner-password-123'])->assertGone();

        self::assertSame('unclaimed', $company->fresh()->owner_setup_status);
        self::assertSame(0, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->count());
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->status);
    }

    public function test_account_suspension_after_initial_read_is_rechecked_under_lock_before_claim(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company] = $this->unclaimedCompany();
        $issued = $this->issueSetup($context, $contract, 'owner.setup.suspend-during-accept');
        $csrf = $this->csrf();
        Auth::logout();
        $resolved = $this->resolve($this->token($issued));
        $resolved->assertJsonPath('state', 'valid');
        $operationKey = (string) $resolved->json('operation_key');
        Account::query()->findOrFail($context->accountId)->forceFill(['status' => 'suspended'])->save();

        $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $operationKey])
            ->postJson('/api/v1/erp-invitations/accept', ['password' => 'owner-password-123'])->assertGone();

        self::assertSame('suspended', Account::query()->findOrFail($context->accountId)->status);
        self::assertSame('unclaimed', $company->fresh()->owner_setup_status);
        self::assertSame(0, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->count());
    }

    public function test_pending_account_activation_reservation_is_preserved_until_intended_owner_activates(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company] = $this->unclaimedCompany();
        $issued = $this->issueSetup($context, $contract, 'owner.setup.pending-activation');
        $activationToken = bin2hex(random_bytes(32));
        $activation = OwnerActivation::query()->create([
            'account_id' => $context->accountId,
            'email' => 'owner@example.test',
            'owner_name' => 'Maria da Silva',
            'token_hash' => hash('sha256', $activationToken),
            'expires_at' => now()->addDays(2),
        ]);
        $csrf = $this->csrf();
        Auth::logout();
        $resolved = $this->resolve($this->token($issued));
        $operationKey = (string) $resolved->json('operation_key');

        $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $csrf, 'Idempotency-Key' => $operationKey])
            ->postJson('/api/v1/erp-invitations/accept', ['password' => 'owner-password-123'])
            ->assertConflict();

        self::assertDatabaseMissing('users', ['email' => 'owner@example.test']);
        self::assertSame('unclaimed', $company->fresh()->owner_setup_status);
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id'])->status);
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('action', 'owner.accept')->count());
        self::assertNull($activation->fresh()->consumed_at);

        $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/first-access', [
                'token' => $activationToken,
                'email' => 'owner@example.test',
                'password' => 'owner-password-123',
                'password_confirmation' => 'owner-password-123',
            ])->assertCreated();
        $resolvedAgain = $this->resolve($this->token($issued));
        $this->requestFromIsolatedIngress()->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => (string) $resolvedAgain->json('operation_key')])
            ->postJson('/api/v1/erp-invitations/accept', [])->assertCreated()->assertJsonPath('data.owner_setup_status', 'claimed');

        self::assertNotNull($activation->fresh()->consumed_at);
        self::assertSame('claimed', $company->fresh()->owner_setup_status);
        self::assertSame(1, ErpCompanyMembership::query()->where('erp_company_id', $company->id)->where('role', 'owner')->count());
    }

    public function test_legacy_signature_without_purpose_cannot_be_relabelled_as_access_setup(): void
    {
        ['context' => $context, 'contract' => $contract] = $this->unclaimedCompany();
        $issued = $this->issueSetup($context, $contract, 'owner.setup.purpose-tampering');
        $invitation = OfficeErpOwnerInvitation::query()->findOrFail($issued['invitation_id']);
        $identity = hash('sha256', $invitation->id.':'.mb_strtolower(trim($invitation->owner_email)));
        $continuation = [
            'invitation_id' => (int) $invitation->id,
            'account_id' => $context->accountId,
            'contract_id' => (int) $contract->id,
            'owner_email' => $invitation->owner_email,
            'owner_name' => $invitation->owner_name,
            'owner_identity' => $identity,
            'operation_key' => hash_hmac('sha256', 'office-erp-owner-access_setup:'.$invitation->id.':'.$identity, (string) config('app.key')),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ];
        $continuation['signature'] = hash_hmac('sha256', json_encode($continuation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));
        $continuation['purpose'] = 'access_setup';

        Auth::logout();
        $this->requestFromIsolatedIngress()->withSession(['office_erp.owner_continuation' => $continuation])
            ->getJson('/api/v1/erp-invitations/current')->assertOk()->assertJsonPath('state', 'missing');
    }

    public function test_already_claimed_company_cannot_receive_an_owner_setup_continuation(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company] = $this->unclaimedCompany();
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        ErpCompanyMembership::factory()->create(['erp_company_id' => $company->id, 'user_id' => $owner->id, 'role' => 'owner']);
        $company->forceFill(['owner_setup_status' => 'claimed'])->save();
        $before = OfficeErpOwnerInvitation::query()->count();

        $this->asOffice($context)->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'owner.setup.claimed-company'])
            ->postJson('/api/v1/erp-commercial/contracts/'.$contract->id.'/owner-setup', [
                'owner' => ['full_name' => 'Maria da Silva', 'email' => 'owner@example.test'],
            ])->assertConflict();

        self::assertSame($before, OfficeErpOwnerInvitation::query()->count());
        self::assertSame(0, OfficeErpCommercialOperation::query()->where('idempotency_key', 'owner.setup.claimed-company')->count());
    }

    public function test_setup_continuation_can_be_revoked_without_releasing_the_company_allocation(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company] = $this->unclaimedCompany();
        $issued = $this->issueSetup($context, $contract, 'owner.setup.revoke-issue');
        $allocation = OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->firstOrFail();

        $this->asOffice($context)->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => 'owner-setup-revoke-0001'])
            ->postJson('/api/v1/erp-commercial/invitations/'.$issued['invitation_id'].'/revoke', ['reason' => 'Owner requested a new setup link.'])
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        self::assertSame('occupied', $allocation->fresh()->state);
        self::assertSame((int) $company->id, (int) $allocation->fresh()->erp_company_id);
        self::assertSame('unclaimed', $company->fresh()->owner_setup_status);
    }

    public function test_explicit_new_setup_issue_revokes_the_previous_link_without_financial_effects(): void
    {
        ['context' => $context, 'contract' => $contract, 'company' => $company] = $this->unclaimedCompany();
        $first = $this->issueSetup($context, $contract, 'owner.setup.issue-first');
        $second = $this->issueSetup($context, $contract, 'owner.setup.issue-second');

        self::assertNotSame($first['invitation_id'], $second['invitation_id']);
        self::assertSame('revoked', OfficeErpOwnerInvitation::query()->findOrFail($first['invitation_id'])->status);
        self::assertSame('pending', OfficeErpOwnerInvitation::query()->findOrFail($second['invitation_id'])->status);
        self::assertSame('occupied', OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->value('state'));
        self::assertSame('unclaimed', $company->fresh()->owner_setup_status);
    }

    public function test_pre_purpose_signed_legacy_continuation_remains_valid(): void
    {
        $context = $this->officeContext();
        [$contract, $invitation] = $this->withOfficeAccount($context, function () use ($context): array {
            $contract = $this->acceptedContract($context);
            $invitation = OfficeErpOwnerInvitation::factory()->create([
                'account_id' => $context->accountId,
                'contract_id' => $contract->id,
                'owner_name' => 'Maria da Silva',
                'owner_email' => 'owner@example.test',
                'purpose' => 'commercial_acceptance',
                'status' => 'pending',
                'expires_at' => now()->addDays(7),
            ]);

            return [$contract, $invitation];
        });
        $identity = hash('sha256', $invitation->id.':'.mb_strtolower(trim($invitation->owner_email)));
        $continuation = [
            'invitation_id' => (int) $invitation->id,
            'account_id' => $context->accountId,
            'contract_id' => (int) $contract->id,
            'owner_email' => $invitation->owner_email,
            'owner_name' => $invitation->owner_name,
            'owner_identity' => $identity,
            'operation_key' => hash_hmac('sha256', 'office-erp-owner-acceptance:'.$invitation->id.':'.$identity, (string) config('app.key')),
            'expires_at' => now()->addMinutes(15)->timestamp,
        ];
        $continuation['signature'] = hash_hmac('sha256', json_encode($continuation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), (string) config('app.key'));

        $this->requestFromIsolatedIngress()->withSession(['office_erp.owner_continuation' => $continuation])
            ->getJson('/api/v1/erp-invitations/current')
            ->assertOk()->assertJsonPath('state', 'valid')->assertJsonPath('purpose', 'commercial_acceptance');
    }

    /** @return array{context: OfficeErpExecutionContext, contract: OfficeErpContract, company: ErpCompany, subscription: ErpSubscription} */
    private function unclaimedCompany(): array
    {
        $context = $this->officeContext(['enabled' => false, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract, $company, $subscription] = $this->withOfficeAccount($context, function () use ($context): array {
            $contract = $this->acceptedContract($context);
            $company = ErpCompany::factory()->create(['cnpj' => $contract->client->tax_id]);
            $company->forceFill(['owner_setup_status' => 'unclaimed'])->save();
            $contract->forceFill(['erp_company_id' => $company->id, 'agreement_status' => 'effective', 'provisioning_source' => 'administrative'])->save();
            $subscription = ErpSubscription::factory()->active()->create(['erp_company_id' => $company->id, 'office_erp_contract_id' => $contract->id]);
            OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $context->accountId,
                'contract_id' => $contract->id,
                'erp_company_id' => $company->id,
                'state' => 'occupied',
                'expires_at' => null,
            ]);

            return [$contract, $company, $subscription];
        });

        return compact('context', 'contract', 'company', 'subscription');
    }

    /** @return array<string, mixed> */
    private function issueSetup(OfficeErpExecutionContext $context, OfficeErpContract $contract, string $key): array
    {
        return $this->asOffice($context)->withHeaders(['X-CSRF-Token' => $this->csrf(), 'Idempotency-Key' => $key])
            ->postJson('/api/v1/erp-commercial/contracts/'.$contract->id.'/owner-setup', [
                'owner' => ['full_name' => 'Maria da Silva', 'email' => 'owner@example.test'],
            ])->assertCreated()->json('data');
    }

    /** @param array{url: string} $issued */
    private function token(array $issued): string
    {
        return substr($issued['url'], strpos($issued['url'], '#token=') + strlen('#token='));
    }

    private function resolve(string $token): TestResponse
    {
        return $this->requestFromIsolatedIngress()->withHeader('X-CSRF-Token', $this->csrf())
            ->postJson('/api/v1/erp-invitations/resolve', ['token' => $token]);
    }

    private function csrf(): string
    {
        return $this->requestFromIsolatedIngress()->getJson('/api/v1/csrf')->assertOk()->json('csrf_token');
    }

    private function requestFromIsolatedIngress(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.18.0.10'])
            ->withHeader('X-Opmoni-Client-IP', '198.18.0.10');
    }

    private function asOffice(OfficeErpExecutionContext $context): static
    {
        $this->selectBusinessAccount($context->actor, $context->accountId);
        $this->actingAs($context->actor);

        return $this->withHeader('X-Account-Id', (string) $context->accountId);
    }
}
