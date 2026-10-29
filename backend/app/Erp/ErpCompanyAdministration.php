<?php

namespace App\Erp;

use App\Actions\AccessAccounts;
use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Models\User;
use App\Support\AccessSession;
use App\Support\AccessTransaction;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ErpCompanyAdministration
{
    /** Acquire identity locks before ResolveCompany takes its aggregate lock. */
    public function prepareMemberIdentityLocks(Request $request, int $companyId): void
    {
        AccessTransaction::boundLockWaits();
        abort_unless(ErpCompanyMembership::query()->where('erp_company_id', $companyId)
            ->where('user_id', $request->user()->id)->where('role', 'owner')->exists(), 403);
        if ($request->routeIs('erp.members.store')) {
            $targetId = $request->input('user_id');
            if ((! is_int($targetId) && ! (is_string($targetId) && ctype_digit($targetId)))
                || (int) $targetId < 1 || (int) $targetId > 9007199254740991) {
                throw ValidationException::withMessages(['user_id' => 'Identidade inválida']);
            }
        } else {
            $targetId = ErpCompanyMembership::query()->where('erp_company_id', $companyId)
                ->whereKey($request->route('membership'))->firstOrFail()->user_id;
        }
        $ids = array_values(array_unique([(int) $request->user()->id, (int) $targetId]));
        sort($ids, SORT_NUMERIC);
        $identities = User::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'email']);
        if ($identities->count() !== count($ids)) {
            throw ValidationException::withMessages(['user_id' => 'Identidade inválida']);
        }
        $emails = $identities->map(fn (User $user): string => mb_strtolower(trim($user->email)))->unique()->sort()->values();
        foreach ($emails as $email) {
            app(AccessAccounts::class)->advisory('email:'.$email);
        }
        $locked = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($locked->count() === count($ids)
            && $locked->pluck('email', 'id')->all() === $identities->pluck('email', 'id')->all(), 409);
    }

    public function assertOwner(Request $request): ErpCompanyMembership
    {
        $context = CurrentCompany::fromRequest($request);
        abort_unless($context->accessKind === 'direct', 403, 'Permissão insuficiente');
        $membership = ErpCompanyMembership::query()->where('erp_company_id', $context->company->id)->where('user_id', $request->user()->id)->first();
        abort_unless($membership?->roleEnum()->canManageMemberships(), 403, 'Permissão insuficiente');

        return $membership;
    }

    /** @param Closure(int): Response $operation */
    public function mutate(Request $request, bool $ownerOnly, Closure $operation): Response
    {
        return DB::transaction(function () use ($request, $ownerOnly, $operation): Response {
            $context = CurrentCompany::fromRequest($request);
            $company = ErpCompany::query()->lockForUpdate()->findOrFail($context->company->id);
            abort_unless($company->status === 'active', 403);
            AccessSession::assertOwnership($request);
            if ($ownerOnly) {
                $this->assertOwner($request);
            } else {
                $access = app(ErpCompanyAuthorization::class)->resolveAccess($request->user(), $company, $context->delegatedOfficeId, true);
                abort_unless($access !== null && $access['kind'] === $context->accessKind, 403);
                $fresh = new CurrentCompany($company, $access['kind'], $access['membership'] ?? null, $access['delegation'] ?? null, $access['office_id'] ?? null, $access['office_role'] ?? null);
                $permission = (string) $request->route()->getName();
                if ($fresh->membership?->role === 'operator') {
                    abort_unless(in_array($permission, $fresh->membership->action_permissions ?? [], true), 403, 'Permissão insuficiente');
                }
                $fresh->assertPermission($permission, true);
            }
            $response = $operation($company->id);
            AccessSession::assertOwnership($request);

            return $response;
        });
    }

    public function member(int $companyId, int $membership, bool $lock = false): ErpCompanyMembership
    {
        $query = ErpCompanyMembership::query()->where('erp_company_id', $companyId)->with('user:id,name,email');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($membership);
    }

    public function establishment(int $companyId, int $establishment, bool $lock = false): ErpEstablishment
    {
        $query = ErpEstablishment::query()->where('erp_company_id', $companyId);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->findOrFail($establishment);
    }

    /** @return array<string, mixed> */
    public function memberPayload(ErpCompanyMembership $membership): array
    {
        $membership->loadMissing('user:id,name,email');
        $hasAllEstablishmentAccess = $membership->hasAllEstablishmentAccess();
        $establishmentIds = $hasAllEstablishmentAccess
            ? []
            : $membership->establishments()->pluck('erp_establishments.id')->map(fn ($id): int => (int) $id)->all();

        return [
            'id' => $membership->id,
            'user' => ['id' => $membership->user->id, 'name' => $membership->user->name, 'email' => $membership->user->email],
            'role' => $membership->role,
            'action_permissions' => $membership->action_permissions,
            'effective_permissions' => $membership->effectivePermissions(),
            'establishment_scope' => $hasAllEstablishmentAccess ? 'all' : 'selected',
            'establishment_ids' => $establishmentIds,
        ];
    }

    /** @return array{id: int, name: string, cnpj: ?string, status: string} */
    public function establishmentPayload(ErpEstablishment $establishment): array
    {
        return ['id' => $establishment->id, 'name' => $establishment->name, 'cnpj' => $establishment->cnpj, 'status' => $establishment->status];
    }

    /** @param list<string> $allowed */
    public function assertOnlyFields(Request $request, array $allowed): void
    {
        $unexpected = array_diff(array_keys($request->all()), $allowed);
        if ($unexpected !== []) {
            throw ValidationException::withMessages(array_fill_keys($unexpected, 'Campo não permitido'));
        }
    }

    /** @return array<string, mixed> */
    public function listParams(Request $request, bool $withStatus = false): array
    {
        return $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            ...($withStatus ? ['status' => ['sometimes', 'in:active,inactive,all']] : []),
        ]);
    }
}
