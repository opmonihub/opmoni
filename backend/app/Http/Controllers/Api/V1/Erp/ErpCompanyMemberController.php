<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\ErpCompanyAdministration;
use App\Erp\ErpCompanyPermissions;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ErpCompanyMemberController extends Controller
{
    public function __construct(private ErpCompanyAdministration $administration) {}

    public function index(Request $request): JsonResponse
    {
        $owner = $this->administration->assertOwner($request);
        $params = $this->administration->listParams($request);
        $query = ErpCompanyMembership::query()->where('erp_company_id', $owner->erp_company_id)->with('user:id,name,email');
        if (($params['search'] ?? '') !== '') {
            $search = '%'.mb_strtolower($params['search']).'%';
            $query->whereHas('user', fn ($users) => $users->where(fn ($names) => $names->whereRaw('LOWER(name) LIKE ?', [$search])->orWhereRaw('LOWER(email) LIKE ?', [$search])));
        }
        $page = $query->orderBy('id')->paginate($params['per_page'] ?? 15);

        return response()->json(['data' => $page->getCollection()->map($this->administration->memberPayload(...))->all(), 'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, int $membership): JsonResponse
    {
        $owner = $this->administration->assertOwner($request);

        return response()->json(['data' => $this->administration->memberPayload($this->administration->member($owner->erp_company_id, $membership))]);
    }

    public function identityLookup(Request $request): JsonResponse
    {
        $this->administration->assertOwner($request);
        $this->administration->assertOnlyFields($request, ['email']);
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [$data['email']])->first(['id', 'name', 'email']);
        abort_unless($user, response()->json(['code' => 'identity_not_found', 'message' => 'Identidade não encontrada'], 404)->header('Cache-Control', 'no-store'));

        return response()->json(['data' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]])->header('Cache-Control', 'no-store');
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->administration->assertOwner($request);

        return response()->json(['data' => ErpCompanyPermissions::catalog(), 'meta' => ['roles' => array_map(fn (ErpCompanyRole $role): array => ['role' => $role->value, 'default_permissions' => $role->defaultPermissions()], [ErpCompanyRole::Manager, ErpCompanyRole::Operator, ErpCompanyRole::Viewer]), 'empty_permissions' => 'role_defaults']]);
    }

    public function store(Request $request): Response
    {
        return $this->administration->mutate($request, true, function (int $companyId) use ($request): JsonResponse {
            $data = $this->memberData($request, true);
            if (! User::query()->whereKey($data['user_id'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Identidade inválida']);
            }
            abort_if(ErpCompanyMembership::query()->where('erp_company_id', $companyId)->where('user_id', $data['user_id'])->exists(), 409, 'Usuário já associado à empresa');
            $membership = ErpCompanyMembership::query()->create(['erp_company_id' => $companyId, 'user_id' => $data['user_id'], 'role' => $data['role'], 'action_permissions' => $data['action_permissions'] ?? null]);

            return response()->json(['data' => $this->administration->memberPayload($membership)], 201);
        });
    }

    public function update(Request $request, int $membership): Response
    {
        return $this->administration->mutate($request, true, function (int $companyId) use ($request, $membership): JsonResponse {
            $member = $this->administration->member($companyId, $membership, true);
            abort_if($member->role === 'owner', 403, 'Proprietário não pode ser alterado');
            $data = $this->memberData($request, false);
            $member->fill(array_intersect_key($data, array_flip(['role', 'action_permissions'])))->save();

            return response()->json(['data' => $this->administration->memberPayload($member)]);
        });
    }

    public function destroy(Request $request, int $membership): Response
    {
        return $this->administration->mutate($request, true, function (int $companyId) use ($request, $membership): Response {
            $member = $this->administration->member($companyId, $membership, true);
            abort_if($member->role === 'owner', 403, 'Proprietário não pode ser removido');
            $this->administration->assertOnlyFields($request, []);
            $member->delete();

            return response()->noContent();
        });
    }

    /** @return array<string, mixed> */
    private function memberData(Request $request, bool $creating): array
    {
        $this->administration->assertOnlyFields($request, $creating ? ['user_id', 'role', 'action_permissions', 'establishment_scope', 'establishment_ids'] : ['role', 'action_permissions', 'establishment_scope', 'establishment_ids']);
        if ($creating && ! is_int($request->input('user_id')) && ! is_string($request->input('user_id'))) {
            throw ValidationException::withMessages(['user_id' => 'Identificador deve ser inteiro ou texto numérico']);
        }
        $data = $request->validate([
            'user_id' => [$creating ? 'required' : 'prohibited', 'integer', 'min:1', 'max:9007199254740991'],
            'role' => [$creating ? 'required' : 'sometimes', Rule::in(['manager', 'operator', 'viewer'])],
            'action_permissions' => ['sometimes', 'nullable', 'array', 'list', 'max:200'],
            'action_permissions.*' => ['string', 'distinct', Rule::in(ErpCompanyPermissions::names())],
            'establishment_scope' => [$creating ? 'required' : 'sometimes', Rule::in(['all'])],
            'establishment_ids' => ['sometimes', 'array', 'list', 'size:0'],
        ]);
        if (! $creating && array_intersect_key($data, array_flip(['role', 'action_permissions'])) === []) {
            throw ValidationException::withMessages(['role' => 'Informe o papel ou as permissões']);
        }

        return $data;
    }
}
