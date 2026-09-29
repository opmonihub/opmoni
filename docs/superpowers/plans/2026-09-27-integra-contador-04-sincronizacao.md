# Integra Contador — Sincronização Durável Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Criar execuções assíncronas por Account, com um item por PJ, chamadas rastreáveis e atualização idempotente de registros sincronizados.

**Architecture:** Endpoint cria execução `queued` sob lock do Account, despacha fan-out após commit e devolve 202. Um job filho por cliente carrega `accountId`, verifica habilitação/termo/procuração de novo, serializa chamadas sob lock por documento, registra cada tentativa e grava projeções por `(account_id,client_id,obligation)`; finalização e watchdog convertem execução abandonada em falha visível.

**Tech Stack:** Laravel ^13.17, PHP ^8.3, PHPUnit ^12.5, filas Redis (`retry_after=90`), cache lock, Nuxt ^4.5.

**Spec:** `openspec/changes/complete-serpro-integration/specs/serpro-sync/spec.md`, `design.md` D5–D6; tarefas 6.1–6.12. **Decisão aprovada nesta sessão:** expor `indeterminate` e `not_processed` separadamente; `total = synchronized + skipped + failed + indeterminate + not_processed`, inclusive durante execução. Atualizar spec, tipos TS e telas junto com a API; nunca contar indeterminado como falha/ignorado.

## Global Constraints

- Sem segredo, payload bruto, documento XML ou PFX em logs, rows públicas ou exceptions persistidas. Apenas código, `responseId`, `X-Request-Tag`, `mensagens` sanitizadas, duração e indicação de cobrança.
- Uma execução `queued|running` por Account; PF fora de `total`; `(run_id,client_id)` único; reentrega nunca envia segunda requisição quando a primeira pode ter alcançado o gateway.
- `504`/timeout após início da chamada ⇒ `indeterminado`, sem hot retry; 429/503 somente com backoff **se ainda não houver chamada com resultado indeterminado**; erros de dados/permissão/termo não repetem. Serviço só de leitura; não chamar `MSGDETALHAMENTO62` (ciência da intimação).
- `$timeout=75` segundos < `retry_after=90`. Fan-out `tries=3`; job por cliente processa **no máximo um serviço por entrega** e reentra a partir do registro de chamadas, com `tries=20` (até nove serviços + tentativas anteriores ao envio), backoff `[15,60]`; watchdog para estados não terminais. Não corrigir `CaptureFiscalDocumentsJob` fora deste change.
- `CurrentTenant` singleton não é resetado pelo worker: jobs restauram valor anterior em `finally`, mas todas as queries/escritas levam `account_id` explícito. Idêntico em SQLite `:memory:` sem Redis/network (`Cache::fake` não existe; use cache array em testes).
- Mensagens/testes em português; gerar classes via Artisan, formato `vendor/bin/pint --dirty --format agent`, correr backend `php artisan test --compact` e frontend `pnpm lint && pnpm typecheck && pnpm test`.

## Mapa de arquivos e interfaces

| Arquivo | Responsabilidade |
| --- | --- |
| `backend/database/migrations/*_create_serpro_sync_runs_table.php`, `*_create_serpro_sync_run_items_table.php` | Histórico, estados, contagens 6-way, timestamps, item único; models/factories/resources correspondentes. |
| `backend/database/migrations/*_evolve_serpro_monitorings_table.php`, `backend/app/Models/SerproMonitoring.php` | Substituir `name` placeholder por vínculo `(account_id,client_id,obligation)` e campos de projeção/`source_at`. |
| `backend/database/migrations/*_create_serpro_calls_table.php`, `backend/app/Models/SerproCall.php` | Auditoria da chamada, inclusive `client_id nullable`; sem payload bruto. |
| `backend/app/Services/SerproRunStarter.php`, `backend/app/Http/Controllers/Tenant/SerproSyncRunController.php`, `backend/app/Http/Resources/{SerproSyncRunResource,SerproSyncRunItemResource,SerproCallResource}.php` | POST, GET list/detail/calls, POST resync, validação de papel e Account. |
| `backend/app/Jobs/{FanOutSerproRunJob,SyncSerproClientJob}.php`, `backend/app/Services/{SerproRunFinalizer,SerproCallRecorder,SerproMonitoringWriter}.php` | Fan-out, serialização, operações por cliente, transições, escrita idempotente. |
| `backend/app/Console/Commands/FailAbandonedSerproRuns.php`, `backend/routes/console.php` | Watchdog de runs presos; sem backfill ou nova chamada ao provedor. |
| `backend/app/Services/PlanLimits.php`, controller/policy/resource CRUD antigo, seeders e três testes legados | Remover comportamento `monitorings` placeholder num único commit coeso. |
| `frontend/app/types/serpro.ts`, `frontend/app/pages/monitoring/execucoes*.vue` | Expor os dois contadores novos; interface não deve mentir sobre 504. |

