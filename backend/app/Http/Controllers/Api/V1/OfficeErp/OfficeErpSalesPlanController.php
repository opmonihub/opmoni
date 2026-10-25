<?php

namespace App\Http\Controllers\Api\V1\OfficeErp;

use App\Erp\Commercial\OfficeErpAdmission;
use App\Erp\Commercial\OfficeErpExecutionContext;
use App\Erp\Commercial\OfficeErpOperations;
use App\Erp\Commercial\OfficeErpQueries;
use App\Erp\Commercial\OfficeErpRecords;
use App\Erp\Commercial\OfficeErpSalesPlans;
use App\Erp\ErpOperationJournal;
use App\Http\Controllers\Controller;
use App\Http\Requests\OfficeErp\ListCommercialRequest;
use App\Http\Requests\OfficeErp\SaveSalesPlanRequest;
use App\Models\Erp\Commercial\OfficeErpSalesPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OfficeErpSalesPlanController extends Controller
{
    public function index(Request $request, OfficeErpAdmission $admission, OfficeErpQueries $queries): JsonResponse
    {
        $filters = ListCommercialRequest::validateFilters('plans', $request->query());
        $ctx = $admission->context($request);
        $page = $queries->plans($ctx, $filters);

        return response()->json([
            'data' => array_map(fn (OfficeErpSalesPlan $plan): array => $this->planData($plan), $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('Cache-Control', 'no-store');
    }

    public function store(SaveSalesPlanRequest $request, OfficeErpAdmission $admission, OfficeErpOperations $operations, ErpOperationJournal $journal, OfficeErpSalesPlans $plans): JsonResponse
    {
        $ctx = $admission->context($request);
        $terms = $request->terms();
        $key = $journal->key($request, true);
        $action = $terms['published'] ? 'offer.publish' : 'offer.create';
        $payload = ['sales_plan_id' => null, 'terms' => $terms];
        $outcome = $operations->execute(
            $ctx,
            $key,
            $action,
            $payload,
            fn (string $currentAction, array $currentPayload): mixed => $plans->authorizeOperation($ctx, $admission, $currentAction, $currentPayload, null, $key),
            fn (): array => ['data' => $this->planData($plans->create($ctx, $terms, $key)->load('currentRevision'), $terms)],
        );

        return response()->json($outcome)->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, int $plan, OfficeErpAdmission $admission): JsonResponse
    {
        $ctx = $admission->context($request);
        $record = OfficeErpRecords::query($ctx, OfficeErpSalesPlan::class)->with('currentRevision')->findOrFail($plan);

        return response()->json(['data' => $this->planData($record)])->header('Cache-Control', 'no-store');
    }

    public function revise(SaveSalesPlanRequest $request, int $plan, OfficeErpAdmission $admission, OfficeErpOperations $operations, ErpOperationJournal $journal, OfficeErpSalesPlans $plans): JsonResponse
    {
        $ctx = $admission->context($request);
        $terms = $request->terms();
        $key = $journal->key($request, true);
        $action = $terms['published'] ? 'offer.publish' : 'offer.create';
        $payload = ['sales_plan_id' => $plan, 'terms' => $terms];
        $outcome = $operations->execute(
            $ctx,
            $key,
            $action,
            $payload,
            fn (string $currentAction, array $currentPayload): mixed => $plans->authorizeOperation($ctx, $admission, $currentAction, $currentPayload, $plan, $key),
            fn (): array => ['data' => $this->planData($plans->revise($ctx, $this->lockedPlan($ctx, $plan), $terms, $key)->salesPlan()->with('currentRevision')->firstOrFail(), $terms)],
        );

        return response()->json($outcome)->header('Cache-Control', 'no-store');
    }

    public function archive(Request $request, int $plan, OfficeErpAdmission $admission, OfficeErpOperations $operations, ErpOperationJournal $journal, OfficeErpSalesPlans $plans): JsonResponse
    {
        $ctx = $admission->context($request);
        $key = $journal->key($request, true);
        $payload = ['sales_plan_id' => $plan];
        $outcome = $operations->execute(
            $ctx,
            $key,
            'offer.archive',
            $payload,
            fn (string $action, array $currentPayload): mixed => $plans->authorizeOperation($ctx, $admission, $action, $currentPayload, $plan, $key),
            function () use ($ctx, $plan, $key, $plans): array {
                $record = $this->lockedPlan($ctx, $plan);
                $plans->archive($ctx, $record, $key);

                return ['data' => $this->planData($this->lockedPlan($ctx, $plan)->load('currentRevision'))];
            },
        );

        return response()->json($outcome)->header('Cache-Control', 'no-store');
    }

    private function lockedPlan(OfficeErpExecutionContext $ctx, int $id): OfficeErpSalesPlan
    {
        return OfficeErpRecords::query($ctx, OfficeErpSalesPlan::class)
            ->whereKey($id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function planData(OfficeErpSalesPlan $plan, ?array $exactTerms = null): array
    {
        $revision = $plan->currentRevision;

        return [
            'id' => (int) $plan->id,
            'name' => $plan->name,
            'description' => $plan->description,
            'status' => $plan->status,
            'archived_at' => $plan->archived_at?->toISOString(),
            'current_revision' => $revision === null ? null : [
                'id' => (int) $revision->id,
                'revision_number' => (int) $revision->revision_number,
                'name' => $revision->name,
                'description' => $revision->description,
                'price' => $exactTerms['price'] ?? (string) $revision->price,
                'currency' => $exactTerms['currency'] ?? $revision->currency,
                'interval_months' => $exactTerms['interval_months'] ?? (int) $revision->interval_months,
                'due_in_days' => $exactTerms['due_in_days'] ?? (int) $revision->due_in_days,
                'module_keys' => $exactTerms['module_keys'] ?? $revision->module_keys,
                'published_at' => $revision->published_at?->toISOString(),
                'published' => $exactTerms['published'] ?? $revision->published_at !== null,
            ],
        ];
    }
}
