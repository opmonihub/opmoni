<?php

use App\Models\Account;
use App\Observers\AccountObserver;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Account::query()->orderBy('id')->chunkById(100, function ($accounts): void {
            foreach ($accounts as $account) {
                app(AccountObserver::class)->initialize($account);
            }
        });
    }

    public function down(): void
    {
        // Defaults are application data and remain available on rollback.
    }
};
