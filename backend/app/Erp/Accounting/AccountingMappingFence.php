<?php

namespace App\Erp\Accounting;

use App\Exceptions\AccountingMappingBusy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class AccountingMappingFence
{
    /** @param Builder<Model> $query */
    public function acquire(Builder $query): ?Model
    {
        try {
            return DB::transaction(fn () => $query->lock(DB::getDriverName() === 'pgsql' ? 'FOR UPDATE NOWAIT' : true)->first());
        } catch (QueryException $error) {
            // The named crossing lock's savepoint has rolled back before classification.
            if (($error->errorInfo[0] ?? null) === '55P03'
                || (DB::getDriverName() === 'sqlite' && in_array($error->errorInfo[1] ?? null, [5, 6], true))) {
                throw new AccountingMappingBusy;
            }
            throw $error;
        }
    }
}
