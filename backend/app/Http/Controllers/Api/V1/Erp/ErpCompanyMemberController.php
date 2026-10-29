<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\ErpCompanyAdministration;
use App\Erp\ErpCompanyMemberGrants;
use App\Erp\ErpCompanyPermissions;
use App\Http\Controllers\Controller;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ErpCompanyMemberController extends Controller
{
    public function __construct(private ErpCompanyAdministration $administration, private ErpCompanyMemberGrants $grants) {}

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
            $data = $this->normalizeMemberGrants($data, $companyId, null, true);
            if (! User::query()->whereKey($data['user_id'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Identidade inválida']);
            }
            abort_if(ErpCompanyMembership::query()->where('erp_company_id', $companyId)->where('user_id', $data['user_id'])->exists(), 409, 'Usuário já associado à empresa');
            $membership = ErpCompanyMembership::query()->create([
                'erp_company_id' => $companyId,
                'user_id' => $data['user_id'],
                'role' => $data['role'],
                'action_permissions' => $data['action_permissions'] ?? null,
                'access_all_establishments' => $data['establishment_scope'] === 'all',
            ]);
            $this->syncEstablishments($membership, $companyId, $data);

            return response()->json(['data' => $this->administration->memberPayload($membership)], 201);
        });
    }

    public function update(Request $request, int $membership): Response
    {
        return $this->administration->mutate($request, true, function (int $companyId) use ($request, $membership): JsonResponse {
            $member = $this->administration->member($companyId, $membership, true);
            abort_if($member->role === 'owner', 403, 'Proprietário não pode ser alterado');
            $data = $this->memberData($request, false);
            $data = $this->normalizeMemberGrants($data, $companyId, $member, false);
            $member->fill(array_intersect_key($data, array_flip(['role', 'action_permissions'])))->save();
            if (array_key_exists('establishment_scope', $data)) {
                $member->access_all_establishments = $data['establishment_scope'] === 'all';
                $member->save();
                $this->syncEstablishments($member, $companyId, $data);
            }

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
        $rawEstablishmentIds = $request->input('establishment_ids');
        if (is_array($rawEstablishmentIds)) {
            foreach ($rawEstablishmentIds as $id) {
                if ((! is_int($id) && ! (is_string($id) && ctype_digit($id))) || (int) $id < 1 || (int) $id > 9007199254740991) {
                    throw ValidationException::withMessages(['establishment_ids' => 'Cada identificador deve ser inteiro positivo seguro']);
                }
            }
        }
        $data = $request->validate([
            'user_id' => [$creating ? 'required' : 'prohibited', 'integer', 'min:1', 'max:9007199254740991'],
            'role' => [$creating ? 'required' : 'sometimes', Rule::in(['manager', 'operator', 'viewer'])],
            'action_permissions' => ['sometimes', 'nullable', 'array', 'list', 'max:200'],
            'action_permissions.*' => ['string', 'distinct', Rule::in(ErpCompanyPermissions::names())],
            'establishment_scope' => [$creating ? 'required' : 'sometimes', Rule::in(['all', 'selected'])],
            'establishment_ids' => ['sometimes', 'array', 'list', 'max:200'],
            'establishment_ids.*' => ['integer', 'min:1', 'max:9007199254740991', 'distinct'],
        ]);
        if (($creating || ($data['establishment_scope'] ?? null) === 'selected')
            && ($data['establishment_scope'] ?? null) === 'selected'
            && ! array_key_exists('establishment_ids', $data)) {
            throw ValidationException::withMessages(['establishment_ids' => 'Informe os estabelecimentos selecionados']);
        }
        if (($data['establishment_scope'] ?? null) === 'all' && ($data['establishment_ids'] ?? []) !== []) {
            throw ValidationException::withMessages(['establishment_ids' => 'Escopo completo não aceita identificadores selecionados']);
        }
        if (! $creating && array_key_exists('establishment_scope', $data) !== array_key_exists('establishment_ids', $data) && ($data['establishment_scope'] ?? null) !== 'all') {
            throw ValidationException::withMessages(['establishment_ids' => 'Informe o escopo e a lista de estabelecimentos juntos']);
        }
        if (! $creating && array_intersect_key($data, array_flip(['role', 'action_permissions', 'establishment_scope'])) === []) {
            throw ValidationException::withMessages(['role' => 'Informe o papel ou as permissões']);
        }

        if (($data['establishment_scope'] ?? null) === 'selected') {
            $ids = array_map(intval(...), $data['establishment_ids'] ?? []);
            $matching = ErpEstablishment::query()->where('erp_company_id', CurrentCompany::fromRequest($request)->company->id)->whereIn('id', $ids)->count();
            if ($matching !== count($ids)) {
                throw ValidationException::withMessages(['establishment_ids' => 'Todos os estabelecimentos devem pertencer à empresa']);
            }
            $data['establishment_ids'] = $ids;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function syncEstablishments(ErpCompanyMembership $membership, int $companyId, array $data): void
    {
        if ($data['establishment_scope'] === 'all') {
            $membership->establishments()->sync([]);

            return;
        }

        $membership->establishments()->sync(array_fill_keys($data['establishment_ids'] ?? [], ['erp_company_id' => $companyId]));
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeMemberGrants(array $data, int $companyId, ?ErpCompanyMembership $current, bool $creating): array
    {
        $role = (string) ($data['role'] ?? $current?->role);
        $roleChanged = $current !== null && array_key_exists('role', $data) && $data['role'] !== $current->role;
        $permissions = array_key_exists('action_permissions', $data)
            ? $data['action_permissions']
            : ($roleChanged ? null : $current?->action_permissions);
        $scope = $data['establishment_scope'] ?? ($current?->hasAllEstablishmentAccess() ? 'all' : 'selected');
        $ids = $data['establishment_ids'] ?? ($scope === 'all'
            ? []
            : ($current?->establishments()->pluck('erp_establishments.id')->all() ?? []));
        $normalized = $this->grants->normalize($role, $permissions, $scope, $ids);
        $this->grants->assertEstablishments($companyId, $normalized['establishment_ids']);

        return array_replace($data, [
            'role' => $normalized['role'],
            'action_permissions' => $normalized['action_permissions'],
            'establishment_scope' => $normalized['establishment_scope'],
            'establishment_ids' => $normalized['establishment_ids'],
        ]);
    }
}
