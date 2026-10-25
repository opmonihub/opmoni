<?php

namespace App\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class OfficeErpLicenceAllocator
{
    public function __construct(private readonly OfficeErpAdmission $admission) {}

    public function reserve(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, CarbonImmutable $expiresAt): OfficeErpLicenceAllocation
    {
        abort_unless($expiresAt->isFuture(), 422, 'invalid_reservation_expiry');
        $ctx->assertVerified();

        return $ctx->run(fn (): OfficeErpLicenceAllocation => DB::transaction(function () use ($ctx, $contract, $expiresAt): OfficeErpLicenceAllocation {
            $this->admission->lockCommercialAggregate($ctx);
            $candidate = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($contract->getKey())
                ->first();
            abort_unless($candidate, 404);
            $ownerAcceptance = $ctx->actorKind === 'owner';
            abort_unless(
                $ownerAcceptance
                    ? $candidate->agreement_status === 'awaiting_owner'
                    : in_array($candidate->agreement_status, ['awaiting_owner', 'accepted', 'effective'], true),
                409,
                'contract_not_accepted',
            );
            $existing = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->where('contract_id', $candidate->id)
                ->first();
            $action = $ownerAcceptance
                ? 'owner.accept'
                : ($candidate->agreement_status === 'awaiting_owner' ? 'invitation.issue' : 'licence.add');
            $this->admission->assertCommand($ctx, $action, $candidate, [], $existing);
            $lockedContract = $this->lockContract($ctx, $candidate);
            $existing = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->where('contract_id', $lockedContract->id)
                ->lockForUpdate()
                ->first();
            if ($existing?->state === 'reserved' && $existing->expires_at?->isFuture()) {
                return $existing;
            }
            abort_if($existing?->state === 'occupied', 409, 'licence_already_occupied');

            $allocation = $existing ?? new OfficeErpLicenceAllocation;
            $allocation->forceFill([
                'account_id' => $ctx->accountId,
                'contract_id' => $lockedContract->id,
                'erp_company_id' => null,
                'state' => 'reserved',
                'expires_at' => $expiresAt,
                'released_at' => null,
            ]);

            return OfficeErpRecords::persist($ctx, $allocation);
        }, 3));
    }

    public function occupy(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, ErpCompany $company): OfficeErpLicenceAllocation
    {
        $ctx->assertVerified();

        return $ctx->run(fn (): OfficeErpLicenceAllocation => DB::transaction(function () use ($ctx, $contract, $company): OfficeErpLicenceAllocation {
            $this->admission->lockCommercialAggregate($ctx);
            $candidate = OfficeErpRecords::query($ctx, OfficeErpContract::class)
                ->whereKey($contract->getKey())
                ->first();
            abort_unless($candidate, 404);
            $ownerAcceptance = $ctx->actorKind === 'owner';
            abort_unless(
                $ownerAcceptance
                    ? $candidate->agreement_status === 'awaiting_owner'
                    : in_array($candidate->agreement_status, ['accepted', 'effective'], true),
                409,
                'contract_not_accepted',
            );
            $this->admission->assertCommandForCompany($ctx, $ownerAcceptance ? 'owner.accept' : 'licence.add', $candidate, $company);
            $lockedCompany = ErpCompany::query()->whereKey($company->getKey())->lockForUpdate()->first();
            abort_unless($lockedCompany, 404);
            $lockedContract = $this->lockContract($ctx, $candidate);
            $allocation = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->where('contract_id', $lockedContract->id)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $allocation?->state === 'reserved'
                    && $allocation->expires_at !== null
                    && $allocation->expires_at->isFuture(),
                409,
                'licence_reservation_required',
            );

            $conflict = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                ->where('erp_company_id', $lockedCompany->id)
                ->whereIn('state', ['reserved', 'occupied'])
                ->whereKeyNot($allocation->id)
                ->exists();
            abort_if($conflict, 409, 'company_already_licensed');

            $allocation->forceFill([
                'state' => 'occupied',
                'erp_company_id' => $lockedCompany->id,
                'expires_at' => null,
                'released_at' => null,
            ]);
            $lockedContract->forceFill([
                'erp_company_id' => $lockedCompany->id,
                'agreement_status' => 'accepted',
                'accepted_at' => $lockedContract->accepted_at ?? now(),
            ]);
            OfficeErpRecords::persist($ctx, $lockedContract);

            return OfficeErpRecords::persist($ctx, $allocation);
        }, 3));
    }

    public function release(OfficeErpExecutionContext $ctx, OfficeErpContract $contract, string $reason): void
    {
        $ctx->assertVerified();
        abort_unless(in_array($reason, ['invitation_expired', 'invitation_revoked', 'owner_rejected', 'contract_canceled', 'effective_cancellation'], true), 422, 'invalid_release_reason');

        $ctx->run(function () use ($ctx, $contract, $reason): void {
            DB::transaction(function () use ($ctx, $contract, $reason): void {
                $this->admission->lockCommercialAggregate($ctx);
                $this->admission->prepareAdmissionLocks(
                    $ctx,
                    $contract,
                    null,
                    ! ($reason === 'invitation_expired' && $ctx->actorKind === 'system'),
                );
                $lockedContract = $this->lockContract($ctx, $contract);
                $allocation = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                    ->where('contract_id', $lockedContract->id)
                    ->lockForUpdate()
                    ->first();
                $this->admission->assertReleaseAuthority($ctx, $reason, $lockedContract, $allocation);
                if ($allocation === null || $allocation->state === 'released') {
                    return;
                }
                if ($allocation->state === 'occupied') {
                    abort_unless(
                        $reason === 'effective_cancellation'
                            && $lockedContract->agreement_status === 'canceled'
                            && $lockedContract->cancel_effective_on !== null
                            && $lockedContract->cancel_effective_on->isPast(),
                        409,
                        'occupied_licence_still_effective',
                    );
                }

                $allocation->forceFill(['state' => 'released', 'released_at' => now()]);
                OfficeErpRecords::persist($ctx, $allocation);
            }, 3);
        });
    }

    private function lockContract(OfficeErpExecutionContext $ctx, OfficeErpContract $contract): OfficeErpContract
    {
        $candidate = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($contract->getKey())
            ->first();
        abort_unless($candidate, 404);
        if ($candidate->erp_company_id !== null) {
            ErpCompany::query()->whereKey($candidate->erp_company_id)->lockForUpdate()->first();
        }

        return OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($candidate->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }
}
