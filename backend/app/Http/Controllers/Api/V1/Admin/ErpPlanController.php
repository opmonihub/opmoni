<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Erp\ErpCommercialAudit;
use App\Erp\ErpCoreModules;
use App\Http\Controllers\Controller;
use App\Http\Resources\Erp\ErpPlanResource;
use App\Models\Erp\ErpPlan;
use App\Models\Erp\ErpPlanModule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ErpPlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = ErpPlan::query()->with('modules')->orderBy('id')->get();

        return response()->json(['data' => ErpPlanResource::collection($plans)->resolve()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePlan($request);
        $plan = DB::transaction(function () use ($request, $data): ErpPlan {
            $plan = ErpPlan::query()->create([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'quotas' => $data['quotas'] ?? null,
            ]);
            $this->syncModules($plan, $data['modules'] ?? []);
            ErpCommercialAudit::log($request, 'erp_plan.create', null, ['plan_id' => $plan->id, 'slug' => $plan->slug]);

            return $plan->load('modules');
        });

        return response()->json(['data' => (new ErpPlanResource($plan))->resolve($request)], 201);
    }

    public function show(ErpPlan $plan): JsonResponse
    {
        return response()->json(['data' => (new ErpPlanResource($plan->load('modules')))->resolve()]);
    }

    public function update(Request $request, ErpPlan $plan): JsonResponse
    {
        $data = $this->validatePlan($request, $plan);
        $plan = DB::transaction(function () use ($request, $plan, $data): ErpPlan {
            $updates = [];
            if (array_key_exists('name', $data)) {
                $updates['name'] = $data['name'];
            }
            if (array_key_exists('quotas', $data)) {
                $updates['quotas'] = $data['quotas'];
            }
            if ($updates !== []) {
                $plan->update($updates);
            }
            if (array_key_exists('modules', $data)) {
                $this->syncModules($plan, $data['modules']);
            }
            ErpCommercialAudit::log($request, 'erp_plan.update', null, ['plan_id' => $plan->id]);

            return $plan->load('modules');
        });

        return response()->json(['data' => (new ErpPlanResource($plan))->resolve($request)]);
    }

    /** @return array<string, mixed> */
    private function validatePlan(Request $request, ?ErpPlan $plan = null): array
    {
        $data = $request->validate([
            'slug' => [$plan === null ? 'required' : 'prohibited', 'string', 'max:255', 'unique:erp_plans,slug'],
            'name' => [$plan ? 'sometimes' : 'required', 'string', 'max:255'],
            'quotas' => ['sometimes', 'nullable', 'array'],
            'quotas.*' => ['integer', 'min:0'],
            'modules' => ['sometimes', 'array'],
            'modules.*.module_key' => ['required', 'string', 'max:64'],
            'modules.*.quota' => ['nullable', 'integer', 'min:0'],
        ]);
        if (isset($data['modules'])) {
            $invalid = ErpCoreModules::validateKeys(array_column($data['modules'], 'module_key'));
            if ($invalid !== []) {
                throw ValidationException::withMessages(['modules' => 'Módulo inválido: '.implode(', ', $invalid)]);
            }
        }

        return $data;
    }

    /** @param  list<array{module_key: string, quota?: int|null}>  $modules */
    private function syncModules(ErpPlan $plan, array $modules): void
    {
        ErpPlanModule::query()->where('erp_plan_id', $plan->id)->delete();
        foreach ($modules as $module) {
            ErpPlanModule::query()->create([
                'erp_plan_id' => $plan->id,
                'module_key' => $module['module_key'],
                'quota' => $module['quota'] ?? null,
            ]);
        }
    }
}
