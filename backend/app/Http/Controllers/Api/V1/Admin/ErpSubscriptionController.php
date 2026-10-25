<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Erp\ErpCommercialAudit;
use App\Http\Controllers\Controller;
use App\Http\Resources\Erp\ErpSubscriptionResource;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ErpSubscriptionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'nullable', 'in:pending,active,past_due,canceled'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 15)));
        $subscriptions = ErpSubscription::query()
            ->with(['company', 'plan.modules'])
            ->search($filters['q'] ?? null)
            ->withStatus($filters['status'] ?? null)
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return ErpSubscriptionResource::collection($subscriptions);
    }

    public function show(ErpSubscription $subscription): JsonResponse
    {
        return response()->json(['data' => (new ErpSubscriptionResource($subscription->load(['company', 'plan.modules'])))->resolve()]);
    }

    public function update(Request $request, ErpSubscription $subscription): JsonResponse
    {
        $data = $request->validate([
            'erp_plan_id' => ['sometimes', 'required', 'exists:erp_plans,id'],
            'status' => ['sometimes', 'required', 'in:pending,active,past_due,canceled'],
        ]);

        $updated = DB::transaction(function () use ($request, $subscription, $data): ErpSubscription {
            ErpCompany::query()->whereKey($subscription->erp_company_id)->lockForUpdate()->firstOrFail();
            $subscription = ErpSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            abort_if($subscription->office_erp_contract_id !== null, 409, 'retail_contract_lifecycle_required');
            $before = $subscription->only(['status', 'erp_plan_id']);
            $subscription->update($data);
            ErpCommercialAudit::log($request, 'erp_subscription.update', (int) $subscription->erp_company_id, [
                'subscription_id' => $subscription->id,
                'before' => $before,
                'after' => $subscription->only(['status', 'erp_plan_id']),
            ]);

            return $subscription->load(['company', 'plan.modules']);
        });

        return response()->json(['data' => (new ErpSubscriptionResource($updated))->resolve($request)]);
    }
}