**Interfaces produzidas:** `SerproRunStarter::start(int $accountId, int $userId, ?int $previousRunId = null): SerproSyncRun`; `SerproMonitoringWriter::store(int $accountId, int $clientId, string $obligation, array $fields, ?string $sourceAt): SerproMonitoring`; `SerproRunFinalizer::recount(int $runId, int $accountId): void`. Plano 05 consome `SerproMonitoring` e os estados da execução, sem chamar `SerproClient` na leitura.

---

### Task 1: Persistir runs, itens e chamadas, e reconciliar os seis contadores

**Files:** Create três migrations, `backend/app/Models/{SerproSyncRun,SerproSyncRunItem,SerproCall}.php`, factories, resources, `backend/app/Services/SerproRunFinalizer.php`; Modify `frontend/app/types/serpro.ts`, `openspec/changes/complete-serpro-integration/specs/serpro-sync/spec.md`; Test `backend/tests/Feature/SerproSyncSchemaTest.php`, `backend/tests/Unit/SerproRunFinalizerTest.php`, `frontend/tests/monitoringStatus.test.ts`.

**Interfaces:** Run `account_id,state,total,synchronized,skipped,failed,indeterminate,not_processed,started_at,finished_at,reason`; item `run_id,account_id,client_id,state,current_obligation nullable,reason,provider_code,response_id,request_tag,attempted_at,updated_at`; call `account_id,run_id,client_id nullable,id_sistema,id_servico,version,path,billable,status,provider_code,response_id,request_tag,messages,duration_ms`; `SerproRunFinalizer::recount(int,int): void`.

- [ ] **Step 1: Teste vermelho.** Criar três itens PJ (`sincronizado`, `indeterminado`, `nao_processado`) ⇒ `total=3,synchronized=1,indeterminate=1,not_processed=1,skipped=failed=0`; segunda inserção `(run_id,client_id)` viola unique; call sem client persiste, mas com outro Account é rejeitada por FK/guarda. Teste TS verifica soma dos seis.

```php
$states = ['sincronizado', 'indeterminado', 'nao_processado'];
foreach ($states as $state) {
    SerproSyncRunItem::factory()->create(['run_id' => $run->id, 'account_id' => $account->id, 'state' => $state]);
}
$finalizer->recount($run->id, $account->id);
$actual = $run->fresh();
$this->assertSame([3, 1, 0, 0, 1, 1], array_map(
    fn (string $key): int => $actual->{$key},
    ['total', 'synchronized', 'skipped', 'failed', 'indeterminate', 'not_processed'],
));
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproSyncSchemaTest.php tests/Unit/SerproRunFinalizerTest.php`; `cd frontend && node --test tests/monitoringStatus.test.ts`; esperado: tabela/campos ausentes.
- [ ] **Step 3: Implementar.** Migrations com FKs/índices `(account_id,state)`, `unique(run_id,client_id)` e `(run_id,id)` em calls; `current_obligation` no item marca o serviço em andamento (não é outro estado do item). No model item declarar `run(): BelongsTo` para `SerproSyncRun`. `BelongsToAccount` nos três models, explicit `account_id` em toda inserção; resource omite corpo de chamadas/segredos e expõe `obligation = current_obligation`. `recount` faz groupBy item.state filtrado por `account_id`, atualiza os seis números, só termina se não houver `nao_processado`, salvo falha de watchdog. `state` é enum dos planos 01/02; adicionar campos TS obrigatórios e ajustar tela de execuções para apresentar os dois novos.

