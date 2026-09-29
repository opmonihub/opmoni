<?php

use App\Http\Controllers\Admin\AccountController as AdminAccountController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\SerproConnectionController as AdminSerproConnectionController;
use App\Http\Controllers\Admin\SerproConnectivityController as AdminSerproConnectivityController;
use App\Http\Controllers\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Admin\SupportLogController as AdminSupportLogController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\SupportAccessController;
use App\Http\Controllers\Tenant\AccountCertificateController;
use App\Http\Controllers\Tenant\AccountMemberController;
use App\Http\Controllers\Tenant\AccountSwitchController;
use App\Http\Controllers\Tenant\ClientBulkDeletionController;
use App\Http\Controllers\Tenant\ClientCertificateController;
use App\Http\Controllers\Tenant\ClientCnpjLookupController;
use App\Http\Controllers\Tenant\ClientCnpjRefreshController;
use App\Http\Controllers\Tenant\ClientController;
use App\Http\Controllers\Tenant\ClientEcacPowerOfAttorneyController;
use App\Http\Controllers\Tenant\ClientSavedFilterController;
use App\Http\Controllers\Tenant\ClientSelectionController;
use App\Http\Controllers\Tenant\ClientTagAssignmentController;
use App\Http\Controllers\Tenant\DepartmentController;
use App\Http\Controllers\Tenant\ProcessController;
use App\Http\Controllers\Tenant\ProcessTemplateController;
use App\Http\Controllers\Tenant\SerproAccountEnablementController;
use App\Http\Controllers\Tenant\SerproAuthorizationTermController;
use App\Http\Controllers\Tenant\SerproSyncRunController;
use App\Http\Controllers\Tenant\TagController;
use App\Http\Controllers\Tenant\TaskController;
use App\Models\SerproConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/registration-status', [AuthController::class, 'registrationStatus'])->middleware('throttle:30,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
Route::get('/me', [AuthController::class, 'me'])->middleware('auth:sanctum');

