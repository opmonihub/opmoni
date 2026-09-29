# Integra Contador — API de Monitoramento Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Servir as 19 obrigações do Monitoramento com fontes honestas, lista filtrada no servidor, contadores coerentes e associação de clientes PJ sem disparo automático.

**Architecture:** Config versionado reproduz o registro de 19 slugs do Nuxt e associa fonte, categoria e serviço(s) sem confundir `unavailable`/`extinct` com falta de dados do cliente. Um projector puro mapeia `SerproMonitoring` sincronizado para situação/causa/colunas; controller de leitura agrega todos os itens elegíveis antes de paginar para overview e listagem concordarem. Associação grava link sem ir à fila nem ao provedor.

**Tech Stack:** Laravel ^13.17, PHP ^8.3, PHPUnit ^12.5, Nuxt ^4.5, Vue ^3.5, pnpm 12.5.1; sem nova dependência.

**Spec:** `openspec/changes/complete-serpro-integration/specs/monitoring/spec.md` e `specs/serpro-sync/spec.md`; `design.md` D6–D7; tarefas 6.13, 7.1–7.6, 10.1–10.5. Executar depois dos planos 01–04.

## Global Constraints

- `monitoringNav.ts` é registro de apresentação; `backend/config/integra-contador.php` torna-se autoridade do catálogo de leitura. Todas as 19 entradas devem coincidir por slug/categoria; revisão registrada `2026-09` ou data realmente consultada. Nenhum serviço indisponível/extinto cria linha ou contador.
- `total = em_dia + processando + pendencias + atencao`; `encerrado` **fora** da partição. PF fora do total, da lista e do sincronismo; dados retidos sem procuração atual levam `stale=true`, não uma sexta situação.
- `Sem procuração` não significa regular. `processando` vale **por obrigação**; 30 dias separam prazo próximo de `em_dia`; marker de pendência do provedor prevalece. Guia PGDAS derivada do payload já persistido, nunca de `PAGTOWEB` por conveniência.
- Mensagem listada ≠ ciência da intimação: não implementar `/messages/{id}`/`MSGDETALHAMENTO62` neste plano, manter `readMessage()` separado e inativo sem consentimento explícito. Respostas não incluem XML, payload bruto nem segredos.
- Filtrar no servidor por `situacao,q,tag_id[]`, 25 linhas por página, ordenar por `client_id`, isolamento `account_id` explícito, `404` para slug/situação inválido, `403` para `user` associar.
- Comentários e testes em português, identificadores em inglês; ler `CONTEXT.md`, `backend/AGENTS.md`, `DESIGN.md`; `vendor/bin/pint --dirty --format agent`, `php artisan test --compact`, `pnpm lint && pnpm typecheck && pnpm test`.

## Mapa de arquivos e interfaces

| Arquivo | Responsabilidade |
| --- | --- |
| `backend/config/integra-contador.php` | `services` com operação, versão e cobrança; `obligations` com 19 slugs/fonte/categoria e `catalogue_revision`. |
| `backend/app/Services/SerproObligationCatalog.php`, `backend/app/Services/SerproMonitoringProjector.php` | Validar slug/fonte; projetar uma linha com situação, causa, campos/guia e stale. |
| `backend/app/Services/SerproMonitoringReader.php` | Seleção tenant-scoped, contagens/cause aggregation, paginação e overview da mesma consulta. |
| `backend/app/Http/Controllers/Tenant/{SerproMonitoringOverviewController,SerproMonitoringObligationController,SerproMonitoringAssociationController}.php`, resources | Três endpoints públicos autenticados e JSON compatível com `useSerpro.ts`. |
| `backend/routes/api.php` | GET `/serpro/monitoring/overview`, GET `/serpro/monitoring/obligations/{obligation}`, POST `.../{obligation}/clients` dentro de `auth:sanctum,tenant`. |
| `backend/tests/Feature/{SerproMonitoringCatalogTest,SerproMonitoringApiTest,SerproMonitoringAssociationTest}.php`, `backend/tests/Unit/SerproMonitoringProjectorTest.php` | Cobertura dos 19 slugs, isolamento, aritmética e guia. |
| `frontend/app/types/serpro.ts`, `frontend/app/components/monitoring/MonitoringSheet.vue`, `frontend/app/pages/monitoring/index.vue` | Apenas ajustar divergências verificadas pelo contrato; não reconstruir telas existentes. |

