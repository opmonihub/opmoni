<?php

namespace Tests\Feature\OfficeErpCommercial;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpLicenceAllocator;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\OfficeErpCommercialTestCase;

class LicenceAllocationTest extends OfficeErpCommercialTestCase
{
    public function test_own_reservation_does_not_require_a_second_free_slot(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($ctx);
        $allocator = app(OfficeErpLicenceAllocator::class);
        $admission = app(OfficeErpAdmission::class);

        $company = ErpCompany::factory()->create();
        [$allocation, $occupied] = $this->withOfficeAccount($ctx, fn (): array => $ctx->run(function () use ($ctx, $allocator, $contract, $company): array {
            $allocation = $allocator->reserve($ctx, $contract, CarbonImmutable::now()->addDay());
            $occupied = $allocator->occupy($ctx, $contract, $company);

            return [$allocation, $occupied];
        }));

        self::assertSame($allocation->id, $occupied->id);
        self::assertSame('occupied', $occupied->fresh()->state);
        $this->withOfficeAccount($ctx, function () use ($ctx, $admission): void {
            $capabilities = $ctx->run(fn (): array => $admission->capabilities($ctx));
            self::assertSame(0, $capabilities['usage']['reserved']);
            self::assertSame(1, $capabilities['usage']['occupied']);
        });
    }

    public function test_verified_owner_can_complete_only_the_dedicated_acceptance_action(): void
    {
        $office = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($office);
        $contract->forceFill(['agreement_status' => 'awaiting_owner'])->save();
        $owner = OfficeErpExecutionContext::owner($office->account, null, 'owner-accept:'.$contract->id, 'verified-invitation:'.$contract->id);
        $admission = app(OfficeErpAdmission::class);
        $allocator = app(OfficeErpLicenceAllocator::class);
        $company = ErpCompany::factory()->create();

        $owner->run(fn () => $admission->assertCommand($owner, 'owner.reject', $contract));
        $reservation = $owner->run(fn (): OfficeErpLicenceAllocation => $allocator->reserve($owner, $contract, CarbonImmutable::now()->addDay()));
        $occupied = $owner->run(fn (): OfficeErpLicenceAllocation => $allocator->occupy($owner, $contract, $company));

        self::assertSame($reservation->id, $occupied->id);
        self::assertSame('accepted', $contract->fresh()->agreement_status);
        try {
            $owner->run(fn () => $admission->assertCommand($owner, 'contract.create'));
            self::fail('Owner contexts must not gain office administration or sales authority.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_expired_reservation_is_released_before_capacity_is_reported(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($ctx);
        $allocation = OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $contract->id,
            'state' => 'reserved',
            'expires_at' => CarbonImmutable::now()->subSecond(),
        ]);
        $system = $this->systemContext($ctx->account, 'allocation-expiry:'.$contract->id);
        $system->run(fn () => app(OfficeErpAdmission::class)->assertCommand($system, 'allocation.expire', $contract, [], $allocation));
        try {
            $system->run(fn () => app(OfficeErpAdmission::class)->assertCommand($system, 'contract.renew', $contract));
            self::fail('System contexts must not acquire office maintenance authority.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }

        $capabilities = $this->withOfficeAccount($ctx, fn (): array => $ctx->run(
            fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx),
        ));

        self::assertSame(0, $capabilities['usage']['reserved']);
        self::assertSame('released', $allocation->fresh()->state);
    }

    public function test_expired_reservation_releases_last_slot_at_and_after_exact_expiry(): void
    {
        foreach ([0, 1] as $secondsAfterExpiry) {
            $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
            $expiredContract = $this->acceptedContract($ctx);
            $newContract = $this->acceptedContract($ctx);
            $expiresAt = CarbonImmutable::now()->addMinute()->startOfSecond();
            $allocation = OfficeErpLicenceAllocation::factory()->create([
                'account_id' => $ctx->accountId,
                'contract_id' => $expiredContract->id,
                'state' => 'reserved',
                'expires_at' => $expiresAt,
            ]);
            $system = $this->systemContext($ctx->account, 'allocation-boundary:'.$secondsAfterExpiry.':'.$expiredContract->id);
            $allocator = app(OfficeErpLicenceAllocator::class);
            $this->travelTo($expiresAt->addSeconds($secondsAfterExpiry));

            $system->run(fn () => $allocator->release($system, $expiredContract, 'invitation_expired'));

            self::assertSame('released', $allocation->fresh()->state);
            self::assertSame($expiresAt->addSeconds($secondsAfterExpiry)->toDateTimeString(), $allocation->fresh()->released_at->toDateTimeString());
            $reservation = $this->withOfficeAccount($ctx, fn (): OfficeErpLicenceAllocation => $ctx->run(
                fn (): OfficeErpLicenceAllocation => $allocator->reserve($ctx, $newContract, CarbonImmutable::now()->addDay()),
            ));
            self::assertSame($newContract->id, $reservation->contract_id);
            self::assertSame('reserved', $reservation->state);
            $this->travelBack();
        }
    }

    public function test_expired_reservation_releases_the_last_slot_for_another_contract(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $expiredContract = $this->acceptedContract($ctx);
        $allocation = OfficeErpLicenceAllocation::factory()->create([
            'account_id' => $ctx->accountId,
            'contract_id' => $expiredContract->id,
            'state' => 'reserved',
            'expires_at' => CarbonImmutable::now()->subSecond(),
        ]);
        $newContract = $this->acceptedContract($ctx);
        $allocator = app(OfficeErpLicenceAllocator::class);

        $reservation = $this->withOfficeAccount($ctx, fn (): OfficeErpLicenceAllocation => $ctx->run(
            fn (): OfficeErpLicenceAllocation => $allocator->reserve($ctx, $newContract, CarbonImmutable::now()->addDay()),
        ));

        self::assertSame('released', $allocation->fresh()->state);
        self::assertSame($newContract->id, $reservation->contract_id);
        self::assertSame('reserved', $reservation->state);
    }

    public function test_occupied_licence_is_released_only_after_effective_cancellation(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $contract = $this->acceptedContract($ctx);
        $company = ErpCompany::factory()->create();
        $allocator = app(OfficeErpLicenceAllocator::class);
        $allocation = $this->withOfficeAccount($ctx, fn (): OfficeErpLicenceAllocation => $ctx->run(function () use ($ctx, $allocator, $contract, $company): OfficeErpLicenceAllocation {
            $allocator->reserve($ctx, $contract, CarbonImmutable::now()->addDay());

            return $allocator->occupy($ctx, $contract, $company);
        }));

        try {
            $this->withOfficeAccount($ctx, fn () => $allocator->release($ctx, $contract, 'contract_canceled'));
            self::fail('An active occupied grant must remain allocated.');
        } catch (HttpException $exception) {
            self::assertSame(409, $exception->getStatusCode());
        }

        $contract->forceFill(['agreement_status' => 'canceled', 'cancel_effective_on' => CarbonImmutable::yesterday()->toDateString()])->save();
        $this->withOfficeAccount($ctx, fn () => $allocator->release($ctx, $contract->fresh(), 'effective_cancellation'));

        self::assertSame('released', $allocation->fresh()->state);
    }

    public function test_suspended_occupied_capacity_survives_downgrade_and_blocks_new_sales(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 2, 'module_keys' => ['purchases']]);
        $admission = app(OfficeErpAdmission::class);
        $allocator = app(OfficeErpLicenceAllocator::class);
        foreach ([1, 2] as $index) {
            $contract = $this->acceptedContract($ctx);
            $company = ErpCompany::factory()->create($index === 1 ? ['status' => 'suspended'] : []);
            $this->withOfficeAccount($ctx, fn () => $ctx->run(function () use ($ctx, $allocator, $contract, $company): void {
                $allocator->reserve($ctx, $contract, CarbonImmutable::now()->addDay());
                $allocator->occupy($ctx, $contract, $company);
            }));
        }

