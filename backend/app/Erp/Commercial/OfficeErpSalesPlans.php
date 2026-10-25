<?php

namespace App\Erp\Commercial;

use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use App\Models\Erp\Commercial\OfficeErpSalesPlanRevision;
use Illuminate\Support\Facades\DB;

final class OfficeErpSalesPlans
{
    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, OfficeErpAdmission $admission, string $action, array $payload, ?int $expectedPlanId): void
    {
        abort_unless(in_array($action, ['offer.create', 'offer.publish', 'offer.archive'], true), 422);
        $planId = $payload['sales_plan_id'] ?? null;
        abort_unless($planId === $expectedPlanId, 404);
        $terms = $payload['terms'] ?? [];
        abort_unless(is_array($terms), 409);
        $modules = $terms['module_keys'] ?? [];
        abort_unless(is_array($modules) && array_is_list($modules), 409);

        $admission->assertCommand($ctx, $action, modules: $modules);
        if ($planId !== null) {
            $plan = OfficeErpRecords::query($ctx, OfficeErpSalesPlan::class)
                ->whereKey($planId)
                ->lockForUpdate()
                ->firstOrFail();
            if (in_array($action, ['offer.create', 'offer.publish'], true)) {
                abort_if($plan->archived_at !== null || $plan->status === 'archived', 409, 'offer_archived');
            }
        }
    }

    /** @param array{name: string, description: ?string, price: string, currency: 'BRL', interval_months: int, due_in_days: int, module_keys: list<string>, published: bool} $terms */
    public function create(OfficeErpExecutionContext $ctx, array $terms, string $key): OfficeErpSalesPlan
    {
        return DB::transaction(function () use ($ctx, $terms): OfficeErpSalesPlan {
            $ctx->assertVerified();
            $plan = new OfficeErpSalesPlan;
            $plan->forceFill([
                'name' => $terms['name'],
                'description' => $terms['description'],
                'status' => 'active',
                'archived_at' => null,
            ]);
            /** @var OfficeErpSalesPlan $plan */
            $plan = OfficeErpRecords::persist($ctx, $plan);

            $revision = $this->newRevision($ctx, $plan, $terms, 1);
            $plan->forceFill(['current_revision_id' => $revision->id]);
            /** @var OfficeErpSalesPlan $plan */
            $plan = OfficeErpRecords::persist($ctx, $plan);

            return $plan->fresh(['currentRevision']);
        }, 3);
    }

    /** @param array{name: string, description: ?string, price: string, currency: 'BRL', interval_months: int, due_in_days: int, module_keys: list<string>, published: bool} $terms */
    public function revise(OfficeErpExecutionContext $ctx, OfficeErpSalesPlan $plan, array $terms, string $key): OfficeErpSalesPlanRevision
    {
        return DB::transaction(function () use ($ctx, $plan, $terms): OfficeErpSalesPlanRevision {
            $lockedPlan = OfficeErpRecords::query($ctx, OfficeErpSalesPlan::class)
                ->whereKey($plan->getKey())
                ->lockForUpdate()
                ->first();
            abort_unless($lockedPlan, 404);
            abort_if($lockedPlan->archived_at !== null || $lockedPlan->status === 'archived', 409, 'offer_archived');

            $revisionNumber = (int) OfficeErpRecords::query($ctx, OfficeErpSalesPlanRevision::class)
                ->where('sales_plan_id', $lockedPlan->id)
                ->max('revision_number') + 1;
            $revision = $this->newRevision($ctx, $lockedPlan, $terms, $revisionNumber);

            $lockedPlan->forceFill([
                'name' => $terms['name'],
                'description' => $terms['description'],
                'current_revision_id' => $revision->id,
            ]);
            OfficeErpRecords::persist($ctx, $lockedPlan);

            return $revision;
        }, 3);
    }

    public function archive(OfficeErpExecutionContext $ctx, OfficeErpSalesPlan $plan, string $key): void
    {
        DB::transaction(function () use ($ctx, $plan): void {
            $lockedPlan = OfficeErpRecords::query($ctx, OfficeErpSalesPlan::class)
                ->whereKey($plan->getKey())
                ->lockForUpdate()
                ->first();
            abort_unless($lockedPlan, 404);

            if ($lockedPlan->archived_at !== null || $lockedPlan->status === 'archived') {
                return;
            }

            $lockedPlan->forceFill(['status' => 'archived', 'archived_at' => now()]);
            OfficeErpRecords::persist($ctx, $lockedPlan);
        }, 3);
    }

    /** @param array{name: string, description: ?string, price: string, currency: 'BRL', interval_months: int, due_in_days: int, module_keys: list<string>, published: bool} $terms */
    private function newRevision(OfficeErpExecutionContext $ctx, OfficeErpSalesPlan $plan, array $terms, int $revisionNumber): OfficeErpSalesPlanRevision
    {
        $revision = new OfficeErpSalesPlanRevision;
        $revision->forceFill([
            'sales_plan_id' => $plan->id,
            'revision_number' => $revisionNumber,
            'name' => $terms['name'],
            'description' => $terms['description'],
            'price' => $terms['price'],
            'currency' => 'BRL',
            'interval_months' => $terms['interval_months'],
            'due_in_days' => $terms['due_in_days'],
            'module_keys' => $terms['module_keys'],
            'published_at' => $terms['published'] ? now() : null,
        ]);

        /** @var OfficeErpSalesPlanRevision $revision */
        return OfficeErpRecords::persist($ctx, $revision);
    }
}