**Interfaces:** `SerproObligationCatalog::find(string $slug): array{category:string,service:?string,derived_from:?string}|null`; `SerproMonitoringProjector::row(Client $client, SerproMonitoring $record, ?SerproClientAuthorization $authorization, ?SerproSyncRunItem $activeItem): array`; `SerproMonitoringReader::overview(int $accountId): array{portfolio_total:int,attention:array<string,int>}`; `::list(int $accountId,string $slug,array $filters): array{data:array,data_rows:array}`; `::associate(int $accountId,string $slug,array $ids): array{associated:int,already:int}`. Nenhum método da leitura acessa `SerproClient`.

---

### Task 1: Catálogo de 19 fontes com revisão e paths concretos

**Files:** Modify `backend/config/integra-contador.php`; Create `backend/app/Services/SerproObligationCatalog.php`; Test `backend/tests/Feature/SerproMonitoringCatalogTest.php`.

**Interfaces:** Cada `obligations[$slug]` declara `category` (`direct|derived|unavailable|extinct`), `service` (`idSistema/idServico` ou null), `derived_from` opcional. `services[$idServico]` mantém `path,versaoSistema,billable` e adiciona os serviços abaixo sem substituir existentes.

- [ ] **Step 1: Teste vermelho.** Extrair slugs e categorias do registro frontend (ler `frontend/app/utils/monitoringNav.ts`; não importar o TS no PHPUnit) e afirmar os 19 exatos, com categorias: unavailable `parcelamentos/pgfn`, `declaracoes/fgts`; extinct `declaracoes/dirf`; derived `fgts-digital`, `parcelamentos/receita-federal`, `situacao-fiscal/certidoes`, `caixas-postais/{fgts-digital,det}`; demais direct. Serviços sem fonte têm null e não retornam linhas.

```php
$map = config('integra-contador.obligations');
$this->assertCount(19, $map);
$this->assertSame('unavailable', $map['parcelamentos/pgfn']['category']);
$this->assertSame('extinct', $map['declaracoes/dirf']['category']);
$this->assertSame('derived', $map['situacao-fiscal/certidoes']['category']);
$this->assertNull($map['declaracoes/fgts']['service']);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproMonitoringCatalogTest.php`; esperado: mapa incompleto.
- [ ] **Step 3: Completar mapa.** Incluir `CONSULTAROPCAOREGIME103` (00060), `CONSDECLARACAO13` (00146), `CONSDECLARACAO142` (00146), `CONSXMLDECLARACAO38` (00103), `RELATORIOSITFIS92` (00002), `PAGAMENTOS71` (00004), `MSGCONTRIBUINTE61` (00006), `CONSULTASITUACAODTE111` (00050), `OBTERPROCURACAO41` (sem procuração), com paths/versões publicados. Para parcelas/MEI consultar as páginas **atuais** do catálogo antes de preencher `idServico`: manter `service` por família `PARCSN`, `PERTSN+RELPSN`, `PARCSN-ESP`, `PGMEI` com `sync_enabled=false` até haver `idServico/path` verificados, **sem** inventar path ou marcar como `unavailable` (o serviço existe). Adicionar `catalogue_revision` com data da leitura; teste exige que todo `service` concreto sincronizado pertença a `services`.

```php
'catalogue_revision' => '2026-09',
'services' => [
    'CONSULTAROPCAOREGIME103' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
    'CONSDECLARACAO13' => ['path' => 'Consultar', 'versaoSistema' => '1.0', 'billable' => true],
    'RELATORIOSITFIS92' => ['path' => 'Emitir', 'versaoSistema' => '2.0', 'billable' => true],
],
'obligations' => [
    'simples-nacional' => ['category' => 'direct', 'service' => 'REGIMEAPURACAO/CONSULTAROPCAOREGIME103', 'derived_from' => null, 'sync_enabled' => true],
    'mei' => ['category' => 'direct', 'service' => 'PGMEI', 'derived_from' => null, 'sync_enabled' => false],
    'parcelamentos/pgfn' => ['category' => 'unavailable', 'service' => null, 'derived_from' => null, 'sync_enabled' => false],
],
// Mesclar essas entradas ao mapa existente, sem substituir serviços atuais.
```
- [ ] **Step 4: Provar/commitar.** `cd backend && php artisan test --compact tests/Feature/SerproMonitoringCatalogTest.php`; `git add backend/config/integra-contador.php backend/app/Services/SerproObligationCatalog.php backend/tests && git commit -m "feat(monitoramento): classificar 19 obrigações pelo catálogo"`.

