<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Client;
use App\Models\Department;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\SerproMonitoring;
use App\Models\Task;
use App\Observers\AccountObserver;
use App\Policies\AccountPolicy;
use App\Policies\ClientPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\ProcessPolicy;
use App\Policies\ProcessTemplatePolicy;
use App\Policies\SerproMonitoringPolicy;
use App\Policies\TaskPolicy;
use App\Tenant\CurrentTenant;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // `php artisan serve` zera o env do filho `php -S` quando há .env
        // (ServeCommand::$passthroughVariables). O filho então cai no .env do
        // bind (host: 127.0.0.1), e dentro do container isso recusa conexão.
        // Repassar DB_*/REDIS_* garante que o filho use postgres/redis do compose.
        ServeCommand::$passthroughVariables = array_values(array_unique(array_merge(
            ServeCommand::$passthroughVariables,
            [
                'DB_CONNECTION',
                'DB_HOST',
                'DB_PORT',
                'DB_DATABASE',
                'DB_USERNAME',
                'DB_PASSWORD',
                'DB_URL',
                'REDIS_CLIENT',
                'REDIS_HOST',
                'REDIS_PORT',
                'REDIS_PASSWORD',
                'CACHE_STORE',
                'QUEUE_CONNECTION',
                'SESSION_DRIVER',
            ]
        )));

        Account::observe(AccountObserver::class);

        Gate::policy(Account::class, AccountPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(SerproMonitoring::class, SerproMonitoringPolicy::class);
        Gate::policy(Process::class, ProcessPolicy::class);
        Gate::policy(ProcessTemplate::class, ProcessTemplatePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
    }
}
