<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Support\Facades\DB;

class EnsureAccountSubscription
{
    public function handle(Account $account): Subscription
    {
        return DB::transaction(function () use ($account): Subscription {
            $lockedAccount = Account::lockForUpdate()->findOrFail($account->getKey());
            $subscription = $lockedAccount->subscription()->lockForUpdate()->first();

            if ($subscription !== null) {
                return $subscription;
            }

            $plan = Plan::where('slug', 'basico')->first();

            if ($plan === null) {
                app(PlanCatalogSeeder::class)->run();
                $plan = Plan::bySlug('basico');
            }

            return $lockedAccount->subscription()->firstOrCreate([], [
                'plan_id' => $plan->getKey(),
                'status' => 'active',
            ]);
        });
    }
}
