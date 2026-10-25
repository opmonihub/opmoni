<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlanRequest;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Plan::orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:255', 'unique:plans,slug'],
            'name' => ['required', 'string', 'max:255'],
            'limits' => ['nullable', 'array'],
            ...UpdatePlanRequest::erpCommercialRules(),
        ]);
        $data['erp_commercial'] ??= Plan::DEFAULT_ERP_COMMERCIAL;

        $plan = Plan::create($data);

        return response()->json($plan, 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return response()->json($plan);
    }

    public function update(UpdatePlanRequest $request, Plan $plan): JsonResponse
    {
        $plan = DB::transaction(function () use ($request, $plan): Plan {
            $lockedPlan = Plan::query()->whereKey($plan->getKey())->lockForUpdate()->firstOrFail();
            $lockedPlan->update($request->planAttributes());

            return $lockedPlan;
        });

        return response()->json($plan);
    }
}
