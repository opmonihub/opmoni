<?php

namespace App\Erp\Commercial;

use App\Models\Account;
use App\Models\Erp\Commercial\OfficeErpTrialLink;
use App\Models\Erp\ErpPlan;
use App\Models\Plan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OfficeErpTrialLinks
{
    public function __construct(
        private readonly OfficeErpAdmission $admission,
        private readonly OfficeErpOperations $operations,
    ) {}

    /** @return array<string, mixed> */
    public function read(OfficeErpExecutionContext $ctx): array
    {
        $capabilities = $this->assertCanManage($ctx);

        $link = OfficeErpRecords::query($ctx, OfficeErpTrialLink::class)
            ->with('plan.modules')
            ->first();

        return [
            'configured' => $link !== null,
            'enabled' => $link?->enabled ?? false,
            'revision' => $link?->revision,
            'url' => $link === null ? null : $this->url((string) $link->public_code),
            'erp_plan_id' => $link?->erp_plan_id,
            'plan' => $link === null ? null : $this->planSummary($link->plan),
            'plans' => $this->eligiblePlans($capabilities['allowance']['module_keys'] ?? []),
        ];
    }

    /** @param array{erp_plan_id: int, enabled: bool} $input @return array<string, mixed> */
    public function configure(OfficeErpExecutionContext $ctx, array $input, string $key): array
    {
        $payload = ['erp_plan_id' => $input['erp_plan_id'], 'enabled' => $input['enabled']];

        return $this->operations->execute(
            $ctx,
            $key,
            'trial.link.configure',
            $payload,
            function (string $action, array $data) use ($ctx): void {
                $this->authorizeOperation($ctx, $this->admission, $action, $data);
            },
            function () use ($ctx, $input): array {
                return DB::transaction(function () use ($ctx, $input): array {
                    $plan = ErpPlan::query()->with('modules')->whereKey($input['erp_plan_id'])->firstOrFail();
                    $moduleKeys = $plan->modules->pluck('module_key')->all();
                    $this->assertCanManage($ctx, $moduleKeys);

                    $link = OfficeErpRecords::query($ctx, OfficeErpTrialLink::class)->lockForUpdate()->first();
                    if ($link === null) {
                        $link = new OfficeErpTrialLink;
                        $link->forceFill([
                            'public_code' => (string) Str::uuid(),
                            'erp_plan_id' => $plan->id,
                            'enabled' => $input['enabled'],
                            'revision' => 1,
                            'created_by_user_id' => $ctx->actor?->getKey(),
                            'updated_by_user_id' => $ctx->actor?->getKey(),
                        ]);
                        OfficeErpRecords::persist($ctx, $link);
                    } else {
                        $link->forceFill([
                            'erp_plan_id' => $plan->id,
                            'enabled' => $input['enabled'],
                            'revision' => (int) $link->revision + 1,
                            'updated_by_user_id' => $ctx->actor?->getKey(),
                        ]);
                        OfficeErpRecords::persist($ctx, $link);
                    }

                    return $this->officeResource($link->fresh('plan.modules'));
                }, 3);
            },
        );
    }

    /** @return array<string, mixed> */
    public function rotate(OfficeErpExecutionContext $ctx, string $key): array
    {
        $link = OfficeErpRecords::query($ctx, OfficeErpTrialLink::class)->firstOrFail();
        $payload = ['link_id' => (int) $link->getKey()];

        return $this->operations->execute(
            $ctx,
            $key,
            'trial.link.rotate',
            $payload,
            function (string $action, array $data) use ($ctx): void {
                $this->authorizeOperation($ctx, $this->admission, $action, $data);
            },
            function () use ($ctx, $link): array {
                return DB::transaction(function () use ($ctx, $link): array {
                    $locked = OfficeErpRecords::query($ctx, OfficeErpTrialLink::class)
                        ->whereKey($link->getKey())
                        ->lockForUpdate()
                        ->firstOrFail();
                    $locked->forceFill([
                        'public_code' => (string) Str::uuid(),
                        'revision' => (int) $locked->revision + 1,
                        'updated_by_user_id' => $ctx->actor?->getKey(),
                    ]);
                    OfficeErpRecords::persist($ctx, $locked);

                    return $this->officeResource($locked->fresh('plan.modules'));
                }, 3);
            },
        );
    }

    /** @return array<string, mixed> */
    public function resolveForRegistration(string $code): array
    {
        $link = $this->findPublicLink($code);
        abort_unless((bool) config('erp.office_trial_enabled', false) && $link->enabled, 404);

        return [
            'link_id' => (int) $link->id,
            'revision' => (int) $link->revision,
            'account_id' => (int) $link->account_id,
            'erp_plan_id' => (int) $link->erp_plan_id,
            'enabled' => (bool) $link->enabled,
            'plan_module_keys' => array_values($link->plan->modules->pluck('module_key')->all()),
            'sponsor_name' => (string) $link->account->name,
        ];
    }

    /** @return array<string, mixed> */
    public function resolvePublic(string $code): array
    {
        $resolved = $this->resolveForRegistration($code);

        $account = Account::query()->find($resolved['account_id']);
        abort_unless($account, 404);
        $subscription = $account?->subscription;
        $officeActive = $account?->status === 'active' && $subscription?->status === 'active';
        $allowance = $subscription?->plan?->erp_commercial ?? Plan::DEFAULT_ERP_COMMERCIAL;
        $modulesAllowed = array_diff($resolved['plan_module_keys'], $allowance['module_keys'] ?? []) === [];
        $usage = $this->admission->usageForPublicAccount($account);
        $counts = $usage['reserved'] + $usage['occupied'];
        $limit = array_key_exists('licence_limit', $allowance) ? $allowance['licence_limit'] : 0;
        $hasCapacity = $limit === null || $counts < (int) $limit;
        $available = $officeActive && $modulesAllowed && $hasCapacity;

        $plan = ErpPlan::query()->with('modules')->find($resolved['erp_plan_id']);

        return [
            'available' => $available,
            'reason' => $available ? null : $this->unavailableReason($officeActive, $modulesAllowed, $hasCapacity),
            'sponsor' => ['name' => $resolved['sponsor_name']],
            'plan' => $plan === null ? null : $this->planSummary($plan),
            'duration_days' => 7,
        ];
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, OfficeErpAdmission $admission, string $action, array $payload, ?string $key = null): void
    {
        abort_unless(in_array($action, ['trial.link.configure', 'trial.link.rotate'], true), 422);
        $modules = [];
        if ($action === 'trial.link.configure') {
            $planId = $payload['erp_plan_id'] ?? null;
            abort_unless(is_int($planId) && $planId > 0 && is_bool($payload['enabled'] ?? null), 409);
            $plan = ErpPlan::query()->with('modules')->find($planId);
            abort_unless($plan, 409);
            $modules = $plan->modules->pluck('module_key')->all();
            abort_unless($modules !== [], 403, 'commercial_module_not_allowed');
            $this->assertPlanAllowed($ctx, $modules);
        } else {
            $linkId = $payload['link_id'] ?? null;
            abort_unless(is_int($linkId) && $linkId > 0, 409);
            OfficeErpRecords::query($ctx, OfficeErpTrialLink::class)->whereKey($linkId)->firstOrFail();
        }
        $admission->assertCommand($ctx, $action, modules: $modules);
    }

    private function assertCanManage(OfficeErpExecutionContext $ctx, array $modules = []): array
    {
        $capabilities = $this->admission->capabilities($ctx);
        abort_unless($capabilities['can_manage_trial_link'] ?? false, 403);
        if ($modules !== []) {
            $this->assertPlanAllowed($ctx, $modules);
        }

        return $capabilities;
    }

    /** @param list<string> $modules */
    private function assertPlanAllowed(OfficeErpExecutionContext $ctx, array $modules): void
    {
        $capabilities = $this->admission->capabilities($ctx);
        abort_unless(array_diff($modules, $capabilities['allowance']['module_keys'] ?? []) === [], 403, 'commercial_module_not_allowed');
    }

    private function findPublicLink(string $code): OfficeErpTrialLink
    {
        abort_unless(Str::isUuid($code), 404);

        return OfficeErpTrialLink::query()
            ->with(['account', 'plan.modules'])
            ->where('public_code', $code)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function officeResource(OfficeErpTrialLink $link): array
    {
        return [
            'configured' => true,
            'enabled' => (bool) $link->enabled,
            'revision' => (int) $link->revision,
            'url' => $this->url((string) $link->public_code),
            'erp_plan_id' => (int) $link->erp_plan_id,
            'plan' => $this->planSummary($link->plan),
        ];
    }

    /** @return array{name: string, module_keys: list<string>} */
    private function planSummary(ErpPlan $plan): array
    {
        return ['name' => (string) $plan->name, 'module_keys' => array_values($plan->modules->pluck('module_key')->all())];
    }

    /** @param list<string> $allowedModules @return list<array{id: int, name: string, module_keys: list<string>}> */
    private function eligiblePlans(array $allowedModules): array
    {
        return ErpPlan::query()
            ->with('modules')
            ->orderBy('name')
            ->get()
            ->filter(function (ErpPlan $plan) use ($allowedModules): bool {
                $modules = $plan->modules->pluck('module_key')->all();

                return $modules !== [] && array_diff($modules, $allowedModules) === [];
            })
            ->map(fn (ErpPlan $plan): array => [
                'id' => (int) $plan->getKey(),
                'name' => (string) $plan->name,
                'module_keys' => array_values($plan->modules->pluck('module_key')->all()),
            ])
            ->values()
            ->all();
    }

    private function url(string $code): string
    {
        $origin = (string) config('access.origin');
        abort_unless(filter_var($origin, FILTER_VALIDATE_URL)
            && (parse_url($origin, PHP_URL_SCHEME) === 'https' || app()->environment('local', 'testing')), 500);

        return rtrim($origin, '/').'/erp/testar/'.$code;
    }

    private function unavailableReason(bool $officeActive, bool $modulesAllowed, bool $hasCapacity): string
    {
        return match (true) {
            ! $officeActive => 'office_unavailable',
            ! $hasCapacity => 'licence_capacity_reached',
            ! $modulesAllowed => 'plan_unavailable',
            default => 'link_unavailable',
        };
    }
}
