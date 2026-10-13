<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AccessAccounts;
use App\Http\Controllers\Controller;
use App\Http\Requests\AccessRequest;
use App\Http\Resources\IdentityResource;
use App\Models\Account;
use App\Models\AccountMembership;
use App\Models\User;
use App\Support\AccessSession;
use App\Support\AccessTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccessController extends Controller
{
    public function __construct(private AccessAccounts $actions) {}

    public function csrf(Request $request): JsonResponse
    {
        return response()->json(['csrf_token' => $request->session()->token()]);
    }

    public function registrationStatus(): JsonResponse
    {
        return response()->json(['registration_available' => ! User::exists() && ! Account::exists()]);
    }

    private function identity(Request $request, int $status = 200): JsonResponse
    {
        if ($request->session()->get('current_account_id') === null) {
            AccessSession::assertOwnership($request);
            $request->session()->put('current_account_id', $request->user()->defaultAccountId());
        }

        return response()->json((new IdentityResource($request->user()))->resolve($request), $status);
    }

    public function register(AccessRequest $request): JsonResponse
    {
        [$user,$account] = $this->actions->bootstrap($request->validated());
        AccessSession::authenticate($request, $user, $account->id);

        return $this->identity($request, 201);
    }

    public function login(AccessRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = User::where('email', $data['email'])->first();
        $dummy = '$argon2id$v=19$m=65536,t=2,p=1$QWxwaGFCZXRhR2FtbWFEZQ$LAoqYUN2TrYY82BVhXA/LAihsqHFvlalWNKBJVqsMXA';
        $hash = $user?->password ?? $dummy;
        $valid = match (password_get_info($hash)['algoName']) {
            'bcrypt' => strlen($data['password']) <= 72 && Hash::driver('bcrypt')->check($data['password'], $hash),
            'argon2id' => Hash::check($data['password'], $hash),
            default => false,
        };
        if (! $user || ! $valid) {
            throw ValidationException::withMessages(['email' => 'Email ou senha inválidos']);
        } if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($data['password']);
            $user->save();
        }

        AccessSession::authenticate($request, $user, $user->defaultAccountId());

        return $this->identity($request);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->identity($request);
    }

    public function logout(Request $request): Response
    {
        AccessSession::revoke($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(['access_logout' => true, 'access_deadline' => time() + 1800]);

        return response()->noContent();
    }

    public function switchAccount(AccessRequest $request): JsonResponse
    {
        $id = (int) $request->validated('account_id');
        DB::transaction(function () use ($request, $id) {
            AccessTransaction::boundLockWaits();
            $account = Account::lockForUpdate()->find($id);
            $membership = AccountMembership::where('account_id', $id)->where('user_id', $request->user()->id)->lockForUpdate()->first();
            abort_unless($account && $membership, 403, 'Acesso ao escritório negado');
            if ($account->status !== 'active') {
                abort(response()->json(['code' => 'account_suspended', 'message' => 'Escritório suspenso'], 403));
            } AccessSession::assertOwnership($request);
            $request->session()->put('current_account_id', $id);
            $request->session()->regenerateToken();
        });

        return $this->identity($request);
    }

    public function activationStatus(AccessRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (! is_string($data['token'] ?? null) || strlen($data['token']) !== 64 || ! is_string($data['email'] ?? null)) {
            return response()->json(['valid' => false]);
        }

        $link = $this->actions->validLink($data);
        $account = $link ? Account::find($link->account_id) : null;

        return response()->json($account?->status === 'active' ? ['valid' => true, 'owner_name' => $link->owner_name, 'account_name' => $account->name] : ['valid' => false]);
    }

    public function activate(AccessRequest $request): JsonResponse
    {
        [$user,$account] = $this->actions->activate($request->validated());
        AccessSession::authenticate($request, $user, $account->id);

        return $this->identity($request, 201);
    }

    public function adminList(AccessRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = Account::query()->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        if (isset($data['q']) && $data['q'] !== '') {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']);
            $query->where(function ($q) use ($data, $search) {
                $q->whereRaw("name LIKE ? ESCAPE '\\'", ['%'.$search.'%']);
                if (ctype_digit($data['q'])) {
                    $q->orWhere('id', (int) $data['q']);
                }
            });
        }

        $page = $query->orderByDesc('id')->paginate(15, ['*'], 'page', (int) ($data['page'] ?? 1));

        return response()->json(['data' => $page->getCollection()->map(fn ($a) => $this->actions->projection($a))->all(), 'meta' => ['page' => $page->currentPage(), 'per_page' => 15, 'total' => $page->total()]]);
    }

    public function adminCreate(AccessRequest $request): JsonResponse
    {
        [$account,$link] = $this->actions->issue($request->validated());

        return response()->json(['data' => $this->actions->projection($account), 'first_access' => $link], 201);
    }

    public function adminUpdate(AccessRequest $request, int $id): JsonResponse
    {
        $account = DB::transaction(function () use ($request, $id) {
            AccessTransaction::boundLockWaits();
            $account = Account::lockForUpdate()->findOrFail($id);
            AccessSession::assertOwnership($request);
            $account->update($request->validated());
            AccessSession::assertOwnership($request);

            return $account;
        });

        return response()->json(['data' => $this->actions->projection($account)]);
    }

    public function adminReissue(AccessRequest $request, int $id): JsonResponse
    {
        [, $link] = $this->actions->issue($request->validated(), $id);

        return response()->json(['first_access' => $link]);
    }
}