### Task 2: Projetar situação, causa e guia sem confundir dado ausente com regular

**Files:** Create `backend/app/Services/SerproMonitoringProjector.php`; Test `backend/tests/Feature/SerproMonitoringProjectorTest.php`.

**Interfaces:** `row(...)` retorna exatamente `client_id,name,tax_id,situacao,cause,due_on,stale,power_of_attorney_expires_on,fields,periods,message` conforme `frontend/app/types/serpro.ts:76-101`; `periods` pode ser `null` se não entregue; `message` só stub sem corpo.

- [ ] **Step 1: Teste vermelho.** Usar `Carbon::setTestNow('2026-09-27')`: sem declaração ⇒ `atencao/sem_declaracao`; sem procuração ⇒ `atencao/sem_procuracao`, retido ⇒ `stale=true`; expirada ⇒ `procuracao_invalida`; flag de débitos ⇒ `contam_debitos`; item atual queued/running para **mesma** obrigação ⇒ `processando`; prazo em 30 dias ⇒ `pendencias`, em 31 ⇒ `em_dia`; pagamento encerrado ⇒ `encerrado` fora dos contadores.

```php
Carbon::setTestNow('2026-09-27 12:00:00');
$row = $projector->row($client, $record, $expiredAuthorization, null);
$this->assertSame('atencao', $row['situacao']);
$this->assertSame('procuracao_invalida', $row['cause']);
$this->assertTrue($row['stale']);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproMonitoringProjectorTest.php`; esperado: projector ausente.
- [ ] **Step 3: Implementar.** Projetar de `SerproMonitoring::{state,cause,due_on,fields,periods,messages,source_at}` com prioridade explícita: item atual em processamento, causa de atenção, encerrado, pendência upstream/prazo 30d, em dia; `stale` depende só de autorização vencida. PGDAS: para cada período reter última transmissão retificadora, `indiceDas` ⇒ `slip_number,slip_issued_at,slip_paid,due_on`; não afirmar que `due_on` do período é vencimento do boleto se fonte não o confirma. Caixa postal usa apenas assunto/IDs; nunca ler corpo.

```php
$situation = match (true) {
    $activeItem?->current_obligation === $record->obligation
        && $activeItem->run?->state === SerproSyncRunState::Running => 'processando',
    $record->cause !== null => 'atencao',
    $record->state === 'encerrado' => 'encerrado',
    $record->due_on !== null && $record->due_on->lte(today()->addDays(30)) => 'pendencias',
    default => 'em_dia',
};
```
- [ ] **Step 4: Provar/commitar.** Teste unitário/feature, `git add backend/app/Services/SerproMonitoringProjector.php backend/tests && git commit -m "feat(monitoramento): projetar situação, causas e guias sincronizadas"`.

### Task 3: Listagem filtrada e overview com a mesma regra de contagem

**Files:** Create `backend/app/Services/SerproMonitoringReader.php`, `backend/app/Http/Controllers/Tenant/{SerproMonitoringOverviewController,SerproMonitoringObligationController}.php`, resources para linha/summary; Modify `backend/routes/api.php`; Test `backend/tests/Feature/SerproMonitoringApiTest.php`.

**Interfaces:** GET overview `{data:{portfolio_total,attention:{[slug]:number}}}`; GET obligation `{data:{obligation,category,total,em_dia,processando,pendencias,atencao,encerrado,progress,current_page,attention_reasons:[{code,count,label}]},data_rows:[MonitoringClient]}`; unserved ⇒ `data` sem contadores e `data_rows=[]` (ajustar TS para `null` no resumo destes casos; não retornar zero fingindo obrigação servida).

- [ ] **Step 1: Teste vermelho.** Dois Accounts e um PJ/PF em cada: overview PJ com registro único conta 1, PF 0; filtro `situacao=atencao` retorna apenas as linhas que compõem contador atenção; `q` e `tag_id[]` filtram antes de paginação; página 2 não duplica; causa conta exatamente as linhas; slug/situação inexistente 404; unserved/extinct não geram linha nem número; overview coincide com cada listagem mesmo após re-sync.

