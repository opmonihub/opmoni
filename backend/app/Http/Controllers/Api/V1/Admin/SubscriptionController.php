<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Models\Subscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionController extends Controller
{
    public function index(IndexSubscriptionRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $subscriptions = Subscription::with(['account', 'plan'])
            ->search($filters['q'] ?? null)
            ->withStatus($filters['status'] ?? null)
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return SubscriptionResource::collection($subscriptions);
    }

    public function show(Subscription $subscription): SubscriptionResource
    {
        $subscription->load(['account', 'plan']);

        return new SubscriptionResource($subscription);
    }

    public function update(Request $request, Subscription $subscription): SubscriptionResource
    {
        $data = $request->validate([
            'plan_id' => ['sometimes', 'required', 'exists:plans,id'],
            'status' => ['sometimes', 'required', 'in:active,past_due,canceled'],
        ]);

        $subscription->update($data);
        $subscription->load(['account', 'plan']);

        return new SubscriptionResource($subscription);
    }
}
