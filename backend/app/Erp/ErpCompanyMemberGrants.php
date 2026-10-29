<?php

namespace App\Erp;

use App\Enums\Erp\ErpCompanyRole;
use App\Models\Erp\ErpEstablishment;
use Illuminate\Validation\ValidationException;

final class ErpCompanyMemberGrants
{
    /** @param list<string>|null $permissions @param list<int|string> $establishmentIds @return array{role: string, action_permissions: ?array, establishment_scope: string, establishment_ids: list<int>} */
    public function normalize(string $role, ?array $permissions, string $establishmentScope, array $establishmentIds): array
    {
        if (! in_array($role, [ErpCompanyRole::Manager->value, ErpCompanyRole::Operator->value, ErpCompanyRole::Viewer->value], true)) {
            throw ValidationException::withMessages(['role' => 'Papel não permitido.']);
        }
        if (! in_array($establishmentScope, ['all', 'selected'], true)) {
            throw ValidationException::withMessages(['establishment_scope' => 'Informe um escopo válido.']);
        }

        $ids = [];
        foreach ($establishmentIds as $id) {
            if ((! is_int($id) && (! is_string($id) || ! ctype_digit($id))) || (int) $id < 1 || (int) $id > 9007199254740991) {
                throw ValidationException::withMessages(['establishment_ids' => 'Cada identificador deve ser inteiro positivo seguro.']);
            }
            $ids[] = (int) $id;
        }
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['establishment_ids' => 'Não repita estabelecimentos.']);
        }
        sort($ids, SORT_NUMERIC);
        if ($establishmentScope === 'all' && $ids !== []) {
            throw ValidationException::withMessages(['establishment_ids' => 'Escopo completo não aceita identificadores selecionados.']);
        }
        $permissions = $permissions === null ? null : array_values($permissions);
        if (is_array($permissions)) {
            foreach ($permissions as $permission) {
                if (! is_string($permission) || ! in_array($permission, ErpCompanyPermissions::names(), true)) {
                    throw ValidationException::withMessages(['action_permissions' => 'Uma ou mais permissões não são reconhecidas.']);
                }
            }
            if (count(array_unique($permissions)) !== count($permissions)) {
                throw ValidationException::withMessages(['action_permissions' => 'Não repita permissões.']);
            }
            if ($role === ErpCompanyRole::Viewer->value && array_intersect($permissions, $this->writePermissions()) !== []) {
                throw ValidationException::withMessages(['action_permissions' => 'O papel de consulta não pode receber permissões de alteração.']);
            }
        }

        return [
            'role' => $role,
            'action_permissions' => $permissions === [] ? null : $permissions,
            'establishment_scope' => $establishmentScope,
            'establishment_ids' => $ids,
        ];
    }

    /** @param list<int> $ids */
    public function assertEstablishments(int $companyId, array $ids, bool $lock = false): void
    {
        if ($ids === []) {
            return;
        }
        $query = ErpEstablishment::query()->where('erp_company_id', $companyId)->whereIn('id', $ids);
        $query->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        if ($query->pluck('id')->count() !== count($ids)) {
            throw ValidationException::withMessages(['establishment_ids' => 'Todos os estabelecimentos devem pertencer à empresa.']);
        }
    }

    /** @return list<string> */
    private function writePermissions(): array
    {
        return array_values(array_map(
            static fn (array $entry): string => $entry['permission'],
            array_filter(ErpCompanyPermissions::catalog(), static fn (array $entry): bool => $entry['write']),
        ));
    }
}
