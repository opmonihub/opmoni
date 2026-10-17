<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Http\Resources\Erp\ErpSubscriptionResource;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ErpSubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var CurrentCompany $context */
        $context = $request->attributes->get(CurrentCompany::class);
        $subscription = $context->company->subscription()->with(['plan.modules'])->first();
        abort_unless($subscription, 404);

        return response()->json(['data' => (new ErpSubscriptionResource($subscription))->resolve($request)])
            ->header('Cache-Control', 'no-store');
    }
}
