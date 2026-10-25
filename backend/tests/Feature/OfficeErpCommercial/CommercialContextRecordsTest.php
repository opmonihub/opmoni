<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpRecords;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Client;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Tenant\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class CommercialContextRecordsTest extends OfficeErpCommercialTestCase
{
    public function test_office_context_configures_existing_observer_subscription_for_allowance(): void
    {
        $allowance = ['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']];
        $request = request();
        $hadPrevious = $request->attributes->has(CurrentAccount::class);
        $previous = $request->attributes->get(CurrentAccount::class);

        $ctx = $this->officeContext($allowance);
        $subscriptions = Subscription::query()->where('account_id', $ctx->accountId)->get();

        $this->assertSame($hadPrevious, $request->attributes->has(CurrentAccount::class));
        if ($hadPrevious) {
            $this->assertSame($previous, $request->attributes->get(CurrentAccount::class));
        }
        $ctx->assertVerified();
        $this->assertSame('office_admin', $ctx->actorKind);
        $this->assertCount(1, $subscriptions);
        $this->assertSame('active', $subscriptions->sole()->status);
        $this->assertSame($allowance, $subscriptions->sole()->plan->erp_commercial);
    }

    public function test_office_admin_context_requires_selected_account_for_factory_and_constructor(): void
    {
        $ctx = $this->officeContext();

        $this->assertOfficeAdminCreationForbidden(fn (): OfficeErpExecutionContext => OfficeErpExecutionContext::officeAdmin(
            $ctx->account,
            $ctx->actor,
            'commercial.plan.create',
        ));
        $this->assertOfficeAdminCreationForbidden(fn (): OfficeErpExecutionContext => new OfficeErpExecutionContext(
            $ctx->account,
            $ctx->actor,
            'office_admin',
            'commercial.plan.create',
        ));
    }

    public function test_office_context_fixture_restores_previous_current_account_attribute(): void
    {
        $ctx = $this->officeContext();
        $membership = AccountMembership::query()
            ->where('account_id', $ctx->accountId)
            ->where('user_id', $ctx->actor?->getKey())
            ->firstOrFail();
        $previous = new CurrentAccount($ctx->account, $membership);
        $request = request();
        $request->attributes->set(CurrentAccount::class, $previous);

        try {
            $created = $this->officeContextFor($ctx->account);

            $this->assertSame('office_admin', $created->actorKind);
            $this->assertSame($previous, CurrentAccount::fromRequest());
        } finally {
            $request->attributes->remove(CurrentAccount::class);
        }
    }

    public function test_office_admin_context_rejects_a_different_selected_account(): void
    {
        $ctx = $this->officeContext();
        $selected = $this->officeContext();

        $this->withOfficeAccount($selected, function () use ($ctx): void {
            $this->assertOfficeAdminCreationForbidden(fn (): OfficeErpExecutionContext => OfficeErpExecutionContext::officeAdmin(
                $ctx->account,
                $ctx->actor,
                'commercial.plan.create',
            ));
            $this->assertOfficeAdminCreationForbidden(fn (): OfficeErpExecutionContext => new OfficeErpExecutionContext(
                $ctx->account,
                $ctx->actor,
                'office_admin',
                'commercial.plan.create',
            ));
        });
    }

    public function test_office_admin_context_rejects_selected_membership_for_a_different_actor(): void
    {
        $ctx = $this->officeContext();
        $otherActor = User::factory()->create();
        $membership = AccountMembership::factory()->create([
            'account_id' => $ctx->accountId,
            'user_id' => $otherActor->id,
            'role' => 'admin',
        ]);
        $selected = new CurrentAccount($ctx->account, $membership);
        $request = request();
        $hadPrevious = $request->attributes->has(CurrentAccount::class);
        $previous = $request->attributes->get(CurrentAccount::class);
        $request->attributes->set(CurrentAccount::class, $selected);

        try {
            $this->assertOfficeAdminCreationForbidden(fn (): OfficeErpExecutionContext => OfficeErpExecutionContext::officeAdmin(
                $ctx->account,
                $ctx->actor,
                'commercial.plan.create',
            ));
        } finally {
            if ($hadPrevious) {
                $request->attributes->set(CurrentAccount::class, $previous);
            } else {
                $request->attributes->remove(CurrentAccount::class);
            }
        }
    }

    public function test_office_admin_context_requires_real_admin_membership(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $actor = User::factory()->create();
        $membership = AccountMembership::factory()->create([
            'account_id' => $account->id,
            'user_id' => $actor->id,
            'role' => 'operador',
        ]);

        $this->expectException(HttpException::class);

        $this->withSelectedOffice($account, $membership, fn (): OfficeErpExecutionContext => OfficeErpExecutionContext::officeAdmin(
            $account,
            $actor,
            'commercial.plan.create',
        ));
    }

    public function test_office_admin_context_requires_database_account_to_remain_active(): void
    {
        $ctx = $this->officeContext();
        DB::table('accounts')->where('id', $ctx->accountId)->update(['status' => 'suspended']);

        $this->withOfficeAccount($ctx, fn () => $this->assertOfficeAdminCreationForbidden(
            fn (): OfficeErpExecutionContext => OfficeErpExecutionContext::officeAdmin(
                $ctx->account,
                $ctx->actor,
                'commercial.plan.create',
            ),
        ));
    }

    public function test_owner_identity_is_stable_across_identity_creation_and_system_context_binds_resource(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $invitationIdentity = 'invitation:'.str_repeat('a', 40);
        $anonymousOwner = OfficeErpExecutionContext::owner(
            $account,
            null,
            'commercial.owner.accept',
            $invitationIdentity,
        );
        $owner = User::factory()->create();
        $authenticatedOwner = OfficeErpExecutionContext::owner(
            $account,
            $owner,
            'commercial.owner.accept',
            $invitationIdentity,
        );
        $system = OfficeErpExecutionContext::system($account, 'contract:42:billing-period:3');
        $otherSystemResource = OfficeErpExecutionContext::system($account, 'contract:43:billing-period:3');

        $this->assertSame('owner', $anonymousOwner->actorKind);
        $this->assertSame($anonymousOwner->actorIdentity, $authenticatedOwner->actorIdentity);
        $this->assertStringNotContainsString($invitationIdentity, $authenticatedOwner->actorIdentity);
        $this->assertNotSame($anonymousOwner->actorIdentity, OfficeErpExecutionContext::owner(
            $account,
            null,
            'commercial.owner.accept',
            'another:'.str_repeat('c', 40),
        )->actorIdentity);
        $this->assertSame('system', $system->actorKind);
        $this->assertNotSame($system->actorIdentity, $otherSystemResource->actorIdentity);
    }

    public function test_context_runner_restores_previous_tenant_after_exception_for_owner_and_system(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $tenant = app(CurrentTenant::class);
        $tenant->accountId = 987;
        $contexts = [
            OfficeErpExecutionContext::owner($account, null, 'commercial.owner.accept', 'invitation:'.str_repeat('b', 40)),
            OfficeErpExecutionContext::system($account, 'contract:51:renewal'),
        ];

        foreach ($contexts as $context) {
            try {
                $context->run(function () use ($context, $tenant): void {
                    $this->assertSame($context->accountId, $tenant->accountId);
                    throw new RuntimeException('fixture failure');
                });
                $this->fail('The fixture exception should escape the context runner.');
            } catch (RuntimeException $exception) {
                $this->assertSame('fixture failure', $exception->getMessage());
            }

            $this->assertSame(987, $tenant->accountId);
        }
    }

    public function test_system_context_rejects_inactive_account(): void
    {
        $account = Account::factory()->create(['status' => 'suspended']);

        $this->expectException(HttpException::class);

        OfficeErpExecutionContext::system($account, 'contract:9:renewal');
    }

    public function test_direct_owner_context_is_not_verified_without_invitation_factory(): void
    {
        $account = Account::factory()->create(['status' => 'active']);
        $context = new OfficeErpExecutionContext($account, null, 'owner', 'commercial.owner.accept');

        $this->expectException(HttpException::class);

        $context->assertVerified();
    }

    public function test_verified_actor_identity_cannot_be_reassigned(): void
    {
        $context = OfficeErpExecutionContext::system(
            Account::factory()->create(['status' => 'active']),
            'contract:73:renewal',
        );

        $this->expectException(LogicException::class);

        $context->actorIdentity = 'system:another-resource';
    }

    public function test_scoped_repository_reads_and_writes_real_commercial_models_only(): void
    {
        $ctx = $this->officeContext();
        $foreign = $this->officeContext();
        $records = app(OfficeErpRecords::class);
        $plan = $records->persist($ctx, new OfficeErpSalesPlan([
            'name' => 'Owned plan',
            'status' => 'active',
        ]));

        $this->assertSame($ctx->accountId, (int) $plan->account_id);
        $this->assertTrue($records->query($ctx, OfficeErpSalesPlan::class)->whereKey($plan->id)->exists());
        $this->assertFalse($records->query($foreign, OfficeErpSalesPlan::class)->whereKey($plan->id)->exists());

        try {
            $this->withOfficeAccount($ctx, fn (): int => $records->query($ctx, Client::class)->count());
            $this->fail('Legacy account-owned models must not enter the commercial repository.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }
    }

    public function test_scoped_repository_accepts_owned_updates_and_rejects_foreign_original_or_current_account(): void
    {
        $ctx = $this->officeContext();
        $foreign = $this->officeContext();
        $records = app(OfficeErpRecords::class);
        $ownedPlan = $records->persist($ctx, new OfficeErpSalesPlan([
            'name' => 'Before update',
            'status' => 'active',
        ]));
        $ownedPlan->name = 'After update';
        $records->persist($ctx, $ownedPlan);
        $this->assertSame('After update', $ownedPlan->refresh()->name);

        $ownedPlan->forceFill(['account_id' => $foreign->accountId]);
        try {
            $records->persist($ctx, $ownedPlan);
            $this->fail('A changed current Account must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $foreignPlan = $records->persist($foreign, new OfficeErpSalesPlan([
            'name' => 'Foreign plan',
            'status' => 'active',
        ]));
        $foreignPlan->forceFill(['account_id' => $ctx->accountId]);
        try {
            $records->persist($ctx, $foreignPlan);
            $this->fail('A foreign original Account must be rejected after current Account reassignment.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }

        $stalePlan = $records->persist($ctx, new OfficeErpSalesPlan([
            'name' => 'Stale ownership snapshot',
            'status' => 'active',
        ]));
        DB::table('office_erp_sales_plans')->where('id', $stalePlan->id)->update(['account_id' => $foreign->accountId]);
        try {
            $records->persist($ctx, $stalePlan);
            $this->fail('A database row moved to another Account must not be updated from a stale model.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_scoped_repository_updates_existing_commercial_operation_outcome(): void
    {
        $ctx = $this->officeContext();
        $records = app(OfficeErpRecords::class);
        $operation = OfficeErpCommercialOperation::factory()->create([
            'account_id' => $ctx->accountId,
            'outcome' => ['accepted' => false],
        ]);

        $this->assertTrue($records->query($ctx, OfficeErpCommercialOperation::class)
            ->whereKey($operation->id)
            ->exists());

        $operation->outcome = ['accepted' => true, 'contract_id' => 73];
        $records->persist($ctx, $operation);

        $this->assertSame(['accepted' => true, 'contract_id' => 73], $operation->fresh()->outcome);
    }

    public function test_scoped_repository_rejects_cross_account_contract_client_relationship(): void
    {
        $ctx = $this->officeContext();
        $foreign = $this->officeContext();
        $foreignClient = $this->clientFor($foreign);

        $this->expectException(QueryException::class);

        app(OfficeErpRecords::class)->persist($ctx, new OfficeErpContract([
            'client_id' => $foreignClient->id,
            'agreement_status' => 'draft',
            'billing_timezone' => 'America/Sao_Paulo',
        ]));
    }

    public function test_owner_and_system_record_persistence_restore_tenant_context_after_exception(): void
    {
        $ctx = $this->officeContext();
        $owner = OfficeErpExecutionContext::owner(
            $ctx->account,
            null,
            'commercial.owner.accept',
            'invitation:'.str_repeat('d', 40),
        );
        $system = $this->systemContext($ctx->account, 'contract:987:renewal');
        $tenant = app(CurrentTenant::class);
        $previousTenantId = $tenant->accountId;
        $records = app(OfficeErpRecords::class);

        foreach ([$owner, $system] as $context) {
            $seenTenant = null;
            OfficeErpSalesPlan::creating(function () use (&$seenTenant): void {
                $seenTenant = app(CurrentTenant::class)->accountId;
                throw new RuntimeException('Fixture failure during commercial record creation.');
            });

            try {
                $records->persist($context, new OfficeErpSalesPlan([
                    'name' => 'Interrupted plan',
                    'status' => 'active',
                ]));
                $this->fail('The model listener should abort persistence.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Fixture failure during commercial record creation.', $exception->getMessage());
                $this->assertSame($ctx->accountId, $seenTenant);
                $this->assertSame($previousTenantId, $tenant->accountId);
            } finally {
                OfficeErpSalesPlan::flushEventListeners();
            }
        }
    }

    public function test_accepted_contract_fixture_uses_current_account_and_known_commercial_module_key(): void
    {
        $ctx = $this->officeContext();
        $contract = $this->acceptedContract($ctx);
        $records = app(OfficeErpRecords::class);

        $clientAccountId = $this->withOfficeAccount($ctx, fn (): int => (int) $contract->client->account_id);

        $this->assertSame($ctx->accountId, $clientAccountId);
        $this->assertSame(['purchases'], $contract->currentRevision->module_keys);
        $this->assertTrue($records->query($ctx, OfficeErpContract::class)->whereKey($contract->id)->exists());

        $contract->manual_hold = true;
        $records->persist($ctx, $contract);

        $manualHold = $this->withOfficeAccount($ctx, fn (): bool => (bool) $contract->refresh()->manual_hold);

        $this->assertTrue($manualHold);
    }

    private function assertOfficeAdminCreationForbidden(callable $create): void
    {
        try {
            $create();
            $this->fail('A human office context must match the selected persisted admin membership.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
