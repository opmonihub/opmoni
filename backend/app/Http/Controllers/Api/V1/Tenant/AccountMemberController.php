<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\MemberDirectoryResource;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Services\PlanLimits;
use App\Services\SupportAudit;
use App\Tenant\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AccountMemberController extends Controller
{
    public function index(): JsonResponse
    {
        $account = $this->tenantAccount();
        Gate::authorize('manageMembers', $account);

        return response()->json($this->presentMembers($account));
    }

    public function store(Request $request): JsonResponse
    {
        $account = $this->tenantAccount();
        Gate::authorize('manageMembers', $account);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:254', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
            'role' => ['required', 'in:admin,operador,user'],
        ]);

        return DB::transaction(function () use ($request, $account, $data): JsonResponse {
            Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            PlanLimits::assertCanCreate($account, 'users');
            $member = User::create([
                'name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'],
            ]);
            $account->members()->attach($member, [
                'role' => $data['role'], 'inviter_id' => $request->user()->getKey(),
            ]);
            SupportAudit::logWrite($request, 'members', 'create', $member->getKey(), [
                'email' => $member->email, 'role' => $data['role'],
            ]);

            return response()->json($this->presentMember($member, $data['role']), 201);
        });
    }

    public function directory(): JsonResponse
    {
        $account = $this->tenantAccount();
        Gate::authorize('viewMembers', $account);

        $rows = $account->members()->with('departments')->orderBy('name')->get();

        return response()->json(['data' => MemberDirectoryResource::collection($rows)->toArray(request())]);
    }

    public function show(User $member): JsonResponse
    {
        $account = $this->tenantAccount();
        Gate::authorize('manageMembers', $account);

        $role = $member->accountRole($account);

        if ($role === null) {
            abort(404);
        }

        return response()->json($this->presentMember($member, $role));
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $account = $this->tenantAccount();
        Gate::authorize('manageMembers', $account);
        $data = $request->validate(['role' => ['required', 'in:admin,operador,user']]);

        return DB::transaction(function () use ($request, $account, $member, $data): JsonResponse {
            Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $membership = AccountMembership::query()->where('account_id', $account->id)->where('user_id', $member->id)->lockForUpdate()->first();
            abort_unless($membership, 404);
            $this->assertLastAdmin($account, $membership, $data['role']);
            $membership->update(['role' => $data['role']]);
            SupportAudit::logWrite($request, 'members', 'update', $member->getKey(), ['role' => $data['role']]);

            return response()->json($this->presentMember($member, $data['role']));
        });
    }

    public function destroy(Request $request, User $member): Response
    {
        $account = $this->tenantAccount();
        Gate::authorize('manageMembers', $account);

        return DB::transaction(function () use ($request, $account, $member): Response {
            Account::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $membership = AccountMembership::query()->where('account_id', $account->id)->where('user_id', $member->id)->lockForUpdate()->first();
            abort_unless($membership, 404);
            $this->assertLastAdmin($account, $membership, null);
            $membership->delete();
            SupportAudit::logWrite($request, 'members', 'delete', $member->getKey(), ['email' => $member->email]);

            return response()->noContent();
        });
    }

    private function assertLastAdmin(Account $account, AccountMembership $membership, ?string $nextRole): void
    {
        if ($membership->role === 'admin' && $nextRole !== 'admin' && ! AccountMembership::query()->where('account_id', $account->id)->where('role', 'admin')->whereKeyNot($membership->id)->exists()) {
            throw ValidationException::withMessages(['role' => ['O escritório precisa manter pelo menos um administrador.']]);
        }
    }

    private function tenantAccount(): Account
    {
        return Account::findOrFail(resolve(CurrentTenant::class)->accountId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function presentMembers(Account $account): array
    {
        return $account->members()->get()->map(fn (User $member): array => $this->presentMember(
            $member,
            $member->pivot->role
        ))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMember(User $member, string $role): array
    {
        return [
            'id' => $member->getKey(),
            'name' => $member->name,
            'email' => $member->email,
            'role' => $role,
        ];
    }
}