```php
$counts = SerproSyncRunItem::query()->where('account_id', $accountId)
    ->where('run_id', $runId)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
$run->forceFill([
    'synchronized' => (int) ($counts['sincronizado'] ?? 0),
    'skipped' => (int) ($counts['ignorado'] ?? 0),
    'failed' => (int) ($counts['falhou'] ?? 0),
    'indeterminate' => (int) ($counts['indeterminado'] ?? 0),
    'not_processed' => (int) ($counts['nao_processado'] ?? 0),
    'total' => array_sum($counts->all()),
])->save();
```
- [ ] **Step 4: Provar/commitar.** `cd backend && php artisan test --compact tests/Feature/SerproSyncSchemaTest.php tests/Unit/SerproRunFinalizerTest.php`; `cd frontend && pnpm lint && pnpm typecheck && pnpm test`; `git add openspec/changes/complete-serpro-integration frontend backend && git commit -m "feat(serpro): registrar execuções com seis estados contáveis"`.

### Task 2: Substituir o placeholder em uma migração e um commit coerentes

**Files:** Create `backend/database/migrations/*_evolve_serpro_monitorings_table.php`; Modify `backend/app/Models/SerproMonitoring.php`, `backend/app/Models/Account.php`, `backend/app/Services/PlanLimits.php`, `backend/routes/api.php`, `backend/database/seeders/PlanSeeder.php`; Delete `backend/app/Http/Controllers/Tenant/SerproMonitoringController.php`, `backend/app/Http/Resources/SerproMonitoringResource.php`, `backend/app/Policies/SerproMonitoringPolicy.php`; Modify `backend/tests/Feature/Tenancy/{ConsistencyRefactorTest,SubscriptionsTest,SecurityRefactorTest}.php`; Test `backend/tests/Feature/SerproMonitoringMigrationTest.php`.

**Interfaces:** `SerproMonitoring` persiste `account_id,client_id,obligation,state,cause,due_on,fields,periods,messages,source_at,created_at,updated_at`; unique `(account_id,client_id,obligation)`; **sem** `/api/monitorings`.

- [ ] **Step 1: Teste vermelho.** Criar placeholder `['name'=>'antigo']`, rodar a migration específica sobre sqlite de teste ⇒ linha antiga eliminada; criar link por cliente PJ e obrigação, `assertDatabaseCount(...,1)` e segunda associação usa upsert; `Route::has('monitorings.index')` falso. Trocar três testes legados: não esperar mais envelope de `/api/monitorings`, nem limite de plano `'monitorings'`.

```php
$this->assertFalse(Schema::hasColumn('serpro_monitorings', 'name'));
$this->assertSame(0, SerproMonitoring::query()->where('account_id', $account->id)->count());
$this->assertFalse(Route::has('monitorings.index'));
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproMonitoringMigrationTest.php tests/Feature/Tenancy/ConsistencyRefactorTest.php tests/Feature/Tenancy/SubscriptionsTest.php tests/Feature/Tenancy/SecurityRefactorTest.php`; esperado: novos testes vermelhos, legados verdes até remoção.
- [ ] **Step 3: Implementar migração reversível.** `DELETE` apenas de `serpro_monitorings` placeholder, remover `name` **em migration nova** e adicionar FK `client_id`, obrigação/estado/campos nullable, unique composto; `down` recria `name` nullable e reverte colunas/índice (não recupera dados apagados; registrar perda planejada). Excluir CRUD/policy/resource, `PlanLimits` match só `users|clients`, limpar seeders/testes e relações Account/Client conforme novo model. Não executar `migrate:fresh` nem rollback em banco com dados reais.

```php
DB::table('serpro_monitorings')->delete(); // somente a tabela placeholder, em migration versionada
Schema::table('serpro_monitorings', function (Blueprint $table): void {
    $table->dropColumn('name');
    $table->foreignId('client_id')->constrained()->cascadeOnDelete();
    $table->string('obligation', 80);
    $table->string('state', 30)->default('sem_dados');
    $table->timestamp('source_at')->nullable();
    $table->unique(['account_id', 'client_id', 'obligation']);
});
```
- [ ] **Step 4: Provar/commitar.** `cd backend && php artisan test --compact tests/Feature/Tenancy/ConsistencyRefactorTest.php tests/Feature/Tenancy/SubscriptionsTest.php tests/Feature/Tenancy/SecurityRefactorTest.php tests/Feature/SerproMonitoringMigrationTest.php`; `php artisan route:list --path=api/monitorings` mostra zero; `git add backend && git commit -m "refactor(serpro): trocar CRUD placeholder por vínculo cliente obrigação"`.

### Task 3: Disparo transacional e leitura tenant-scoped das execuções

**Files:** Create `backend/app/Services/SerproRunStarter.php`, `backend/app/Http/Controllers/Tenant/SerproSyncRunController.php`, `backend/app/Policies/SerproSyncRunPolicy.php`; Modify `backend/routes/api.php`; Test `backend/tests/Feature/SerproSyncRunApiTest.php`.

