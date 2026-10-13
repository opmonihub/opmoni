<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);

        $plan = Plan::create($data);

        return response()->json($plan, 201);
    }

    public function show(Plan $plan): JsonResponse
    {
        return response()->json($plan);
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'limits' => ['sometimes', 'array'],
        ]);

        $plan->update($data);

        return response()->json($plan->refresh());
    }
}
