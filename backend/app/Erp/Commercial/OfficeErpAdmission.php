<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\Erp\Commercial\OfficeErpContract;
use App\Models\Erp\Commercial\OfficeErpLicenceAllocation;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\AccessSession;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OfficeErpAdmission
{
    public const ACTION_CLASSIFICATION = [
        'maintenance' => [
            'contract.read', 'contract.activate', 'contract.renew', 'contract.amend.price', 'contract.suspend', 'contract.resume', 'contract.cancel',
            'invoice.read', 'invoice.issue', 'invoice.correct', 'receipt.read', 'receipt.record', 'receipt.reverse',
            'policy.read', 'policy.configure', 'offer.archive', 'report.read', 'invitation.revoke', 'owner.setup.issue',
        ],
        'new_sale' => [
            'contract.create', 'contract.send', 'invitation.issue', 'subscription.adopt', 'licence.add', 'modules.expand',
            'offer.create', 'offer.publish', 'customer.enroll', 'trial.link.configure', 'trial.link.rotate', 'trial.convert',
        ],
        'owner_only' => ['owner.accept', 'owner.reject'],
        'trial_registration' => ['trial.register'],
        'system_only' => ['billing.generate', 'allocation.expire', 'trial.expire'],
    ];

    public function context(Request $request): OfficeErpExecutionContext
    {
        $context = $request->attributes->get(OfficeErpExecutionContext::class);
        abort_unless($context instanceof OfficeErpExecutionContext, 403);

        return $context;
    }

    /** @return array<string, mixed> */
    public function capabilities(OfficeErpExecutionContext $ctx): array
    {
        $this->assertOfficeAdminContext($ctx);

        return $ctx->run(function () use ($ctx): array {
            return DB::transaction(function () use ($ctx): array {
                [$account, $subscription, $plan, $allowance] = $this->lockCommercialAggregate($ctx);
                $this->releaseExpiredReservations($ctx);

                $counts = $this->usage($ctx);
                $canRead = $account->status === 'active';
                $commercialEnabled = (bool) $allowance['enabled'];
                $subscriptionActive = $subscription?->status === 'active';
                $canMaintain = $canRead;
                $canConfigure = $canRead;
                $limit = $allowance['licence_limit'];
                $used = $counts['reserved'] + $counts['occupied'];
                $hasCapacity = $limit === null || $used < $limit;
                $canProvision = $canRead && $subscriptionActive && $hasCapacity && $allowance['module_keys'] !== [];
                $canManageTrialLink = $canRead && $subscriptionActive;
                $trialRegistrationEnabled = (bool) config('erp.office_trial_enabled', false);
                $canStartTrial = $canProvision && $trialRegistrationEnabled;
                $canSellNew = $canRead && $subscriptionActive && $commercialEnabled && $hasCapacity;

                $provisionReasons = [];
                if (! $subscriptionActive) {
                    $provisionReasons[] = 'subscription_inactive';
                }
                if (! $hasCapacity) {
                    $provisionReasons[] = 'licence_capacity_reached';
                }
                if ($allowance['module_keys'] === []) {
                    $provisionReasons[] = 'commercial_module_not_allowed';
                }
                $resaleReasons = $provisionReasons;
                if (! $commercialEnabled) {
                    $resaleReasons[] = 'commercial_resale_disabled';
                }
                $trialReasons = $provisionReasons;
                if (! $trialRegistrationEnabled) {
                    $trialReasons[] = 'trial_registration_disabled';
                }
                $linkReasons = $subscriptionActive ? [] : ['subscription_inactive'];
                $reasons = [];
                if (! $subscriptionActive) {
                    $reasons[] = 'subscription_inactive';
                }
                if (! $commercialEnabled) {
                    $reasons[] = 'commercial_resale_disabled';
                }
                if (! $hasCapacity) {
                    $reasons[] = 'licence_capacity_reached';
                }

                $identityAllowance = $allowance;
                sort($identityAllowance['module_keys'], SORT_STRING);
                $identity = [
                    'account_id' => (int) $account->id,
                    'account_status' => $account->status,
                    'actor_id' => (int) $ctx->actor?->getKey(),
                    'membership_role' => CurrentAccount::fromRequest()->membership->role,
                    'session_id' => hash('sha256', request()->hasSession()
                        ? request()->session()->getId()
                        : $ctx->actorIdentity.':'.$ctx->purpose),
                    'subscription_id' => (int) ($subscription?->id ?? 0),
                    'subscription_status' => $subscription?->status,
                    'plan_id' => (int) ($plan?->id ?? 0),
                    'allowance' => $identityAllowance,
                    'capabilities' => [$canRead, $canMaintain, $canConfigure, $canSellNew, $canProvision, $canStartTrial, $canManageTrialLink],
                ];

                return [
                    'can_read' => $canRead,
                    'can_maintain' => $canMaintain,
                    'can_configure' => $canConfigure,
                    'can_sell_new' => $canSellNew,
                    'can_provision' => $canProvision,
                    'can_start_trial' => $canStartTrial,
                    'can_manage_trial_link' => $canManageTrialLink,
                    'capability_reasons' => [
                        'can_configure' => [],
                        'can_sell_new' => array_values(array_unique($resaleReasons)),
                        'can_provision' => array_values(array_unique($provisionReasons)),
                        'can_start_trial' => array_values(array_unique($trialReasons)),
                        'can_manage_trial_link' => $linkReasons,
                    ],
                    'reasons' => array_combine(
                        array_values(array_unique($reasons)),
                        array_map(static fn (string $reason): array => [$reason], array_values(array_unique($reasons))),
                    ),
                    'allowance' => $allowance,
                    'usage' => ['reserved' => $counts['reserved'], 'occupied' => $counts['occupied']],
                    'available' => $limit === null ? null : max(0, $limit - $used),
                    'capability_key' => hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)),
                    'action_classification' => self::ACTION_CLASSIFICATION,
                ];
            }, 3);
        });
    }

    /** @param array<string> $modules */
    public function assertCommand(
        OfficeErpExecutionContext $ctx,
        string $action,
        ?OfficeErpContract $contract = null,
        array $modules = [],
        ?OfficeErpLicenceAllocation $reservation = null,
    ): void {
        $this->assertActionContext($ctx, $action);

        DB::transaction(function () use ($ctx, $action, $contract, $modules, $reservation): void {
            [$account, $subscription, $plan, $allowance] = $this->lockCommercialAggregate($ctx);
            abort_unless($account->status === 'active', 403);
            if (in_array($action, self::ACTION_CLASSIFICATION['system_only'], true)) {
                $this->prepareAdmissionLocks($ctx, $contract, null, false);
                [$lockedContract, $allocation] = $this->lockTarget($ctx, $contract, $reservation);
                abort_unless($lockedContract !== null, 404);
                if ($action === 'allocation.expire') {
                    abort_unless(
                        $allocation?->state === 'reserved'
                            && $allocation->expires_at !== null
                            && ! $allocation->expires_at->isFuture(),
                        409,
                        'allocation_not_expired',
                    );
                } else {
                    abort_unless(in_array($lockedContract->agreement_status, ['accepted', 'effective'], true), 409);
                }

                return;
            }
            $this->prepareAdmissionLocks($ctx, $contract);
            [$lockedContract, $allocation] = $this->lockTarget($ctx, $contract, $reservation);
            $this->assertCommandForLockedContext($ctx, $action, $lockedContract, $allocation, $subscription, $allowance, $modules);
        }, 3);
    }

    /** @param list<string> $modules */
    public function assertCommandForCompany(
        OfficeErpExecutionContext $ctx,
        string $action,
        OfficeErpContract $contract,
        ErpCompany $company,
        array $modules = [],
    ): void {
        $this->assertActionContext($ctx, $action);
        abort_unless($action === 'licence.add' || $action === 'owner.accept', 403);

        DB::transaction(function () use ($ctx, $action, $contract, $company, $modules): void {
            [$account, $subscription, , $allowance] = $this->lockCommercialAggregate($ctx);
            abort_unless($account->status === 'active', 403);
            $this->prepareAdmissionLocks($ctx, $contract, $company);
            [$lockedContract, $allocation] = $this->lockTarget($ctx, $contract, null);
            $this->assertCommandForLockedContext($ctx, $action, $lockedContract, $allocation, $subscription, $allowance, $modules);
        }, 3);
    }

    private function assertActionContext(OfficeErpExecutionContext $ctx, string $action): void
    {
        $ctx->assertVerified();
        $this->assertActorCanRunAction($ctx, $action);
        abort_unless(in_array($action, [
            ...self::ACTION_CLASSIFICATION['maintenance'],
            ...self::ACTION_CLASSIFICATION['new_sale'],
            ...self::ACTION_CLASSIFICATION['owner_only'],
            ...self::ACTION_CLASSIFICATION['trial_registration'],
            ...self::ACTION_CLASSIFICATION['system_only'],
        ], true), 403);
    }

    /** @param array{enabled: bool, licence_limit: ?int, module_keys: list<string>} $allowance @param list<string> $modules */
    private function assertCommandForLockedContext(
        OfficeErpExecutionContext $ctx,
        string $action,
        ?OfficeErpContract $contract,
        ?OfficeErpLicenceAllocation $allocation,
        ?Subscription $subscription,
        array $allowance,
        array $modules,
    ): void {
        if (in_array($action, self::ACTION_CLASSIFICATION['maintenance'], true)) {
            abort_unless($this->isAcceptedMaintenance($action, $contract, $allocation, $ctx), 403);
            $this->assertModulesAllowed($action, $modules, $contract, $allowance, false);

            return;
        }

        if ($ctx->actorKind === 'owner' && $action === 'owner.reject') {
            abort_unless($contract?->agreement_status === 'awaiting_owner', 409, 'contract_not_awaiting_owner');

            return;
        }

        if ($action === 'trial.register') {
            abort_unless($ctx->actorKind === 'owner' && $ctx->purpose === 'trial.register', 403);
            abort_unless((bool) config('erp.office_trial_enabled', false), 403, 'trial_registration_disabled');
            $this->assertCanProvision($subscription, $allowance);
            $this->assertModulesAllowed($action, $modules, null, $allowance, true);
            $counts = $this->usage($ctx);
            $limit = $allowance['licence_limit'];
            abort_if($limit !== null && $limit <= $counts['reserved'] + $counts['occupied'], 409, 'licence_capacity_reached');

            return;
        }

        $ownerAcceptance = $ctx->actorKind === 'owner' && $action === 'owner.accept';
        $convertingCurrentTrial = $action === 'trial.convert'
            && $allocation?->state === 'occupied'
            && $this->hasCurrentUnconvertedTrial($contract);
        if (in_array($action, ['customer.enroll', 'trial.convert'], true) && ! $convertingCurrentTrial) {
            $this->assertCanProvision($subscription, $allowance);
        } elseif ($convertingCurrentTrial) {
            abort_unless($subscription?->status === 'active', 403, 'subscription_inactive');
            abort_unless($allowance['module_keys'] !== [], 403, 'commercial_module_not_allowed');
        } elseif (in_array($action, ['trial.link.configure', 'trial.link.rotate'], true)) {
            abort_unless($subscription?->status === 'active', 403, 'subscription_inactive');
        } else {
            $this->assertCanSell($subscription, $allowance);
        }
        abort_unless($ctx->actorKind === 'office_admin' || $ownerAcceptance, 403);
        if ($ownerAcceptance) {
            abort_unless($contract?->agreement_status === 'awaiting_owner', 409, 'contract_not_awaiting_owner');
        }
        $this->assertNewSaleTarget($action, $contract);
        $this->assertModulesAllowed($action, $modules, $contract, $allowance, true);
        $counts = $this->usage($ctx);
        $limit = $allowance['licence_limit'];
        $ownUnexpiredReservation = $allocation?->state === 'reserved'
            && $allocation->expires_at !== null
            && $allocation->expires_at->isFuture();
        $capacityRequired = in_array($action, [
            'contract.create', 'contract.send', 'invitation.issue', 'subscription.adopt', 'licence.add', 'owner.accept',
            'offer.create', 'offer.publish', 'customer.enroll', 'trial.convert',
        ], true);
        abort_if(
            $capacityRequired
                && ! $convertingCurrentTrial
                && $limit !== null
                && $limit <= $counts['reserved'] + $counts['occupied'] - ($ownUnexpiredReservation ? 1 : 0),
            409,
            'licence_capacity_reached',
        );
    }

    private function hasCurrentUnconvertedTrial(?OfficeErpContract $contract): bool
    {
        if ($contract?->erp_company_id === null) {
            return false;
        }

        return ErpSubscription::query()
            ->where('erp_company_id', $contract->erp_company_id)
            ->where('office_erp_contract_id', $contract->getKey())
            ->where('status', 'trialing')
            ->whereHas('trial', static fn ($trial) => $trial
                ->where('erp_company_id', $contract->erp_company_id)
                ->where('account_id', $contract->account_id)
                ->whereNull('converted_at')
                ->where('ends_at', '>', now()))
            ->exists();
    }

    private function assertNewSaleTarget(string $action, ?OfficeErpContract $contract): void
    {
        if ($action === 'contract.create') {
            abort_unless($contract === null || $contract->agreement_status === 'draft', 409, 'contract_not_draft');
        } elseif ($action === 'contract.send' || $action === 'invitation.issue') {
            abort_unless($contract?->agreement_status === ($action === 'contract.send' ? 'draft' : 'awaiting_owner'), 409, 'contract_not_invitable');
        } elseif (in_array($action, ['subscription.adopt', 'licence.add', 'modules.expand'], true)) {
            abort_unless($contract && in_array($contract->agreement_status, ['accepted', 'effective'], true), 409, 'contract_not_accepted');
        }
    }

    /** @return array{Account, ?Subscription, ?Plan, array{enabled: bool, licence_limit: ?int, module_keys: list<string>}} */
    public function lockCommercialAggregate(OfficeErpExecutionContext $ctx): array
    {
        $account = Account::query()->whereKey($ctx->accountId)->lockForUpdate()->first();
        abort_unless($account, 404);
        if ($ctx->actorKind === 'office_admin') {
            $selected = CurrentAccount::fromRequest();
            abort_unless((int) $selected->account->id === $ctx->accountId, 403);
            $membership = AccountMembership::query()
                ->whereKey($selected->membership->getKey())
                ->where('account_id', $ctx->accountId)
                ->where('user_id', $ctx->actor?->getKey())
                ->where('role', 'admin')
                ->lockForUpdate()
                ->first();
            abort_unless($membership, 403);
        }
        $subscription = Subscription::query()->where('account_id', $ctx->accountId)->lockForUpdate()->first();
        $plan = $subscription === null
            ? null
            : Plan::query()->whereKey($subscription->plan_id)->lockForUpdate()->first();

        return [$account, $subscription, $plan, $plan?->erp_commercial ?? Plan::DEFAULT_ERP_COMMERCIAL];
    }

    /** @return array{reserved: int, occupied: int} */
    public function usage(OfficeErpExecutionContext $ctx): array
    {
        $allocations = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class);

        return $this->usageFromAllocations($allocations);
    }

    /** @return array{reserved: int, occupied: int} */
    public function usageForPublicAccount(Account $account): array
    {
        abort_unless($account->exists && Account::query()->whereKey($account->getKey())->exists(), 404);

        return $this->usageFromAllocations(
            OfficeErpLicenceAllocation::query()->where('account_id', (int) $account->getKey()),
        );
    }

    /** @return array{reserved: int, occupied: int} */
    private function usageFromAllocations(Builder $allocations): array
    {
        $currentAllocations = (clone $allocations)
            ->where(function ($query): void {
                $query->where('state', 'occupied')
                    ->orWhere(function ($reserved): void {
                        $reserved->where('state', 'reserved')->where('expires_at', '>', now());
                    });
            });

        $occupied = (clone $currentAllocations)
            ->where('state', 'occupied')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('erp_subscriptions')
                    ->join('erp_subscription_trials', 'erp_subscription_trials.erp_subscription_id', '=', 'erp_subscriptions.id')
                    ->whereColumn('erp_subscriptions.erp_company_id', 'office_erp_licence_allocations.erp_company_id')
                    ->whereColumn('erp_subscriptions.office_erp_contract_id', 'office_erp_licence_allocations.contract_id')
                    ->whereColumn('erp_subscription_trials.erp_company_id', 'office_erp_licence_allocations.erp_company_id')
                    ->whereColumn('erp_subscription_trials.account_id', 'office_erp_licence_allocations.account_id')
                    ->whereIn('erp_subscriptions.status', ['trialing', 'trial_expired'])
                    ->whereNull('erp_subscription_trials.converted_at')
                    ->where('erp_subscription_trials.ends_at', '<=', now());
            });

        return [
            'reserved' => (clone $currentAllocations)->where('state', 'reserved')->count(),
            'occupied' => $occupied->count(),
        ];
    }

    public function releaseExpiredReservations(OfficeErpExecutionContext $ctx): void
    {
        $this->prepareAdmissionLocks($ctx);
    }

    public function prepareAdmissionLocks(
        OfficeErpExecutionContext $ctx,
        ?OfficeErpContract $targetContract = null,
        ?ErpCompany $targetCompany = null,
        bool $releaseExpired = true,
    ): void {
        DB::transaction(function () use ($ctx, $targetContract, $targetCompany, $releaseExpired): void {
            $this->lockCommercialAggregate($ctx);
            $expired = $releaseExpired
                ? OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                    ->where('state', 'reserved')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now())
                    ->orderBy('id')
                    ->get()
                : collect();

            $lockedTarget = $targetContract === null
                ? null
                : OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereKey($targetContract->getKey())->first();
            abort_if($targetContract !== null && $lockedTarget === null, 404);

            $companyIds = $expired->pluck('erp_company_id')->filter()->merge([
                $lockedTarget?->erp_company_id,
                $targetCompany?->getKey(),
            ])->filter()->unique()->sort()->values();
            $foundTargetCompany = $targetCompany === null;
            foreach ($companyIds as $companyId) {
                $company = ErpCompany::query()->whereKey($companyId)->lockForUpdate()->first();
                abort_unless($company, 404);
                if ($targetCompany !== null && (int) $company->getKey() === (int) $targetCompany->getKey()) {
                    $foundTargetCompany = true;
                }
            }
            abort_unless($foundTargetCompany, 404);

            $contractIds = $expired->pluck('contract_id')->merge([$lockedTarget?->getKey()])->filter()->unique()->sort()->values();
            $contracts = $contractIds->isEmpty()
                ? collect()
                : OfficeErpRecords::query($ctx, OfficeErpContract::class)->whereIn('id', $contractIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');
            abort_if($lockedTarget !== null && ! $contracts->has($lockedTarget->getKey()), 404);

            $allocationIds = $expired->pluck('id');
            if ($lockedTarget !== null) {
                $allocationIds = $allocationIds->merge(OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                    ->where('contract_id', $lockedTarget->getKey())
                    ->pluck('id'));
            }
            $allocationIds = $allocationIds->unique()->sort()->values();
            $allocations = $allocationIds->isEmpty()
                ? collect()
                : OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
                    ->whereIn('id', $allocationIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

            foreach ($allocations as $allocation) {
                if ($expired->contains('id', $allocation->id)) {
                    abort_unless($contracts->has($allocation->contract_id), 404);
                }
                if ($allocation->state === 'reserved' && $allocation->expires_at !== null && ! $allocation->expires_at->isFuture()) {
                    if ($expired->contains('id', $allocation->id)) {
                        $allocation->forceFill(['state' => 'released', 'released_at' => now()]);
                        OfficeErpRecords::persist($ctx, $allocation);
                    }
                }
            }
        }, 3);
    }

    private function assertOfficeAdminContext(OfficeErpExecutionContext $ctx): void
    {
        $ctx->assertVerified();
        abort_unless($ctx->actorKind === 'office_admin' && $ctx->actor !== null, 403);
        $selected = CurrentAccount::fromRequest();
        abort_unless((int) $selected->account->id === $ctx->accountId && $selected->membership->role === 'admin', 403);
        AccessSession::assertOwnership(request());
    }

    /** @return array{?OfficeErpContract, ?OfficeErpLicenceAllocation} */
    private function lockTarget(OfficeErpExecutionContext $ctx, ?OfficeErpContract $contract, ?OfficeErpLicenceAllocation $reservation): array
    {
        if ($contract === null) {
            abort_if($reservation !== null, 404);

            return [null, null];
        }

        $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($contract->getKey())
            ->first();
        abort_unless($contract, 404);
        if ($contract->erp_company_id !== null) {
            ErpCompany::query()->whereKey($contract->erp_company_id)->lockForUpdate()->first();
        }
        $contract = OfficeErpRecords::query($ctx, OfficeErpContract::class)
            ->whereKey($contract->getKey())->lockForUpdate()->first();
        abort_unless($contract, 404);
        $allocationQuery = OfficeErpRecords::query($ctx, OfficeErpLicenceAllocation::class)
            ->where('contract_id', $contract->getKey());
        if ($reservation !== null) {
            $allocationQuery->whereKey($reservation->getKey());
        }

        return [$contract, $allocationQuery->lockForUpdate()->first()];
    }

    private function isAcceptedMaintenance(string $action, ?OfficeErpContract $contract, ?OfficeErpLicenceAllocation $allocation, OfficeErpExecutionContext $ctx): bool
    {
        if (in_array($action, ['policy.read', 'policy.configure', 'offer.archive', 'report.read'], true)) {
            return true;
        }

        if ($contract === null) {
            return false;
        }

        $active = in_array($contract->agreement_status, ['accepted', 'effective'], true);
        $historical = in_array($contract->agreement_status, ['accepted', 'effective', 'canceled'], true);
        $effectiveLicence = $contract->agreement_status !== 'effective' || $allocation?->state === 'occupied';

        return match ($action) {
            'contract.read', 'invoice.read', 'invoice.issue', 'invoice.correct', 'receipt.read', 'receipt.record', 'receipt.reverse' => $historical && $effectiveLicence,
            'contract.activate' => $contract->agreement_status === 'accepted' && $allocation?->state === 'occupied',
            'contract.renew', 'contract.amend.price', 'contract.suspend', 'contract.resume', 'contract.cancel' => $active && $effectiveLicence,
            'invitation.revoke' => $contract->agreement_status === 'awaiting_owner' && $allocation?->state === 'reserved',
            'owner.setup.issue' => $active && $effectiveLicence,
            default => false,
        };
    }

    /** @param list<string> $modules @param array{enabled: bool, licence_limit: ?int, module_keys: list<string>} $allowance */
    private function assertModulesAllowed(string $action, array $modules, ?OfficeErpContract $contract, array $allowance, bool $expansion): void
    {
        if ($modules === []) {
            abort_if($action === 'modules.expand', 422, 'modules_required');

            return;
        }
        $allowed = $expansion ? $allowance['module_keys'] : ($contract?->currentRevision?->module_keys ?? []);
        abort_unless(array_diff($modules, $allowed) === [], 403, 'commercial_module_not_allowed');
        if (! $expansion && $contract !== null) {
            abort_unless(array_diff($modules, $contract->currentRevision?->module_keys ?? []) === [], 403, 'commercial_grant_expansion_forbidden');
        }
        if ($action === 'contract.renew' || $action === 'contract.amend.price') {
            $current = $contract?->currentRevision?->module_keys ?? [];
            sort($modules, SORT_STRING);
            sort($current, SORT_STRING);
            abort_unless($modules === $current, 403, 'commercial_grant_expansion_forbidden');
        }
        if ($action === 'modules.expand') {
            abort_unless($contract && in_array($contract->agreement_status, ['accepted', 'effective'], true), 409);
            abort_unless(array_diff($modules, $contract->currentRevision?->module_keys ?? []) !== [], 422, 'modules_must_expand_grant');
        }
    }

    /** @param array{enabled: bool, licence_limit: ?int, module_keys: list<string>} $allowance */
    private function assertCanSell(?Subscription $subscription, array $allowance): void
    {
        abort_unless($subscription?->status === 'active', 403, 'subscription_inactive');
        abort_unless($allowance['enabled'], 403, 'commercial_resale_disabled');
    }

    /** @param array{enabled: bool, licence_limit: ?int, module_keys: list<string>} $allowance */
    private function assertCanProvision(?Subscription $subscription, array $allowance): void
    {
        abort_unless($subscription?->status === 'active', 403, 'subscription_inactive');
        abort_unless($allowance['licence_limit'] === null || $allowance['licence_limit'] > 0, 403, 'licence_capacity_reached');
        abort_unless($allowance['module_keys'] !== [], 403, 'commercial_module_not_allowed');
    }

    private function assertActorCanRunAction(OfficeErpExecutionContext $ctx, string $action): void
    {
        if ($ctx->actorKind === 'office_admin') {
            abort_if(
                in_array($action, self::ACTION_CLASSIFICATION['owner_only'], true)
                    || in_array($action, self::ACTION_CLASSIFICATION['system_only'], true),
                403,
            );
            $this->assertOfficeAdminContext($ctx);

            return;
        }

        if ($ctx->actorKind === 'owner') {
            if ($action === 'trial.register') {
                abort_unless($ctx->purpose === 'trial.register' && $ctx->actor instanceof User, 403);

                return;
            }
            abort_unless($ctx->purpose !== 'trial.register', 403);
            abort_unless(in_array($action, self::ACTION_CLASSIFICATION['owner_only'], true), 403);

            return;
        }

        abort_unless($ctx->actorKind === 'system' && in_array($action, self::ACTION_CLASSIFICATION['system_only'], true), 403);
    }

    public function assertReleaseAuthority(OfficeErpExecutionContext $ctx, string $reason, OfficeErpContract $contract, ?OfficeErpLicenceAllocation $allocation): void
    {
        $ctx->assertVerified();
        if ($ctx->actorKind === 'office_admin') {
            $this->assertOfficeAdminContext($ctx);
            abort_unless(in_array($reason, ['invitation_revoked', 'contract_canceled', 'effective_cancellation'], true), 403);
            if ($reason === 'invitation_revoked') {
                abort_unless($contract->agreement_status === 'awaiting_owner' && $allocation?->state === 'reserved', 409);
            } elseif ($reason === 'contract_canceled') {
                abort_unless($contract->agreement_status === 'canceled', 409);
            }

            return;
        }
        if ($ctx->actorKind === 'owner') {
            abort_unless($ctx->purpose !== 'trial.register', 403);
            abort_unless($reason === 'owner_rejected' && $contract->agreement_status === 'awaiting_owner', 403);

            return;
        }
        abort_unless(
            $ctx->actorKind === 'system'
                && (($reason === 'invitation_expired' && $allocation?->state === 'reserved' && $allocation->expires_at !== null && ! $allocation->expires_at->isFuture())
                    || ($reason === 'effective_cancellation' && $contract->agreement_status === 'canceled')),
            403,
        );
    }
}
