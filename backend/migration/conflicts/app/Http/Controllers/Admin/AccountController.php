<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexAccountRequest;
use App\Http\Requests\Admin\StoreAccountRequest;
use App\Http\Requests\Admin\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\AdminAccountProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class AccountController extends Controller
{
    public function index(IndexAccountRequest $request): AnonymousResourceCollection
    {
        $filters = $request->validated();
        $accounts = Account::with('subscription.plan')
            ->withCount('members')
            ->search($filters['q'] ?? null)
            ->withStatus($filters['status'] ?? null)
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request, AdminAccountProvisioner $provisioner): JsonResponse
    {
        $result = $provisioner->create($request->provisionerPayload());

        return response()->json([
            'data' => new AccountResource($result['account']),
            'first_access' => $result['first_access'],
        ], 201);
    }

    public function show(Account $account): AccountResource
    {
        Gate::authorize('view', $account);

        $account->load('subscription.plan');
        $account->loadCount('members');

        return new AccountResource($account);
    }

    public function update(UpdateAccountRequest $request, Account $account, AdminAccountProvisioner $provisioner): JsonResponse|AccountResource
    {
        if ($request->has('status') && ! $request->hasAny(['name', 'phone', 'city', 'state', 'login_email', 'owner_name'])) {
            $account->update(['status' => $request->validated('status')]);
            $account->load('subscription.plan');
            $account->loadCount('members');

            return new AccountResource($account);
        }

        $payload = [
            'name' => $request->validated('name'),
            'billing_contact' => $request->billingContact(),
        ];

        if ($request->has('login_email')) {
            $payload = array_merge($payload, $request->accessPayload());
        }

        $result = $provisioner->update($account, $payload);

        return response()->json([
            'data' => new AccountResource($result['account']),
            'first_access' => $result['first_access'],
        ]);
    }
}
