<?php

namespace App\Console\Commands;

use App\Actions\EnsureAccountSubscription;
use App\Models\Account;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('platform:initialize')]
#[Description('Initialize the platform catalog and missing account subscriptions')]
class InitializePlatform extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        app(PlanCatalogSeeder::class)->run();
        $provisionSubscription = app(EnsureAccountSubscription::class);
        $created = 0;

        Account::whereDoesntHave('subscription')->chunkById(200, function (Collection $accounts) use ($provisionSubscription, &$created): void {
            foreach ($accounts as $account) {
                if ($provisionSubscription->handle($account)->wasRecentlyCreated) {
                    $created++;
                }
            }
        });

        $this->info("Subscriptions created: {$created}");

        return self::SUCCESS;
    }
}