Route::post('/account/switch', AccountSwitchController::class)->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
    Route::get('clients/summary', [ClientController::class, 'summary']);
    Route::get('clients/analytics', [ClientController::class, 'analytics']);
    Route::post('clients/tags', [ClientTagAssignmentController::class, 'store']);
    Route::apiResource('tags', TagController::class)->except(['show']);
    Route::apiResource('departments', DepartmentController::class)->except(['show']);
    Route::get('clients/saved-filters', [ClientSavedFilterController::class, 'index']);
    Route::post('clients/saved-filters', [ClientSavedFilterController::class, 'store']);
    Route::delete('clients/saved-filters/{savedFilter}', [ClientSavedFilterController::class, 'destroy']);
    Route::post('clients/selections', [ClientSelectionController::class, 'store']);
    Route::post('clients/selections/{selection}/presence', [ClientSelectionController::class, 'presence']);
    Route::post('clients/bulk-deletions', [ClientBulkDeletionController::class, 'store']);
    Route::get('clients/bulk-deletions/{bulkDeletion}', [ClientBulkDeletionController::class, 'show']);
    Route::post('clients/cnpj-lookup', ClientCnpjLookupController::class)
        ->middleware('throttle:10,1');
    Route::post('clients/{client}/cnpj-refresh-preview', [ClientCnpjRefreshController::class, 'preview']);
    Route::post('clients/{client}/cnpj-refresh', [ClientCnpjRefreshController::class, 'update']);
    Route::post('clients/{client}/certificate', [ClientCertificateController::class, 'store']);
    Route::delete('clients/{client}/certificate', [ClientCertificateController::class, 'destroy']);
    Route::put('clients/{client}/ecac-power-of-attorney', [ClientEcacPowerOfAttorneyController::class, 'update']);
    Route::delete('clients/{client}/ecac-power-of-attorney', [ClientEcacPowerOfAttorneyController::class, 'destroy']);
    Route::apiResource('clients', ClientController::class);
    /*
     * O e-CNPJ do escritório é **da conta**, e por isso estas rotas ficam no
     * grupo `tenant` — ao contrário de `serpro/connection` mais abaixo, que é a
     * credencial da plataforma e por isso responde pela policy de outra conta.
     *
     * As três rotas não endereçam linha nenhuma: o certificado do escritório é
     * um por conta, e o upload substitui o que estava valendo e a remoção apaga
     * o que estava valendo. O `404` da leitura é o de "esta conta não tem
     * certificado", e por construção é também o de "o certificado é de outra
     * conta" — a busca é por `account_id` explícito, nunca pelo escopo global
     * do tenant.
     *
     * **O upload é limitado, e pelo mesmo motivo do diagnóstico mais
     * abaixo.** Cada envio aceito agenda a emissão do termo, e a emissão é um
     * `submitTerm` de verdade contra o provedor, com `tries = 1` e sem
     * unicidade: um `admin` ou `operador` que chame a rota em laço gasta a cota
     * **do escritório dele**, que é a cota de um parceiro do SERPRO. Seis por
     * minuto é folgado para o uso real — o e-CNPJ se entrega uma vez, e a
     * reentrega é rara — e curto o bastante para que a cota gasta por uma
     * sessão de teste seja irrelevante.
     */
    Route::get('serpro/account-certificate', [AccountCertificateController::class, 'show']);
    Route::post('serpro/account-certificate', [AccountCertificateController::class, 'store'])
        ->middleware('throttle:6,1');
    Route::delete('serpro/account-certificate', [AccountCertificateController::class, 'destroy']);

    /*
     * O termo de autorização do escritório, e **só** a leitura dele.
     *
     * Não há rota de escrita, e a ausência é a decisão: o termo é assinado
     * pelo e-CNPJ que a rota de cima entrega e emitido pela plataforma, e
     * nenhum Membro tem o que pedir ao provedor em nome do escritório. A
     * policy nega a escrita para todo mundo pelo mesmo motivo, e o que
     * segura as duas coisas é a ausência do verbo nela **e** a ausência da
     * rota aqui: um `Route::post` que chamasse o `SerproTermManager`
     * diretamente passaria pelo `Gate` sem nunca chegar à policy, e é por
     * isso que o teste que afirma a ausência de rota existe.
     *
     * O `200` com `state` = `ausente` para quem não tem termo é o que a
     * spec chama de "ação pertencente ao escritório" — a tela precisa da
     * ausência para pedir o certificado, e um `404` seria indistinguível de
     * rota errada.
     */
    Route::get('serpro/authorization-terms', SerproAuthorizationTermController::class);

    /*
     * A habilitação da integração é do escritório, e fica aqui — no grupo
     * `tenant` — porque é a conta corrente que liga e desliga. Escrever é de
     * `admin` (o `update` da `AccountPolicy` que o Form Request consulta):
     * desligar um escritório é contenção, e a decisão não é de quem só
     * opera a rotina. Ler é de qualquer Membro, porque a tela de todos
     * precisa saber se a integração está ligada.
     */
    Route::get('serpro/enablement', [SerproAccountEnablementController::class, 'show']);
    Route::put('serpro/enablement', [SerproAccountEnablementController::class, 'update']);

    /*
     * As execuções de sincronização. O POST é o disparo manual — a rotina
     * agendada nasce fora daqui — e a guarda "uma execução ativa por conta"
     * mora no `SerproRunStarter`, sob lock da linha da conta. `resync` é
     * POST de propósito: cria uma execução nova ligada à anterior, e não
     * reabre a terminada — reabrir apagaria o histórico que a tela mostra.
     * Escrever é de `admin`/`operador` (cada execução gasta a cota do
     * provedor); ler é de qualquer Membro, e execução alheia é `404` pelo
     * binding restrito da trait.
     */
    Route::get('serpro/sync-runs', [SerproSyncRunController::class, 'index']);
    Route::post('serpro/sync-runs', [SerproSyncRunController::class, 'store']);
    Route::get('serpro/sync-runs/{run}', [SerproSyncRunController::class, 'show']);
    Route::get('serpro/sync-runs/{run}/calls', [SerproSyncRunController::class, 'calls']);
    Route::post('serpro/sync-runs/{run}/resync', [SerproSyncRunController::class, 'resync']);
    Route::apiResource('processes', ProcessController::class);
    Route::get('account/members/directory', [AccountMemberController::class, 'directory']);
    Route::apiResource('process-templates', ProcessTemplateController::class);
    Route::get('process-templates/{process_template}/preview', [ProcessTemplateController::class, 'preview']);
    Route::post('process-templates/{process_template}/generate', [ProcessTemplateController::class, 'generate']);
    Route::apiResource('tasks', TaskController::class)->only(['index', 'show', 'update']);
    Route::get('work/calendar', [TaskController::class, 'calendar']);
    Route::get('work/grouped', [TaskController::class, 'grouped']);
    Route::get('work/tasks/unscoped', [TaskController::class, 'unscopedForMonth']);
    Route::apiResource('account/members', AccountMemberController::class)->parameter('members', 'member');
});

