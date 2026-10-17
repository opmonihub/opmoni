<?php

namespace App\Services;

use App\Models\Department;
use Illuminate\Database\Query\JoinClause;
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
            ->join('account_memberships', function (JoinClause $join): void {
                $join->on('account_memberships.account_id', '=', 'department_user.account_id')
                    ->on('account_memberships.user_id', '=', 'department_user.user_id');
            })
            ->join('departments', function (JoinClause $join): void {
                $join->on('departments.id', '=', 'department_user.department_id')
                    ->on('departments.account_id', '=', 'department_user.account_id');
            })
            ->where('department_user.account_id', $accountId)
            ->where('department_user.department_id', $departmentId)
            ->where('department_user.user_id', $userId)
            ->exists();
    }
}
