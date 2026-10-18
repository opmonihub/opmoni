<?php

namespace App\Providers;

use App\Erp\Fiscal\Contracts\ErpFiscalProvider;
use App\Erp\Fiscal\UnavailableErpFiscalProvider;
use App\Models\Account;
use App\Observers\AccountObserver;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
use App\Support\AbsoluteSessionHandler;
use App\Support\ErpCompanyContext;
use App\Tenant\CurrentTenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentTenant::class);
        $this->app->scoped(ErpCompanyContext::class);
        $this->app->bind(FiscalConnectorRegistry::class);
        $this->app->bind(ErpFiscalProvider::class, UnavailableErpFiscalProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Account::observe(AccountObserver::class);
        foreach (glob(app_path('Policies/*Policy.php')) as $policyFile) {
            $name = basename($policyFile, 'Policy.php');
            $model = 'App\\Models\\'.$name;
            $policy = 'App\\Policies\\'.$name.'Policy';
            if (class_exists($model)) {
                Gate::policy($model, $policy);
            }
        }

        Session::extend('redis', function ($app) {
            $handler = new AbsoluteSessionHandler(
                Cache::store(config('session.store', 'sessions') ?: 'sessions'),
                config('session.lifetime', 720),
            );

            return $handler;
        });
    }
}