Route::middleware(['auth:sanctum', 'super_admin'])->prefix('admin')->group(function (): void {
    Route::apiResource('accounts', AdminAccountController::class)->except(['destroy']);
    Route::apiResource('plans', AdminPlanController::class)->except(['destroy']);
    Route::apiResource('subscriptions', AdminSubscriptionController::class)->only(['index', 'show', 'update']);
    Route::get('users', [AdminUserController::class, 'index']);
    Route::get('support/logs', [AdminSupportLogController::class, 'index']);
});

Route::middleware(['auth:sanctum', 'super_admin'])
    ->post('support/accounts/{account}/enter', [SupportAccessController::class, 'enter']);
Route::middleware(['auth:sanctum', 'super_admin'])
    ->post('support/exit', [SupportAccessController::class, 'exit']);

/*
 * A credencial do Integra Contador é da plataforma: uma linha só, fora de
 * qualquer conta. Por isso estas rotas ficam fora do grupo `tenant` — a conta
 * corrente não é a dona da credencial, e dizer o contrário faria a policy
 * responder pelo vínculo errado.
 */
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('serpro/connection', [AdminSerproConnectionController::class, 'show'])
        ->middleware('can:viewAny,'.SerproConnection::class);

    Route::put('serpro/connection', [AdminSerproConnectionController::class, 'update'])
        ->middleware('super_admin');

    /*
     * O teste de conectividade exercita a autenticação da credencial da
     * plataforma, sem consultar nenhum contribuinte: quem opera a integração é
     * o super_admin, e um Membro da conta não tem o que fazer aqui.
     *
     * O limite vai junto porque cada chamada **custa uma emissão de token de
     * verdade** — mTLS com o A1 do contratante e `client_credentials` —, e a
     * credencial é uma só para a plataforma inteira. O token que fica em cache
     * não protege esta rota: a pergunta é "está funcionando agora?", e por
     * definição ela não aceita a resposta de meia hora atrás. Sem limite, um
     * duplo clique ou um laço de retry no botão gasta a cota do SERPRO, e o
     * `429` que volta apareceria como `provedor` para **toda** conta ao mesmo
     * tempo — um botão de diagnóstico de uma conta derrubando a integração das
     * outras. Seis por minuto é folgado para o uso real (um diagnóstico são
     * algumas cliques com tempo de leitura entre elas) e curto o bastante para
     * que a cota gasto por uma sessão de teste seja irrelevante.
     */
    Route::post('serpro/connectivity', AdminSerproConnectivityController::class)
        ->middleware(['super_admin', 'throttle:6,1']);
});