        $this->withOfficeAccount($ctx, function () use ($ctx, $admission): void {
            $ctx->account->subscription->plan->forceFill([
                'erp_commercial' => ['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']],
            ])->save();
            $capabilities = $ctx->run(fn (): array => $admission->capabilities($ctx));
            self::assertSame(['reserved' => 0, 'occupied' => 2], $capabilities['usage']);
            self::assertSame(0, $capabilities['available']);
            self::assertFalse($capabilities['can_sell_new']);
            self::assertSame(['licence_capacity_reached' => ['licence_capacity_reached']], $capabilities['reasons']);
            try {
                $admission->assertCommand($ctx, 'contract.create');
                self::fail('A lowered capacity must block new sales while occupied grants remain.');
            } catch (HttpException $exception) {
                self::assertSame(409, $exception->getStatusCode());
            }
        });
    }

    public function test_draft_contract_does_not_consume_a_licence(): void
    {
        $ctx = $this->officeContext(['enabled' => true, 'licence_limit' => 1, 'module_keys' => ['purchases']]);
        $client = $this->clientFor($ctx);
        $ctx->run(fn (): OfficeErpContract => OfficeErpContract::factory()->create([
            'account_id' => $ctx->accountId,
            'client_id' => $client->id,
            'agreement_status' => 'draft',
        ]));

        $capabilities = $this->withOfficeAccount($ctx, fn (): array => $ctx->run(
            fn (): array => app(OfficeErpAdmission::class)->capabilities($ctx),
        ));

        self::assertSame(0, $capabilities['usage']['reserved']);
        self::assertSame(0, $capabilities['usage']['occupied']);
    }
}
