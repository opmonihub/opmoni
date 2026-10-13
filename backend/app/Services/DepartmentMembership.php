<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Support\Facades\DB;

class DepartmentMembership
{
    /**
     * Resolve o id do departamento pelo nome (case-insensitive) dentro da conta.
     */
    public static function findId(int $accountId, ?string $name): ?int
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        return Department::withoutGlobalScopes()
            ->where('account_id', $accountId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])
            ->value('id');
    }

    /**
     * Verifica se o membro pertence ao departamento (via department_user da conta).
     */
    public static function memberBelongs(int $accountId, int $departmentId, int $userId): bool
    {
        return DB::table('department_user')
            ->where('account_id', $accountId)
            ->where('department_id', $departmentId)
            ->where('user_id', $userId)
            ->exists();
    }
}
