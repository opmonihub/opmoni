<?php

namespace App\Erp\Commercial;

use App\Enums\Erp\ErpCompanyRole;
use App\Erp\ErpCompanyMemberGrants;
use App\Erp\ErpCompanyPermissions;
use App\Models\Erp\Commercial\OfficeErpCommercialOperation;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use App\Support\AccessSession;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class OfficeErpCompanyMembers
{
    public function __construct(
        private readonly OfficeErpMemberAuthority $authority,
        private readonly ErpCompanyMemberGrants $grants,
        private readonly OfficeErpOperations $operations,
    ) {}

    /** @return array<string, mixed> */
    public function index(OfficeErpExecutionContext $ctx, int $companyId, array $input): array
    {
        $access = $this->authority->resolve($ctx, $companyId);
        $this->assertOnlyFields($input, ['page', 'per_page', 'search', 'status']);
        $filters = Validator::make($input, [
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['active', 'pending', 'all'])],
        ])->validate();
        $perPage = (int) ($filters['per_page'] ?? 25);
        $pageNumber = (int) ($filters['page'] ?? 1);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $membershipRows = DB::table('erp_company_memberships as membership')
            ->join('users as member_user', 'member_user.id', '=', 'membership.user_id')
            ->where('membership.erp_company_id', $companyId)
            ->when($status === 'pending', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($search !== '', function (Builder $query) use ($search): Builder {
                $name = $query->getGrammar()->wrap('member_user.name');
                $email = $query->getGrammar()->wrap('member_user.email');
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);

                return $query->where(fn (Builder $matches): Builder => $matches
                    ->whereRaw("LOWER({$name}) LIKE LOWER(?) ESCAPE '!'", ["%{$escaped}%"])
                    ->orWhereRaw("LOWER({$email}) LIKE LOWER(?) ESCAPE '!'", ["%{$escaped}%"]));
            })
            ->selectRaw("'membership' as kind, membership.id as target_id, membership.user_id, member_user.name, member_user.email, membership.role, membership.action_permissions as permissions, membership.access_all_establishments as access_all, NULL as establishment_ids, 'active' as status, NULL as expires_at");
        $invitationRows = DB::table('erp_company_member_invitations as invitation')
            ->where('invitation.account_id', $ctx->accountId)->where('invitation.erp_company_id', $companyId)
            ->where('invitation.purpose', 'member_access')->where('invitation.status', 'pending')->where('invitation.expires_at', '>', now())
            ->when($status === 'active', fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($search !== '', function (Builder $query) use ($search): Builder {
                $name = $query->getGrammar()->wrap('invitation.name');
                $email = $query->getGrammar()->wrap('invitation.email');
                $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);

                return $query->where(fn (Builder $matches): Builder => $matches
                    ->whereRaw("LOWER({$name}) LIKE LOWER(?) ESCAPE '!'", ["%{$escaped}%"])
                    ->orWhereRaw("LOWER({$email}) LIKE LOWER(?) ESCAPE '!'", ["%{$escaped}%"]));
            })
            ->selectRaw("'invitation' as kind, invitation.id as target_id, NULL as user_id, invitation.name, invitation.email, invitation.role, invitation.permissions as permissions, (invitation.establishment_scope = 'all') as access_all, invitation.establishment_ids, 'pending' as status, invitation.expires_at");
        $union = $membershipRows->unionAll($invitationRows);
        $query = DB::query()->fromSub($union, 'access_rows')->orderBy('name')->orderBy('kind')->orderBy('target_id');
        $paginator = $query->paginate($perPage, ['*'], 'page', $pageNumber);
        $memberships = $paginator->getCollection()->where('kind', 'membership')->pluck('target_id')->map(intval(...))->all();
        $models = ErpCompanyMembership::query()->with('user:id,name,email,access_auth_revision')->where('erp_company_id', $companyId)->whereIn('id', $memberships)->get()->keyBy('id');
        $data = $paginator->getCollection()->map(function (object $row) use ($models): array {
            if ($row->kind === 'membership') {
                $membership = $models->get((int) $row->target_id);
                abort_unless($membership !== null, 409);

                return $this->membershipPayload($membership);
            }

            $ids = is_array($row->establishment_ids) ? $row->establishment_ids : json_decode((string) $row->establishment_ids, true);

            return [
                'kind' => 'invitation', 'id' => (int) $row->target_id,
                'name' => (string) $row->name, 'email' => (string) $row->email,
                'role' => (string) $row->role, 'permissions_mode' => 'explicit',
                'action_permissions' => $this->decodeList($row->permissions),
                'effective_permissions' => $this->effectivePermissions((string) $row->role, $this->decodeList($row->permissions)),
                'establishment_scope' => (bool) $row->access_all ? 'all' : 'selected',
                'establishment_ids' => (bool) $row->access_all ? [] : array_map(intval(...), is_array($ids) ? $ids : []),
                'status' => 'pending', 'expires_at' => (string) $row->expires_at,
            ];
        })->values()->all();

        return ['data' => $data, 'meta' => [
            'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
        ], 'capabilities' => ['can_manage' => true]];
    }

    /** @return array<string, mixed> */
    public function show(OfficeErpExecutionContext $ctx, int $companyId, int $membershipId): array
    {
        $this->authority->resolve($ctx, $companyId, $membershipId);
        $membership = ErpCompanyMembership::query()->with('user:id,name,email,access_auth_revision')->where('erp_company_id', $companyId)->findOrFail($membershipId);

        return $this->membershipPayload($membership);
    }

    /** @return array<string, mixed> */
    public function options(OfficeErpExecutionContext $ctx, int $companyId): array
    {
        $this->authority->resolve($ctx, $companyId);
        $establishments = ErpEstablishment::query()->where('erp_company_id', $companyId)->orderBy('id')->get();

        return ['data' => [
            'roles' => array_map(static fn (string $role): array => ['role' => $role, 'default_permissions' => ErpCompanyRole::from($role)->defaultPermissions()], ['manager', 'operator', 'viewer']),
            'permissions' => ErpCompanyPermissions::catalog(),
            'establishments' => $establishments->map(static fn (ErpEstablishment $establishment): array => [
                'id' => (int) $establishment->id, 'name' => (string) $establishment->name,
                'cnpj' => $establishment->cnpj, 'status' => (string) $establishment->status,
            ])->all(),
        ]];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function update(OfficeErpExecutionContext $ctx, int $companyId, int $membershipId, array $input, string $key): array
    {
        $changes = $this->validateUpdate($input);
        $payload = ['company_id' => $companyId, 'membership_id' => $membershipId, 'changes' => $changes];
        $outcome = $this->operations->execute($ctx, $key, 'member.update', $payload,
            fn (string $action, array $stored) => $this->authorizeOperation($ctx, $action, $stored, $key),
            function () use ($ctx, $companyId, $membershipId, $changes): array {
                $resolved = $this->authority->resolve($ctx, $companyId, $membershipId, true);
                $membership = $resolved['membership'];
                $this->assertProtectedTarget($ctx, $resolved['user']);
                abort_if($membership->role === 'owner', 403, 'company_owner_protected');
                $scope = $changes['establishment_scope'] ?? ($membership->hasAllEstablishmentAccess() ? 'all' : 'selected');
                $ids = $changes['establishment_ids'] ?? ($scope === 'all'
                    ? []
                    : $membership->establishments()->pluck('erp_establishments.id')->all());
                $grants = $this->grants->normalize(
                    $changes['role'] ?? (string) $membership->role,
                    array_key_exists('action_permissions', $changes) ? $changes['action_permissions'] : $membership->action_permissions,
                    $scope,
                    $ids,
                );
                $this->grants->assertEstablishments($companyId, $grants['establishment_ids'], true);
                $membership->forceFill([
                    'role' => $grants['role'], 'action_permissions' => $grants['action_permissions'],
                    'access_all_establishments' => $grants['establishment_scope'] === 'all',
                ])->save();
                $membership->establishments()->sync(array_fill_keys($grants['establishment_ids'], ['erp_company_id' => $companyId]));
                AccessSession::assertOwnership(request());

                return ['data' => $this->membershipPayload($membership->fresh()->load('user:id,name,email,access_auth_revision'))];
            });

        return $outcome['data'];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function remove(OfficeErpExecutionContext $ctx, int $companyId, int $membershipId, array $input, string $key): array
    {
        $this->assertOnlyFields($input, ['confirmed']);
        if (($input['confirmed'] ?? false) !== true) {
            throw ValidationException::withMessages(['confirmed' => 'Confirme a remoção deste acesso.']);
        }
        $payload = ['company_id' => $companyId, 'membership_id' => $membershipId, 'confirmed' => true];
        $outcome = $this->operations->execute($ctx, $key, 'member.remove', $payload,
            fn (string $action, array $stored) => $this->authorizeOperation($ctx, $action, $stored, $key),
            function () use ($ctx, $companyId, $membershipId): array {
                $resolved = $this->authority->resolve($ctx, $companyId, $membershipId, true);
                $membership = $resolved['membership'];
                $this->assertProtectedTarget($ctx, $resolved['user']);
                abort_if($membership->role === 'owner', 403, 'company_owner_protected');
                app(OfficeErpMemberCredentials::class)->invalidateRecoveryForMembership($ctx, $membershipId, (int) $resolved['user']->id);
                $membership->delete();
                AccessSession::assertOwnership(request());

                return ['data' => ['company_id' => $companyId, 'membership_id' => $membershipId, 'status' => 'removed']];
            });

        return $outcome['data'];
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    public function validateInvitation(array $input): array
    {
        $this->assertOnlyFields($input, ['name', 'email', 'role', 'permissions_mode', 'permissions', 'establishment_scope', 'establishment_ids']);
        if (is_string($input['email'] ?? null)) {
            $input['email'] = mb_strtolower(trim($input['email']));
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'role' => ['required', Rule::in(['manager', 'operator', 'viewer'])],
            'permissions_mode' => ['sometimes', Rule::in(['defaults', 'explicit'])],
            'permissions' => ['sometimes', 'array', 'list', 'max:200'],
            'permissions.*' => ['string', 'distinct', Rule::in(ErpCompanyPermissions::names())],
            'establishment_scope' => ['required', Rule::in(['all', 'selected'])],
            'establishment_ids' => ['sometimes', 'array', 'list', 'max:200'],
            'establishment_ids.*' => ['integer', 'min:1', 'max:9007199254740991', 'distinct'],
        ])->validate();
        if ($data['establishment_scope'] === 'selected' && ! array_key_exists('establishment_ids', $data)) {
            throw ValidationException::withMessages(['establishment_ids' => 'Informe a lista dos estabelecimentos selecionados.']);
        }
        if ($data['establishment_scope'] === 'selected' && $data['establishment_ids'] === []) {
            throw ValidationException::withMessages(['establishment_ids' => 'Selecione ao menos um estabelecimento.']);
        }
        $mode = $data['permissions_mode'] ?? 'defaults';
        $permissions = $mode === 'defaults' ? null : ($data['permissions'] ?? []);
        if ($mode === 'defaults' && ($data['permissions'] ?? []) !== []) {
            throw ValidationException::withMessages(['permissions' => 'Permissões explícitas não podem acompanhar o modo padrão.']);
        }
        if ($mode === 'explicit' && ($data['permissions'] ?? []) === []) {
            throw ValidationException::withMessages(['permissions' => 'Informe ao menos uma permissão explícita.']);
        }
        $grants = $this->grants->normalize($data['role'], $permissions, $data['establishment_scope'], $data['establishment_ids'] ?? []);

        return [
            'name' => trim($data['name']), 'email' => mb_strtolower(trim($data['email'])),
            'role' => $grants['role'], 'permissions_mode' => $mode,
            'permissions' => $grants['action_permissions'] ?? [],
            'establishment_scope' => $grants['establishment_scope'], 'establishment_ids' => $grants['establishment_ids'],
        ];
    }

    /** @param array<string, mixed> $payload */
    public function authorizeOperation(OfficeErpExecutionContext $ctx, string $action, array $payload, ?string $operationKey = null): void
    {
        $companyId = $payload['company_id'] ?? null;
        abort_unless(in_array($action, ['member.update', 'member.remove'], true) && is_int($companyId) && is_int($payload['membership_id'] ?? null), 409);
        if ($action === 'member.remove') {
            $membership = ErpCompanyMembership::query()->where('erp_company_id', $companyId)->whereKey($payload['membership_id'])->first();
            if ($membership === null) {
                $this->authority->resolve($ctx, $companyId, null, true);
                abort_unless(is_string($operationKey) && $operationKey !== '', 404);
                $operation = OfficeErpCommercialOperation::query()->where('account_id', $ctx->accountId)
                    ->where('actor_kind', $ctx->actorKind)->where('actor_identity', $ctx->actorIdentity)
                    ->where('idempotency_key', $operationKey)->where('action', 'member.remove')->first();
                abort_unless(($operation?->outcome['data']['company_id'] ?? null) === $companyId
                    && ($operation?->outcome['data']['membership_id'] ?? null) === $payload['membership_id']
                    && ($operation?->outcome['data']['status'] ?? null) === 'removed', 404);

                return;
            }
            $resolved = $this->authority->resolve($ctx, $companyId, $payload['membership_id'], true);
            $this->assertProtectedTarget($ctx, $resolved['user']);
            abort_if($resolved['membership']->role === 'owner', 403, 'company_owner_protected');

            return;
        }
        $resolved = $this->authority->resolve($ctx, $companyId, $payload['membership_id'], true);
        $this->assertProtectedTarget($ctx, $resolved['user']);
        abort_if($resolved['membership']->role === 'owner', 403, 'company_owner_protected');
    }

    /** @return array<string, mixed> */
    private function validateUpdate(array $input): array
    {
        $this->assertOnlyFields($input, ['role', 'permissions_mode', 'action_permissions', 'establishment_scope', 'establishment_ids']);
        if ($input === []) {
            throw ValidationException::withMessages(['member' => 'Informe ao menos uma alteração.']);
        }
        $data = Validator::make($input, [
            'role' => ['sometimes', Rule::in(['manager', 'operator', 'viewer'])],
            'permissions_mode' => ['sometimes', Rule::in(['defaults', 'explicit'])],
            'action_permissions' => ['sometimes', 'nullable', 'array', 'list', 'max:200'],
            'action_permissions.*' => ['string', 'distinct', Rule::in([...ErpCompanyPermissions::names(), '*'])],
            'establishment_scope' => ['sometimes', Rule::in(['all', 'selected'])],
            'establishment_ids' => ['sometimes', 'array', 'list', 'max:200'],
            'establishment_ids.*' => ['integer', 'min:1', 'max:9007199254740991', 'distinct'],
        ])->validate();
        if (($data['permissions_mode'] ?? null) === 'defaults' && ($data['action_permissions'] ?? []) !== []) {
            throw ValidationException::withMessages(['action_permissions' => 'Permissões explícitas não podem acompanhar o modo padrão.']);
        }
        if (($data['permissions_mode'] ?? null) === 'explicit' && ($data['action_permissions'] ?? []) === []) {
            throw ValidationException::withMessages(['action_permissions' => 'Informe ao menos uma permissão explícita.']);
        }
        if (array_key_exists('establishment_scope', $data) !== array_key_exists('establishment_ids', $data)
            && ($data['establishment_scope'] ?? null) !== 'all') {
            throw ValidationException::withMessages(['establishment_ids' => 'Informe o escopo e a lista de estabelecimentos juntos.']);
        }
        if (($data['establishment_scope'] ?? null) === 'selected' && ($data['establishment_ids'] ?? []) === []) {
            throw ValidationException::withMessages(['establishment_ids' => 'Selecione ao menos um estabelecimento.']);
        }

        return $data;
    }

    private function assertProtectedTarget(OfficeErpExecutionContext $ctx, User $user): void
    {
        abort_unless((int) $user->id !== (int) $ctx->actor?->id, 403, 'protected_company_member');
        abort_if(DB::table('account_memberships')->where('account_id', $ctx->accountId)->where('user_id', $user->id)->where('role', 'admin')->exists(), 403, 'protected_company_member');
    }

    /** @return array<string, mixed> */
    private function membershipPayload(ErpCompanyMembership $membership): array
    {
        $membership->loadMissing('user:id,name,email,access_auth_revision');
        $all = $membership->hasAllEstablishmentAccess();
        $establishmentIds = $all ? [] : $membership->establishments()->pluck('erp_establishments.id')->map(intval(...))->all();

        return [
            'kind' => 'membership', 'id' => (int) $membership->id,
            'user' => ['id' => (int) $membership->user->id, 'name' => (string) $membership->user->name, 'email' => (string) $membership->user->email],
            'access_auth_revision' => (int) $membership->user->access_auth_revision,
            'role' => (string) $membership->role, 'action_permissions' => $membership->action_permissions,
            'effective_permissions' => $membership->effectivePermissions(),
            'establishment_scope' => $all ? 'all' : 'selected', 'establishment_ids' => $establishmentIds,
            'status' => 'active',
        ];
    }

    /** @param mixed $value @return list<string> */
    private function decodeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(strval(...), $value));
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [];
    }

    /** @param list<string> $permissions @return list<string> */
    private function effectivePermissions(string $role, array $permissions): array
    {
        if ($permissions !== []) {
            return $permissions;
        }

        return ErpCompanyRole::from($role)->defaultPermissions();
    }

    /** @param array<string, mixed> $input @param list<string> $allowed */
    private function assertOnlyFields(array $input, array $allowed): void
    {
        $unexpected = array_diff(array_keys($input), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'Campo não permitido.'));
        }
    }
}