**Interfaces:** POST `/api/serpro/sync-runs` ou `/api/serpro/sync-runs/{run}/resync` ⇒ HTTP 202 `{data: SerproSyncRun}`; GET lista/detalhe paginado do Account; GET `/api/serpro/sync-runs/{run}/calls` ⇒ lista paginada de `client_id,id_sistema,id_servico,version,path,billable,status,provider_code,response_id,request_tag,messages,duration_ms` sem payload bruto. Conflito retorna 409 e `run_id`; conexão/flag ausente retorna 422 sem run; `user` 403 para escrita, leitura permitida.

- [ ] **Step 1: Teste vermelho.** Com `Queue::fake()`: admin/operador criam `Queued`, `user` 403, sem credencial/Account desligado 422, segundo disparo 409 com ID, outro Account pode disparar, GET de run/calls alheios 404; GET calls do Account inclui cliente/tag/cobrança mas não `dados`; `resync` de run terminado cria **novo** run, não reusa ID.

```php
Queue::fake();
$this->actingAs($operator, 'sanctum')->postJson('/api/serpro/sync-runs')
    ->assertStatus(202)->assertJsonPath('data.state', 'queued');
Queue::assertPushed(FanOutSerproRunJob::class, fn ($job) => $job->accountId === $account->id);
$this->actingAs($operator, 'sanctum')->postJson('/api/serpro/sync-runs')->assertStatus(409);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproSyncRunApiTest.php`; esperado: rota ausente.
- [ ] **Step 3: Implementar.** `DB::transaction` trava `Account::whereKey($accountId)->lockForUpdate()`, valida `SerproAccountEnablement::enabled`, `SerproConnection::current()->assertIdentity()`, nenhuma run `Queued|Running`; cria run `Queued` com `account_id`, após commit `FanOutSerproRunJob::dispatch($run->id,$accountId)->afterCommit()`. Policy `admin|operador` escrita e Membro leitura; resources expõem seis contadores/itens, `SerproCallResource` expõe metadados seguros das calls no endpoint separado (sem inventar tela de custo). Registrar rotas somente no grupo `auth:sanctum,tenant`.

```php
return DB::transaction(function () use ($accountId, $userId): SerproSyncRun {
    Account::query()->whereKey($accountId)->lockForUpdate()->firstOrFail();
    $active = SerproSyncRun::query()->where('account_id', $accountId)
        ->whereIn('state', ['queued', 'running'])->first();
    if ($active !== null) {
        abort(409, "Execução {$active->id} já está em andamento.");
    }
    $run = SerproSyncRun::create(['account_id' => $accountId, 'requested_by' => $userId, 'state' => 'queued']);
    FanOutSerproRunJob::dispatch($run->id, $accountId)->afterCommit();
    return $run;
});
```
- [ ] **Step 4: Provar/commitar.** `cd backend && php artisan test --compact tests/Feature/SerproSyncRunApiTest.php`; `git add backend/app backend/routes/api.php backend/tests && git commit -m "feat(serpro): disparar execução única por Account"`.

### Task 4: Fan-out PJ, item inicial e claim idempotente do cliente

**Files:** Create `backend/app/Jobs/{FanOutSerproRunJob,SyncSerproClientJob}.php`, `backend/app/Services/SerproClientLock.php`; Test `backend/tests/Feature/SerproSyncJobsTest.php`.

**Interfaces:** Fan-out carrega `runId:int,accountId:int` (`tries=3`); filho carrega `runId:int,accountId:int,clientId:int` (`timeout=75`, `tries=20`, `backoff=[15,60]`); `SerproClientLock::with(int $accountId, string $taxId, Closure $callback): mixed` usa chave por `accountId/taxId`, lease > timeout.

- [ ] **Step 1: Teste vermelho.** `Queue::fake()` após fan-out: só PJ da Account (inclusive PF de outra conta ignorada), um item `NaoProcessado` por PJ e um child job por item; duas entregas do mesmo filho não duplicam item nem produzem duas chamadas; lock tomado anteriormente faz `release(15)` sem marcar falha/sucesso; `CurrentTenant` do job anterior não vaza após `finally`.

