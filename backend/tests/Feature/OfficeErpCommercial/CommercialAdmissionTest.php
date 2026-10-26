<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Models\Erp\ErpSubscriptionTrial;
use App\Models\Subscription;
use App\Models\User;
use App\Tenant\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class CommercialAdmissionTest extends OfficeErpCommercialTestCase
{
    public function test_real_admin_and_context_assertion(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $admin = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $admin->id, 'role' => 'admin']);
        $this->selectBusinessAccount($admin, (int) $account->id);
        $this->actingAs($admin);

        $contextResponse = $this->getJson('/api/v1/erp-commercial/context')
            ->assertOk()
            ->assertJsonPath('data.can_read', true)
            ->assertJsonPath('data.can_configure', true)
            ->assertJsonPath('data.can_provision', false)
            ->assertJsonPath('data.can_start_trial', false)
            ->assertJsonPath('data.can_manage_trial_link', true)
            ->assertJsonPath('data.allowance.enabled', false)
            ->assertJsonPath('data.usage.reserved', 0)
            ->assertJsonPath('data.usage.occupied', 0)
            ->assertJsonPath('data.reasons', [
                'commercial_resale_disabled' => ['commercial_resale_disabled'],
                'licence_capacity_reached' => ['licence_capacity_reached'],
            ]);
        self::assertStringContainsString('no-store', (string) $contextResponse->headers->get('Cache-Control'));
        self::assertSame(64, strlen((string) $contextResponse->json('data.capability_key')));
        self::assertContains('owner.accept', $contextResponse->json('data.action_classification.owner_only'));
        self::assertContains('customer.enroll', $contextResponse->json('data.action_classification.new_sale'));
        self::assertContains('owner.setup.issue', $contextResponse->json('data.action_classification.maintenance'));
        self::assertContains('trial.register', $contextResponse->json('data.action_classification.trial_registration'));
        self::assertContains('trial.expire', $contextResponse->json('data.action_classification.system_only'));

        $this->withHeader('X-Account-Id', (string) ($account->id + 100))
            ->getJson('/api/v1/erp-commercial/context')
            ->assertConflict()
            ->assertJsonPath('code', 'account_context_changed');

        $operator = User::factory()->create();
        AccountMembership::factory()->create(['account_id' => $account->id, 'user_id' => $operator->id, 'role' => 'operador']);
        $this->selectBusinessAccount($operator, (int) $account->id);
        $this->actingAs($operator);
        $this->withHeader('X-Account-Id', (string) $account->id);
        $this->getJson('/api/v1/erp-commercial/context')->assertForbidden();

        $superAdmin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($superAdmin);
        $this->getJson('/api/v1/erp-commercial/context')->assertForbidden();
    }

    public function test_eligible_context_serializes_empty_reasons_as_a_json_object(): void
    {
        config()->set('erp.office_trial_enabled', true);
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        $response = $this->getJson('/api/v1/erp-commercial/context')->assertOk();
        $payload = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($payload->data->can_configure);
        self::assertTrue($payload->data->can_sell_new);
        self::assertTrue($payload->data->can_provision);
        self::assertTrue($payload->data->can_start_trial);
        self::assertTrue($payload->data->can_manage_trial_link);
        self::assertSame([], $payload->data->capability_reasons->can_provision);
        self::assertSame([], $payload->data->capability_reasons->can_start_trial);
        self::assertInstanceOf(\stdClass::class, $payload->data->reasons);
        self::assertSame([], get_object_vars($payload->data->reasons));
        self::assertSame(['reserved' => 0, 'occupied' => 0], get_object_vars($payload->data->usage));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_provisioning_is_independent_of_retail_resale_and_requires_active_subscription_capacity_and_modules(): void
    {
        config()->set('erp.office_trial_enabled', true);
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        $this->getJson('/api/v1/erp-commercial/context')
            ->assertOk()
            ->assertJsonPath('data.can_configure', true)
            ->assertJsonPath('data.can_provision', true)
            ->assertJsonPath('data.can_start_trial', true)
            ->assertJsonPath('data.can_manage_trial_link', true)
            ->assertJsonPath('data.can_sell_new', false);

        Subscription::query()->where('account_id', $ctx->accountId)->update(['status' => 'canceled']);
        $this->getJson('/api/v1/erp-commercial/context')
            ->assertOk()
            ->assertJsonPath('data.can_configure', true)
            ->assertJsonPath('data.can_provision', false)
            ->assertJsonPath('data.can_sell_new', false);
    }

    public function test_trial_release_gate_is_separate_from_trial_link_configuration_and_general_provisioning(): void
    {
        config()->set('erp.office_trial_enabled', false);
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        $this->getJson('/api/v1/erp-commercial/context')
            ->assertOk()
            ->assertJsonPath('data.can_provision', true)
            ->assertJsonPath('data.can_start_trial', false)
            ->assertJsonPath('data.can_manage_trial_link', true)
            ->assertJsonPath('data.capability_reasons.can_start_trial', ['trial_registration_disabled'])
            ->assertJsonPath('data.capability_reasons.can_manage_trial_link', []);
    }

    public function test_zero_capacity_blocks_provisioning_while_null_capacity_is_unlimited(): void
    {
        $zero = $this->officeContext(['enabled' => false, 'licence_limit' => 0, 'module_keys' => ['purchases']]);
        $this->selectBusinessAccount($zero->actor, $zero->accountId);
        $this->actingAs($zero->actor);
        $this->getJson('/api/v1/erp-commercial/context')
            ->assertOk()
            ->assertJsonPath('data.can_configure', true)
            ->assertJsonPath('data.can_provision', false);

        $unlimited = $this->officeContext(['enabled' => false, 'licence_limit' => null, 'module_keys' => ['purchases']]);
        $this->selectBusinessAccount($unlimited->actor, $unlimited->accountId);
        $this->actingAs($unlimited->actor);
        $this->getJson('/api/v1/erp-commercial/context')
            ->assertOk()
            ->assertJsonPath('data.can_provision', true)
            ->assertJsonPath('data.can_sell_new', false);
    }

    public function test_trial_registration_context_is_purpose_bound_and_cannot_reuse_invitation_owner_authority(): void
    {
        config()->set('erp.office_trial_enabled', true);
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $trialContext = OfficeErpExecutionContext::trialRegistration(
            $ctx->account,
            User::factory()->create(),
            'verified-link-revision:'.$ctx->accountId.':1:owner:123',
        );
        self::assertSame('owner', $trialContext->actorKind);
        self::assertSame('trial.register', $trialContext->purpose);
        self::assertSame(
            $trialContext->actorIdentity,
            OfficeErpExecutionContext::trialRegistration(
                $ctx->account,
                $trialContext->actor,
                'verified-link-revision:'.$ctx->accountId.':1:owner:123',
            )->actorIdentity,
        );

        $admission = app(OfficeErpAdmission::class);
        $trialContext->run(fn () => $admission->assertCommand($trialContext, 'trial.register', modules: ['purchases']));

        $invitationContext = OfficeErpExecutionContext::owner(
            $ctx->account,
            $ctx->actor,
            'commercial.owner.accept',
            'verified-invitation:'.$ctx->accountId,
        );
        try {
            $invitationContext->run(fn () => $admission->assertCommand($invitationContext, 'trial.register', modules: ['purchases']));
            self::fail('An invitation owner context cannot register a trial.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        try {
            OfficeErpExecutionContext::owner(
                $ctx->account,
                $ctx->actor,
                'trial.register',
                'unverified-trial-registration',
            );
            self::fail('A normal owner context cannot claim trial-registration purpose.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_trial_registration_purpose_cannot_accept_reject_or_release_owner_reservations(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($ctx);
        $contract->forceFill(['agreement_status' => 'awaiting_owner'])->save();
        $allocation = OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'state' => 'reserved',
        ]);
        $trialContext = OfficeErpExecutionContext::trialRegistration(
            $ctx->account,
            User::factory()->create(),
            'verified-link-revision:'.$ctx->accountId.':1:owner:456',
        );
        $admission = app(OfficeErpAdmission::class);

        foreach (['owner.accept', 'owner.reject'] as $action) {
            try {
                $trialContext->run(fn () => $admission->assertCommand($trialContext, $action, $contract, ['purchases'], $allocation));
                self::fail("A trial registration context cannot perform {$action}.");
            } catch (HttpException $exception) {
                self::assertSame(403, $exception->getStatusCode());
            }
        }

        try {
            $admission->assertReleaseAuthority($trialContext, 'owner_rejected', $contract, $allocation);
            self::fail('A trial registration context cannot release an owner reservation.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_unexpired_trial_conversion_keeps_its_occupied_seat_after_quota_downgrade_to_zero(): void
    {
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 0, 'module_keys' => ['purchases']]);
        [$contract] = $this->createOccupiedTrial($ctx);
        $admission = app(OfficeErpAdmission::class);

        $this->withOfficeAccount($ctx, fn () => $ctx->run(
            fn () => $admission->assertCommand($ctx, 'trial.convert', $contract, ['purchases']),
        ));

        self::assertSame('trialing', ErpSubscription::query()->where('office_erp_contract_id', $contract->id)->value('status'));
        self::assertSame('occupied', OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->value('state'));
    }

    public function test_unexpired_trial_conversion_keeps_its_seat_when_quota_is_below_current_usage(): void
    {
        $ctx = $this->officeContext(['enabled' => false, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        [$contract] = $this->createOccupiedTrial($ctx);
        $this->createOccupiedTrial($ctx);
        $admission = app(OfficeErpAdmission::class);

        $this->withOfficeAccount($ctx, fn () => $ctx->run(
            fn () => $admission->assertCommand($ctx, 'trial.convert', $contract, ['purchases']),
        ));

        self::assertSame('trialing', ErpSubscription::query()->where('office_erp_contract_id', $contract->id)->value('status'));
        self::assertSame('occupied', OfficeErpLicenceAllocation::query()->where('contract_id', $contract->id)->value('state'));
    }

    public function test_right_loss_preserves_only_accepted_maintenance(): void
    {
        $allowance = ['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']];
        $ctx = $this->officeContext($allowance);
        $contract = $this->acceptedContract($ctx);
        $admission = app(OfficeErpAdmission::class);

        $this->withOfficeAccount($ctx, function () use ($admission, $ctx, $contract): void {
            $ctx->run(function () use ($admission, $ctx, $contract): void {
                $capabilities = $admission->capabilities($ctx);
                self::assertTrue($capabilities['can_maintain']);
                self::assertTrue($capabilities['can_sell_new']);

                $account = $ctx->account->fresh();
                $account->subscription->plan->forceFill([
                    'erp_commercial' => ['enabled' => false, 'licence_limit' => 0, 'module_keys' => []],
                ])->save();

                $capabilitiesAfterLoss = $admission->capabilities($ctx);
                self::assertTrue($capabilitiesAfterLoss['can_maintain']);
                self::assertFalse($capabilitiesAfterLoss['can_sell_new']);
                self::assertNotSame($capabilities['capability_key'], $capabilitiesAfterLoss['capability_key']);
                self::assertSame([
                    'commercial_resale_disabled' => ['commercial_resale_disabled'],
                    'licence_capacity_reached' => ['licence_capacity_reached'],
                ], $capabilitiesAfterLoss['reasons']);
                Subscription::query()->where('account_id', $ctx->accountId)->update(['status' => 'canceled']);
                $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
                $this->actingAs($ctx->actor);
                $before = $contract->fresh()->getAttributes();
                $admission->assertCommand($ctx, 'contract.renew', $contract->fresh());
                $this->postJson('/api/v1/clients', ['name' => 'Cliente bloqueado'])
                    ->assertForbidden()
                    ->assertJsonPath('code', 'subscription_inactive');
                self::assertSame($before, $contract->fresh()->getAttributes());
                try {
                    $admission->assertCommand($ctx, 'contract.create');
                    self::fail('Resale loss must prevent new contracts.');
                } catch (HttpException $exception) {
                    self::assertSame(403, $exception->getStatusCode());
                }
            });
        });
    }

    public function test_inactive_subscription_does_not_bypass_normal_account_writes(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        Subscription::query()->where('account_id', $ctx->accountId)->update(['status' => 'canceled']);
        $this->selectBusinessAccount($ctx->actor, $ctx->accountId);
        $this->actingAs($ctx->actor);

        $this->postJson('/api/v1/clients', ['name' => 'Cliente bloqueado'])
            ->assertForbidden()
            ->assertJsonPath('code', 'subscription_inactive');
    }

    public function test_account_admin_can_archive_offer_without_any_contract(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $admission = app(OfficeErpAdmission::class);

        $capabilities = $this->withOfficeAccount($ctx, fn (): array => $ctx->run(fn (): array => $admission->capabilities($ctx)));
        self::assertTrue($capabilities['can_maintain']);
        $this->withOfficeAccount($ctx, fn () => $admission->assertCommand($ctx, 'offer.archive'));

        $foreign = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $foreignContract = $this->acceptedContract($foreign);
        try {
            $this->withOfficeAccount($ctx, fn () => $admission->assertCommand($ctx, 'contract.renew', $foreignContract));
            self::fail('An office administrator cannot maintain a foreign office contract.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_context_restoration_after_admission_exception(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $admission = app(OfficeErpAdmission::class);
        $tenant = app(CurrentTenant::class);
        $tenant->accountId = 987654;
        $beforeTenant = $tenant->accountId;

        try {
            $this->withOfficeAccount($ctx, fn () => $ctx->run(
                fn () => $admission->assertCommand($ctx, 'owner.accept'),
            ));
            self::fail('Expected an office administrator to be forbidden from an owner-only action.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        self::assertSame($beforeTenant, app(CurrentTenant::class)->accountId);
    }

    /** @return array{OfficeErpContract, OfficeErpLicenceAllocation} */
    private function createOccupiedTrial(OfficeErpExecutionContext $ctx): array
    {
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $contract->forceFill(['erp_company_id' => $company->id])->save();
        $subscription = ErpSubscription::factory()->create([
            'erp_company_id' => $company->id,
            'office_erp_contract_id' => $contract->id,
            'status' => 'trialing',
        ]);
        ErpSubscriptionTrial::query()->create([
            'erp_subscription_id' => $subscription->id,
            'erp_company_id' => $company->id,
            'account_id' => $ctx->accountId,
            'cnpj' => $company->cnpj,
            'started_at' => now()->subHours(1),
            'ends_at' => now()->addDays(7),
            'link_revision' => 1,
        ]);
        $allocation = OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'erp_company_id' => $company->id,
            'state' => 'occupied',
        ]);

        return [$contract->fresh(), $allocation];
    }
}