```php
$this->actingAs($member, 'sanctum')->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas?situacao=atencao')
    ->assertOk()->assertJsonPath('data.atencao', 1)
    ->assertJsonPath('data.total', 1)
    ->assertJsonCount(1, 'data_rows');
$this->actingAs($member, 'sanctum')->getJson('/api/serpro/monitoring/obligations/inexistente')->assertNotFound();
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproMonitoringApiTest.php`; esperado: 404 nos endpoints.
- [ ] **Step 3: Implementar.** Reader começa em `SerproMonitoring::where('account_id',$accountId)` + `clients.person_type='company'`; inclui registro com `source_at` preenchido **ou** item de execução `Running` com `current_obligation=$slug` (mesmo sem fonte prévia, mostrando `processando`). Associação isolada com `source_at=null` e sem execução fica fora. Eager loads cliente/tags/autorizações/item corrente, projeta e agrupa por slug; agrega **antes** de filtros de situação/busca/tags. `total` soma quatro, `encerrado` separado; filtros selecionam `data_rows`, `current_page` 1-based; `attention_reasons` agrupa `code` e resolve label de apresentação. Overview reutiliza mesmo agregador por slug, sem cache stale. Controllers finos; rotas dentro `['auth:sanctum','tenant']`.

```php
$activeClientIds = SerproSyncRunItem::query()->where('account_id', $accountId)
    ->where('current_obligation', $slug)
    ->whereHas('run', fn ($query) => $query->where('state', 'running'))
    ->pluck('client_id');
$records = SerproMonitoring::query()->where('account_id', $accountId)
    ->where('obligation', $slug)
    ->where(fn ($query) => $query->whereNotNull('source_at')->orWhereIn('client_id', $activeClientIds))
    ->whereHas('client', fn ($query) => $query->where('account_id', $accountId)->where('person_type', 'company'))
    ->with(['client.tags', 'client.serproAuthorizations'])->orderBy('client_id')->get();
$summary['total'] = $summary['em_dia'] + $summary['processando']
    + $summary['pendencias'] + $summary['atencao'];
$rows = array_slice($filteredRows, ($page - 1) * 25, 25);
```
- [ ] **Step 4: Provar/commitar.** `cd backend && php artisan test --compact tests/Feature/SerproMonitoringApiTest.php`; `git add backend/app backend/routes/api.php backend/tests && git commit -m "feat(monitoramento): servir contadores e listas por Account"`.

### Task 4: Associação idempotente de PJ sem efeito colateral na fila

**Files:** Create `backend/app/Http/Requests/Tenant/AssociateSerproClientsRequest.php`, `backend/app/Http/Controllers/Tenant/SerproMonitoringAssociationController.php`; Modify `backend/app/Services/SerproMonitoringReader.php`, `backend/routes/api.php`; Test `backend/tests/Feature/SerproMonitoringAssociationTest.php`.

**Interfaces:** POST `/api/serpro/monitoring/obligations/{obligation}/clients` com `{client_ids:int[]}` retorna `{data:{associated:int,already:int}}`; `::associate(int,string,array): array{associated:int,already:int}`. Não cria `SerproSyncRun` nem dispatch.

- [ ] **Step 1: Teste vermelho.** `admin|operador` associa um PJ novo e reenvia IDs ⇒ `associated=1,already=1` no primeiro lote misto; `user` 403; PF/ID de outro Account 422 (nenhuma inserção); unserved/extinct 422; `Queue::fake()` ⇒ nenhum job despachado e `serpro_sync_runs` continua vazio.

```php
Queue::fake();
$this->actingAs($operator, 'sanctum')->postJson('/api/serpro/monitoring/obligations/declaracoes/pgdas/clients', [
    'client_ids' => [$newClient->id, $existingClient->id],
])->assertOk()->assertJsonPath('data.associated', 1)->assertJsonPath('data.already', 1);
Queue::assertNothingPushed();
$this->assertDatabaseCount('serpro_sync_runs', 0);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproMonitoringAssociationTest.php`; esperado: 404.
- [ ] **Step 3: Implementar.** Form Request exige lista `min:1`, `distinct`, `exists` **escopado** ao Account e PJ; validar slug pelo catálogo; em transação inserir `SerproMonitoring` com `account_id,client_id,obligation,state='sem_dados',source_at=null` apenas para IDs não associados, contar já existentes; jamais chamar starter/job/SerproClient. Reader ignora `source_at IS NULL` quando não há execução atual dessa obrigação: associar não inventa situação `em_dia` nem `sem_declaracao`. Repetição é idempotente pela unique `(account_id,client_id,obligation)`.