```php
Queue::fake();
(new FanOutSerproRunJob($run->id, $account->id))->handle();
$this->assertDatabaseCount('serpro_sync_run_items', 1);
Queue::assertPushed(SyncSerproClientJob::class, 1);
$this->assertDatabaseMissing('serpro_sync_run_items', ['client_id' => $individual->id]);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproSyncJobsTest.php`; esperado: classes ausentes.
- [ ] **Step 3: Implementar.** Fan-out pagina `Client::where('account_id',$accountId)->where('person_type','company')`, `firstOrCreate(['run_id'=>$runId,'client_id'=>$clientId], ['account_id'=>$accountId,'state'=>'nao_processado'])` e despacha `SyncSerproClientJob` depois do commit. Filho entra no lock antes da chamada, relê Account/Client/termo/elegibilidade, seleciona **um** serviço ainda não registrado para este `(run,cliente)`, cria vínculo `SerproMonitoring` com `source_at=null` se ele ainda não existe, e marca `current_obligation` + `attempted_at` antes de enviar; após resposta registra log e limpa ambos para a próxima entrega `release(1)`. Esse checkpoint impede ultrapassar 75s ao processar nove serviços. Se já existir tentativa sem resposta após crash, classifica `Indeterminado` em vez de reemitir. Só marca `Sincronizado` ao concluir as fontes elegíveis; restaura `CurrentTenant` em `finally`; não usa scope como única barreira.

```php
$tenant = resolve(CurrentTenant::class);
$previous = $tenant->accountId;
try {
    $tenant->accountId = $this->accountId;
    $client = Client::query()->where('account_id', $this->accountId)->findOrFail($this->clientId);
    $item = SerproSyncRunItem::query()->where('account_id', $this->accountId)
        ->where('run_id', $this->runId)->where('client_id', $client->id)->firstOrFail();
    if ($item->current_obligation !== null && $item->attempted_at !== null) {
        $item->forceFill(['state' => SerproSyncItemState::Indeterminate])->save();
        return;
    }
} finally {
    $tenant->accountId = $previous;
}
```
- [ ] **Step 4: Provar/commitar.** Rodar teste específico; `git add backend/app/Jobs backend/app/Services/SerproClientLock.php backend/tests && git commit -m "feat(serpro): distribuir clientes PJ com lock e claim idempotente"`.

### Task 5: Registrar cada chamada e persistir projeção sem duplicar

**Files:** Create `backend/app/Services/{SerproCallRecorder,SerproMonitoringWriter}.php`; Modify `backend/app/Jobs/SyncSerproClientJob.php`, `backend/config/integra-contador.php`; Test `backend/tests/Feature/SerproSyncProjectionTest.php`.

**Interfaces:** `SerproCallRecorder::record(int $runId,int $accountId,?int $clientId,string $idSistema,string $idServico,callable $call): SerproResult` grava request tag/response ID/código e duração; `SerproMonitoringWriter::store(int,int,string,array,?string): SerproMonitoring` atualiza por unique composto e renova `source_at` mesmo quando dado idêntico.

- [ ] **Step 1: Teste vermelho.** `Http::fake` de `OBTERPROCURACAO41`, `CONSULTAROPCAOREGIME103`, `CONSDECLARACAO13`, `RELATORIOSITFIS92` e `MSGCONTRIBUINTE61`: caller registra `idServico/path/versaoSistema/billable/request_tag` com `strlen===32`, responseId, mensagens sanitizadas e duração; chamada gratuita `SOLICITARPROTOCOLO91` `billable=false`; segunda execução atualiza mesmo monitoring, preserva `state` quando iguais e renova `source_at`. `client_id=null` permitido para chamada por Account.

```php
$result = $recorder->record($run->id, $account->id, $client->id,
    'REGIMEAPURACAO', 'CONSULTAROPCAOREGIME103',
    fn () => $serpro->call('REGIMEAPURACAO', 'CONSULTAROPCAOREGIME103', [], $author, $client->tax_id));
$this->assertSame(32, strlen($result->requestTag()));
$this->assertDatabaseHas('serpro_calls', ['account_id' => $account->id, 'request_tag' => $result->requestTag()]);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproSyncProjectionTest.php`; esperado: recorder ausente.
- [ ] **Step 3: Implementar.** Config map com os `idServico` acima e `billable/path/versaoSistema` explícitos; `SerproClient::call` é o único caminho de rede (SITFIS segue protocolo `/Apoiar` + relatório `/Emitir` e usa os estados documentados 200/202/204/304/503, não o trial como prova). `SerproCallRecorder` não armazena `dados` bruto, `message` arbitrária nem segredo; no erro guarda código/status de `SerproException`; writer usa `updateOrCreate` com `account_id` explícito, campos permitidos somente do mapper do serviço e timestamps de origem. `source_at` só é preenchido quando o serviço respondeu com dados: associação sem sincronização continua sem linha no painel. Não sintetizar projeções para serviço não consultado.

