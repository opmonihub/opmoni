<?php

namespace App\Observers;

use App\Models\Account;
use App\Models\Plan;

class AccountObserver
{
    /**
     * Handle the Account "created" event.
     */
    public function created(Account $account): void
    {
        $plan = Plan::firstOrCreate(
            ['slug' => 'basico'],
            ['name' => 'Básico', 'limits' => ['users' => 5, 'clients' => 50]]
        );

        $account->subscription()->create(['plan_id' => $plan->getKey()]);
    }
}
