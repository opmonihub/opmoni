<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Models\Account;
use App\Models\AccountMembership;
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
            ->assertJsonPath('data.can_configure', false)
            ->assertJsonPath('data.allowance.enabled', false)
            ->assertJsonPath('data.reserved', 0)
            ->assertJsonPath('data.occupied', 0);
        self::assertStringContainsString('no-store', (string) $contextResponse->headers->get('Cache-Control'));
        self::assertSame(64, strlen((string) $contextResponse->json('data.capability_key')));
        self::assertContains('owner.accept', $contextResponse->json('data.action_classification.owner_only'));

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
                self::assertContains('commercial_resale_disabled', $capabilitiesAfterLoss['reason_codes']);
                $before = $contract->fresh()->getAttributes();
                $admission->assertCommand($ctx, 'contract.renew', $contract->fresh());
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
}
