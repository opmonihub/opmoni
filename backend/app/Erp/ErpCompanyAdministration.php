<?php

namespace App\Erp;

use App\Models\Erp\ErpCompany;
use App\Models\Erp\ErpCompanyMembership;
use App\Models\Erp\ErpEstablishment;
use App\Support\AccessSession;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ErpCompanyAdministration
{
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

        return [
            'id' => $membership->id,
            'user' => ['id' => $membership->user->id, 'name' => $membership->user->name, 'email' => $membership->user->email],
            'role' => $membership->role,
            'action_permissions' => $membership->action_permissions,
            'effective_permissions' => $membership->effectivePermissions(),
            'establishment_scope' => 'all',
            'establishment_ids' => [],
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
