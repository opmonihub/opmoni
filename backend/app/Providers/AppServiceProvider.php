<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\AccountCertificate;
use App\Models\Client;
use App\Models\Department;
use App\Models\FiscalDocument;
use App\Models\Process;
use App\Models\ProcessTemplate;
use App\Models\SerproAuthorizationTerm;
use App\Models\SerproSyncRun;
use App\Models\Task;
use App\Observers\AccountObserver;
use App\Policies\AccountCertificatePolicy;
use App\Policies\AccountPolicy;
use App\Policies\ClientPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\FiscalDocumentPolicy;
use App\Policies\ProcessPolicy;
use App\Policies\ProcessTemplatePolicy;
use App\Policies\SerproAuthorizationTermPolicy;
use App\Policies\SerproSyncRunPolicy;
use App\Policies\TaskPolicy;
use App\Services\Fiscal\Capture\FiscalConnectorRegistry;
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

        // O catálogo de conector é o único lugar que sabe qual conector serve
        // cada fonte. `bind` e não `singleton`: o registro é sem estado e quem
        // chama é a fila.
        $this->app->bind(FiscalConnectorRegistry::class);

        // ⚠️ `FiscalConnector` **não** é ligado a nada, de propósito.
        //
        // Com dois serviços de distribuição, uma ligação da interface para "o
        // conector" é a armadilha: qualquer código que tipasse a interface e
        // chamasse `source()` receberia NF-e, e a fonte de CT-e passaria a ser
        // consultada pelo `.asmx` da NF-e sem que nada gritasse. Todo caminho
        // que fala com o fisco resolve pelo `FiscalConnectorRegistry`, que não
        // devolve conector para fonte sem conector — e um código novo que pedir
        // a interface recebe "Target [App\Services\Fiscal\Contracts\FiscalConnector]
        // is not instantiable" no lugar do silêncio.
        //
        // O teste `test_o_contrato_do_conector_nao_resolve_para_nenhum_conector`
        // é o que trava esta linha.
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
        Gate::policy(AccountCertificate::class, AccountCertificatePolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Department::class, DepartmentPolicy::class);
        Gate::policy(FiscalDocument::class, FiscalDocumentPolicy::class);
        Gate::policy(SerproAuthorizationTerm::class, SerproAuthorizationTermPolicy::class);
        Gate::policy(SerproSyncRun::class, SerproSyncRunPolicy::class);
        Gate::policy(Process::class, ProcessPolicy::class);
        Gate::policy(ProcessTemplate::class, ProcessTemplatePolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
    }
}
