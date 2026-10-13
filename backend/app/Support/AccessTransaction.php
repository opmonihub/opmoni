<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class AccessTransaction
{
    public static function boundLockWaits(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET LOCAL lock_timeout = '2s'");
            DB::statement("SET LOCAL statement_timeout = '5s'");
        }
    }
}