```php
foreach ($clientIds as $clientId) {
    $record = SerproMonitoring::query()->firstOrCreate(
        ['account_id' => $accountId, 'client_id' => $clientId, 'obligation' => $slug],
        ['state' => 'sem_dados', 'source_at' => null],
    );
    $record->wasRecentlyCreated ? $associated++ : $already++;
}
return ['associated' => $associated, 'already' => $already];
```
- [ ] **Step 4: Provar/commitar.** Teste específico; `git add backend/app backend/routes/api.php backend/tests && git commit -m "feat(monitoramento): associar PJ sem iniciar execução"`.

### Task 5: Fechar contrato com o frontend e verificar caminho povoado

**Files:** Modify `frontend/app/types/serpro.ts`, `frontend/app/components/monitoring/MonitoringSheet.vue`, `frontend/app/pages/monitoring/index.vue` somente se contrato exigir; Test `backend/tests/Feature/SerproMonitoringApiTest.php`, `frontend/tests/{monitoringStatus,monitoringFormat,monitoringRoutes}.test.ts`.

**Interfaces:** `useSerpro.ts` mantém paths sem `/api`; `readMessage` permanece separado e sem caller automático. Unserved não apresenta `0` nem badge de pendência; listagem servida exibe campos reais/guia, loading, vazio e erro distintos.

- [ ] **Step 1: Teste vermelho de ponta a ponta sem rede.** Com `Http::fake()` e factories, rodar uma `SerproSyncRun` via jobs síncronos usando fixture PGDAS de declaração paga/retificadora; GET overview/listagem deve retornar cliente real, contador e campos de guia. Repetir com Account vizinho ⇒ vazio. Comparar todos os 19 slugs em teste de contrato entre arquivo TS (leitura textual ou teste Node separado) e mapa PHP.

```php
$this->actingAs($member, 'sanctum')->getJson('/api/serpro/monitoring/overview')
    ->assertOk()->assertJsonPath('data.portfolio_total', 1);
$this->actingAs($member, 'sanctum')->getJson('/api/serpro/monitoring/obligations/declaracoes/pgdas')
    ->assertOk()->assertJsonPath('data_rows.0.client_id', $client->id)
    ->assertJsonPath('data_rows.0.periods.0.slip_paid', true);
```
- [ ] **Step 2: Rodar.** `cd backend && php artisan test --compact tests/Feature/SerproMonitoringApiTest.php`; esperado: asserção de dado povoado falha se writer/projector divergir.
- [ ] **Step 3: Ajustar contrato real.** No frontend tipar resumo de unserved como variante discriminada de `MonitoringObligationSummary` com contadores `null` quando `category: 'unavailable'|'extinct'`; não somar `null` na UI; manter `getCachedData:()=>undefined` em overview e `loadMore` com guarda de geração; 404 para slug conhecido pode manter empty fallback, mas as telas devem provar sucesso com dados populados e erro 5xx não vira vazio. Não unir `readMessage` e `listObligation`.

```ts
export type MonitoringObligationResponse = MonitoringObligationSummary | {
  obligation: string
  category: 'unavailable' | 'extinct'
  total: null
  em_dia: null
  processando: null
  pendencias: null
  atencao: null
  encerrado: null
  current_page: 1
  attention_reasons: []
  progress: null
}
// MonitoringSheet não pede listObligation quando monitoringObligationUnserved é true.
```
- [ ] **Step 4: Verificar e commitar.** `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact`; `cd frontend && pnpm lint && pnpm typecheck && pnpm test`; `cd backend && php artisan route:list --path=api/serpro`; checar respostas e logs para segredo/XML/PFX; `git add backend frontend && git commit -m "test(monitoramento): provar painel povoado e categorias honestas"`.

**Saída verificável:** 19 slugs categorizados, telas populadas por dados sincronizados, contadores/listas coerentes e isolamento de Account. Não executar `docker stack deploy`, `migrate:fresh` nem teste de rede/produção sem autorização explícita. Arquivar o change só após todos os planos e specs reconciliados.
