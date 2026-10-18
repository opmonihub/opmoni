<?php

namespace App\Erp;

use App\Exceptions\CompanyAccessBusy;
use App\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class CompanyAccessFence
{
    public function acquire(int $accountId): ?Account
    {
        try {
            return DB::transaction(fn () => Account::query()->whereKey($accountId)->lock(DB::getDriverName() === 'pgsql' ? 'FOR UPDATE NOWAIT' : true)->first());
        } catch (QueryException $error) {
            if (($error->errorInfo[0] ?? null) === '55P03') {
                throw new CompanyAccessBusy;
            }
            throw $error;
        }
    }
}