```php
return SerproMonitoring::query()->updateOrCreate(
    ['account_id' => $accountId, 'client_id' => $clientId, 'obligation' => $obligation],
    ['fields' => $fields, 'source_at' => $sourceAt ?? now()->toISOString()]
);
// No recorder: duration_ms = (int) round((microtime(true) - $started) * 1000).
```
- [ ] **Step 4: Provar/commitar.** Teste específico + `SerproClientTest`; `git add backend/app backend/config/integra-contador.php backend/tests && git commit -m "feat(serpro): auditar chamadas e atualizar dados por obrigação"`.

### Task 6: Estados terminais, 504, watchdog e verificação fim a fim

**Files:** Modify `backend/app/Jobs/{FanOutSerproRunJob,SyncSerproClientJob}.php`, `backend/app/Services/SerproRunFinalizer.php`, `backend/routes/console.php`; Create `backend/app/Console/Commands/FailAbandonedSerproRuns.php`; Test `backend/tests/Feature/SerproSyncFailureTest.php`.

**Interfaces:** `SerproRunFinalizer::recount` decide `Completed` se todos `Sincronizado|Ignorado`, `Partial` se mistura de resultados/incerteza, `Failed` se zero clientes processados por erro de conexão ou provider; watchdog termina run `Running` há mais de 2×timeout sem progresso, preserva itens `NaoProcessado` e motivo.

- [ ] **Step 1: Teste vermelho.** Fixture 504 com responseId ⇒ `Indeterminado`, uma tentativa apenas, outros clientes processados, run `Partial`, `failed=0`; `-022` ⇒ `Ignorado` com motivo; `-016/-019/-054` ⇒ `Falhou` sem reenvio; 429/503 ⇒ backoff só se nenhuma resposta incerta; `Running` preso é `Failed` após watchdog; `total` sempre soma os seis campos, PF fora.

```php
Http::fake(['*/Consultar' => Http::response(['code' => '058', 'responseId' => 'r-123'], 504)]);
$job->handle($eligibility, $client, $recorder, $writer, $finalizer);
$this->assertDatabaseHas('serpro_sync_run_items', ['run_id' => $run->id, 'client_id' => $customer->id,
    'state' => 'indeterminado', 'response_id' => 'r-123']);
Http::assertSentCount(1);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproSyncFailureTest.php`; esperado: estados/contagens incorretos.
- [ ] **Step 3: Implementar.** `catch (SerproException $e)` faz switch por `SerproFailure`, nunca `release()` com `Indeterminate|DoNotRetry`; `-022` vira ignorado, 504/connection depois de `attempted_at` indeterminado, retries apenas antes da fronteira de envio; `failed(Throwable)` finaliza, sem texto de exception não sanitizada. Agendar `serpro:fail-abandoned` a cada 5 minutos em `routes/console.php`, query por `account_id` do run e `updated_at`/último item, atualizar terminais em transação.

```php
if ($exception->failure === SerproFailure::Indeterminate || ($item->attempted_at !== null && $exception->failure === SerproFailure::Upstream)) {
    $item->forceFill(['state' => SerproSyncItemState::Indeterminate, 'response_id' => $exception->responseId])->save();
} elseif ($exception->providerCode === 'AcessoNegado-ICGERENCIADOR-022') {
    $item->forceFill(['state' => SerproSyncItemState::Skipped, 'reason' => 'sem_procuracao'])->save();
}
// schedule: Schedule::command('serpro:fail-abandoned')->everyFiveMinutes()->withoutOverlapping();
```
- [ ] **Step 4: Verificar/commitar.** `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact`; `cd frontend && pnpm lint && pnpm typecheck && pnpm test`; `php artisan schedule:list` confirma watchdog; `git add backend frontend openspec/changes/complete-serpro-integration && git commit -m "feat(serpro): concluir execuções sem repetir resultado incerto"`.

**Saída verificável:** run com histórico/contagens honestos, requests auditados, nenhum cliente alheio atingido, sem endpoint placeholder. Não rodar jobs reais contra SERPRO nem migrar produção sem autorização explícita.
