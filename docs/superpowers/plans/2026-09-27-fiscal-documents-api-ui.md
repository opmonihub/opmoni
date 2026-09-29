# Fiscal Documents API and UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give each Account a fiscal coverage dashboard, a unified NF-e/CT-e document table, a secure XML detail/download and an authorized asynchronous capture action.

**Architecture:** Add tenant-scoped read services and explicit API resources on the Laravel side; only the download action touches the private disk. Nuxt uses the existing `$api` plugin, URL-driven filters and pure presentation utilities tested by Node; the fiscal shell owns navigation, not data loading. The page works with current NF-e rows before CT-e is deployed; CT-e rows appear through the same API when its connector is ready.

**Tech Stack:** Laravel 13, PHP ^8.3, Sanctum, PHPUnit, Nuxt 4, Vue 3, Nuxt UI v4, pnpm 12.5.1, `node --test` (not Vitest).

**Spec:** `openspec/changes/add-fiscal-document-capture/specs/fiscal-documents-ui/spec.md`, `openspec/changes/add-fiscal-document-capture/specs/client-fiscal-access/spec.md`, `openspec/changes/add-fiscal-document-capture/design.md` (decisions 9–12 and 145–149).

## Global Constraints

- No new runtime dependencies, API proxy, Pinia or i18n. `$api` already prefixes `/api`; call it with `/fiscal/...`, never `/api/fiscal/...`. All new pages must declare `definePageMeta({ middleware: 'auth' })` explicitly or live inside the fiscal shell that declares it.
- Read for any Account member (`admin`, `operador`, `user`); capture only `admin` and `operador`, including support mode with `SupportAudit::logWrite`. Another Account's ID returns 404, not 403 or leaked metadata. Never expose `password_encrypted`, `storage_path` or the certificate's disk path in JSON/logs.
- XML lives in `Storage::disk('fiscal')`, configured private with `serve=false`. Preview is *escaped text*, capped to 4096 bytes after `FiscalXmlEncoding::forDisplay()` from the recovery plan; never inject HTML or fetch an external entity.
- Existing `Client` is soft deleted, `FiscalDocument::client()` must use `withTrashed()` for retained identity, without returning any certificate/password. Set `account_id` explicitly in jobs; `CurrentTenant` is mutable. No real database/network in tests; `RefreshDatabase` opt-in.
- User-facing copy/tests/comments in pt-BR using `CONTEXT.md` (the tenant is **Account**). PHP: `vendor/bin/pint --dirty --format agent`. Frontend verification in order: `pnpm lint && pnpm typecheck && pnpm test` from `frontend/`.

---

## File Structure

| File | Responsibility |
|---|---|
| `backend/app/Services/Fiscal/Read/FiscalCoverage.php` (new) | Count certificate eligibility and attention by reason for the current Account. |
| `backend/app/Services/Fiscal/Read/FiscalDocuments.php` (new) | Filter/paginate rows, totals/series, related events, models available under a filter. |
| `backend/app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php` (new) | Validated filters, model/kind enums, date range, sort and page size. |
| `backend/app/Http/Controllers/Tenant/FiscalDocumentController.php` (new) | `summary`, `index`, `show`, `download`, `capture`, preview delegated to private storage. |
| `backend/app/Http/Resources/FiscalDocumentResource.php` (new) | Explicit document API allowlist, no private path; summary returns the read service's explicit safe shape. |
| `backend/app/Policies/FiscalDocumentPolicy.php` (new), `backend/app/Providers/AppServiceProvider.php`, `backend/routes/api.php` | Read/capture gate, routes under existing `auth:sanctum` + `tenant`. |
| `backend/app/Models/FiscalDocument.php` | Retain identity of soft-deleted client. |
| `backend/tests/Feature/Fiscal/FiscalDocumentApiTest.php` (new) | Account isolation, roles, filters, coverage, events, XML download and support audit. |
| `frontend/app/types/fiscal.ts`, `frontend/app/composables/useFiscal.ts` (new) | Typed API contract and calls via `$api`. |
| `frontend/app/utils/fiscalNav.ts`, `frontend/app/utils/fiscalPresentation.ts`, `frontend/app/utils/fiscalFilters.ts` (new) | Navigation, empty-state/reason labels and URL/model-filter transforms; pure Node-testable modules. |
| `frontend/app/pages/fiscal.vue`, `frontend/app/pages/fiscal/index.vue`, `frontend/app/pages/fiscal/documentos.vue` (new) | Authenticated shell, coverage-first panel, filtered table + detail sheet. |
| `frontend/app/layouts/default.vue` | Sidebar and search menu entry. |
| `frontend/tests/fiscalNav.test.ts`, `frontend/tests/fiscalPresentation.test.ts`, `frontend/tests/fiscalFilters.test.ts` (new) | Node's native TS type-stripping tests. |

**Dependency:** The recovery plan provides `FiscalXmlEncoding::forDisplay`. The rest can ship on current NF-e capture; do not wait for CT-e to make NF-e visible. Old `documents` CRUD is already removed. Neither `pnpm` tests nor Laravel tests run automatically in existing CI.

### Task 1: Authenticated summary and certificate coverage

**Files:** Create `backend/app/Services/Fiscal/Read/FiscalCoverage.php`, `backend/app/Http/Controllers/Tenant/FiscalDocumentController.php`, `backend/app/Policies/FiscalDocumentPolicy.php`, `backend/tests/Feature/Fiscal/FiscalDocumentApiTest.php`; modify `backend/routes/api.php`, `backend/app/Providers/AppServiceProvider.php`.

**Interfaces:** `FiscalCoverage::summary(int $accountId): array` returns `coverage: {total:int,capturable:int,not_capturable:int}`, `attention: list<{client_id:int,client_name:string,reason:string,blocked_until:?string}>`, `documents: {total:int,models:array<string,int>,over_time:list<{month:string,total:int}>}`, `last_capture: ?{source:string,ran_at:string,error:?string}`. Use reason keys `certificate_absent`, `certificate_expired`, `certificate_reupload`, `capture_blocked`, `history_interrupted`, `continuity_warning`, `capture_failed`; the UI consumes these exact strings.

- [ ] **Step 1: Write failing feature tests.** Generate `FiscalDocumentApiTest` with `RefreshDatabase`, `Storage::fake('fiscal')`; create two Accounts and members (pattern from existing tenant controller feature tests). Assert `GET /api/fiscal/summary` is 401 unauthenticated, 200 for own `user`, and excludes all foreign Account names/counts. For own Account create four clients: no A1, expired A1, valid A1 without password (`ClientCertificate::factory()->withoutPassword()`), valid A1 with password. Assert `coverage.total=4`, `capturable=1`, attention lists three distinct certificate reasons. Create cursors blocked and 61 days since `last_seen_at`; assert they occur as *additional* operational attention without double-decrementing coverage. No rows means `documents.total=0`, not a fabricated measurement.

  ```php
  $this->actingAs($member)->getJson('/api/fiscal/summary')
      ->assertOk()->assertJsonPath('data.coverage.total', 4)
      ->assertJsonPath('data.coverage.capturable', 1)
      ->assertJsonPath('data.coverage.not_capturable', 3);
  ```
- [ ] **Step 2: Verify red.** `cd backend && php artisan test --compact tests/Feature/Fiscal/FiscalDocumentApiTest.php --filter=test_resumo`; expect route 404.
- [ ] **Step 3: Implement.** Register `Route::get('fiscal/summary', [FiscalDocumentController::class, 'summary'])` inside existing `auth:sanctum,tenant` group. Register `Gate::policy(FiscalDocument::class, FiscalDocumentPolicy::class)` and define `viewAny(User): bool` with `HasTenantRole` (`!== null`) before using `Gate::authorize('viewAny', FiscalDocument::class)`. `FiscalCoverage` queries `Client::query()->with(['currentCertificate'])->where('account_id', $accountId)`, classifies expiry before missing password and checks the fixed `last_error='certificate_reupload'` marker from the recovery plan *before* counting a ciphertext-bearing certificate as capturable. Join cursors by client ID in the same Account; only `last_error='blocked_consumption'` plus future `blocked_until` produces `capture_blocked` (a normal `137` one-hour pause is not an alert); mark an interrupted history after `continuity_days=60` and warn from `continuity_alert_days=45` until day 60. Other safe errors produce `capture_failed`. Do not decrypt stored passwords in a read API: capture remains the decrypting guard. Count document totals/series by `account_id`, group by model and issuance month, and compute latest `last_run_at` by cursor. Return only declared keys.

  ```php
  Gate::authorize('viewAny', FiscalDocument::class);
  return response()->json(['data' => $coverage->summary((int) resolve(CurrentTenant::class)->accountId)]);
  // Calculate reason from currentCertificate: absent, expired, missing password, usable.
  ```
- [ ] **Step 4: Verify and commit.** Run focused test, Pint; commit `feat(fiscal): expose Account capture coverage`.

### Task 2: Validated, paginated document list

**Files:** Create `backend/app/Http/Requests/Tenant/IndexFiscalDocumentRequest.php`, `backend/app/Http/Resources/FiscalDocumentResource.php`, `backend/app/Services/Fiscal/Read/FiscalDocuments.php`; modify controller and `FiscalDocument` relation; extend `FiscalDocumentApiTest.php`.

**Interfaces:** `GET /api/fiscal/documents?model[]=nfe&model[]=cte&client_id=1&issuer=123&recipient=456&kind=document&issued_from=2026-09-01&issued_to=2026-09-30&amount_min=10&amount_max=100&sort=emissao_at&direction=desc&page=1&per_page=25`. Response `data: FiscalDocumentRow[]`, `meta` (Laravel pagination) and `available_models: string[]`; row: `id`, `client:{id,name,tax_id}`, `model`, `kind`, `stage`, `chave_acesso`, `emitente_cnpj`, `destinatario_cnpj`, `valor_total`, `emissao_at`, `event_count`, `mascarado`, `digval_confere`. `storage_path` never included.

- [ ] **Step 1: Write failing tests.** Create current and soft-deleted clients with NF-e/CT-e rows and an event sharing the key. Assert default sort newest issuance first, `event_count` on document row, a deleted client's retained name/tax ID, foreign row absent, combined filters (including amount range) work and unknown `model`/`kind`/sort, negative amount or reversed date/amount range returns 422. Empty filters return `data=[]` with total 0; `available_models` reflects *filtered* rows and cannot leak foreign models.

  ```php
  $this->actingAs($member)->getJson('/api/fiscal/documents?model[]=invalido')
      ->assertStatus(422)->assertJsonValidationErrors('model.0');
  $this->actingAs($member)->getJson('/api/fiscal/documents?model[]=nfe')
      ->assertOk()->assertJsonPath('available_models.0', 'nfe');
  ```
- [ ] **Step 2: Verify red.** `php artisan test --compact tests/Feature/Fiscal/FiscalDocumentApiTest.php --filter=test_lista`; expect 404.
- [ ] **Step 3: Implement.** Request rules use `Rule::in(array_column(FiscalModel::cases(), 'value'))` and `FiscalKind::cases()`, date `issued_to` `after_or_equal:issued_from`, `per_page` in `[25,50,100]`, allowed sort `emissao_at|valor_total|captured_at`; validate `client_id` with `Rule::exists('clients','id')->where('account_id', resolve(CurrentTenant::class)->accountId)` and allow soft-deleted matching ID for historical rows. In read service scope by `account_id`, filter `whereIn('model', ...)`, exact client ID, CNPJ prefix for issuer/recipient, dates, kind; default `orderByDesc('emissao_at')->orderByDesc('id')`, `paginate()->withQueryString()`, `with(['client' => fn ($q) => $q->withTrashed()])`, and count events by same client/key with stage `event` (not by NSU). `available_models` is the distinct model values *in the complete filtered match set before pagination*, plus selected values so an existing filter remains removable. Never select XML paths into a resource response.

  ```php
  'model' => ['sometimes', 'array', 'max:20'],
  'model.*' => ['required', Rule::in(array_column(FiscalModel::cases(), 'value'))],
  'kind' => ['sometimes', Rule::in(array_column(FiscalKind::cases(), 'value'))],
  'issued_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:issued_from'],
  'amount_min' => ['sometimes', 'numeric', 'min:0'],
  'amount_max' => ['sometimes', 'numeric', 'gte:amount_min'],
  'per_page' => ['sometimes', Rule::in([25, 50, 100])],
  ```
- [ ] **Step 4: Verify and commit.** Run all `FiscalDocumentApiTest.php`, Pint; commit `feat(fiscal): filter captured documents by Account`.

### Task 3: Detail, safe preview, download and capture gate

**Files:** Modify `backend/app/Policies/FiscalDocumentPolicy.php`, controller, resources, routes, `FiscalDocumentApiTest.php`. Consume `FiscalXmlEncoding::forDisplay()` from recovery plan.

**Interfaces:** `GET /api/fiscal/documents/{fiscalDocument}` returns row plus `events` sorted `evento_ocorrido_em_at,id` and `xml_preview:string|null` (4096 UTF-8 bytes max); `GET /api/fiscal/documents/{fiscalDocument}/xml` returns `application/xml` attachment; `POST /api/fiscal/clients/{client}/capture` returns 202 `{data:{queued:true,client_id:int}}` or 409 `{message:string,blocked_until:string}`. `FiscalDocumentPolicy::viewAny(User): bool`, `view(User,FiscalDocument): bool`, `capture(User,Client): bool`.

- [ ] **Step 1: Write failing tests.** Own `user` can list/show/download but POST is 403; own `admin` and `operador` can POST (`Bus::fake()` asserts one `CaptureFiscalDocumentsJob` for each selected source, if specified); blocked client POST returns 409 and no dispatch. Foreign document ID in show/download returns 404 including in support mode unless support has entered that Account; foreign client capture ID returns 404. Fake XML disk with `withStoredXml()` and assert downloaded bytes match stored raw (Latin-1 included), preview is UTF-8 and contains no external entity expansion, JSON misses `storage_path`, `password_encrypted`, `certificate_password`. In support mode assert capture logs through `SupportAudit::logWrite` with only client ID and source.

  ```php
  Bus::fake();
  $this->actingAs($reader)->postJson("/api/fiscal/clients/{$client->id}/capture")
      ->assertForbidden();
  $this->actingAs($admin)->postJson("/api/fiscal/clients/{$client->id}/capture")
      ->assertStatus(202)->assertJsonPath('data.queued', true);
  Bus::assertDispatched(CaptureFiscalDocumentsJob::class);
  ```
- [ ] **Step 2: Verify red.** `php artisan test --compact tests/Feature/Fiscal/FiscalDocumentApiTest.php --filter=test_detalhe` and `--filter=test_captura`; expect 404.
- [ ] **Step 3: Implement.** Register routes above the generic `{fiscalDocument}` route inside the tenant group; bind `FiscalDocument` policy and use `Gate::authorize` for list/show/capture. In show, explicitly load client's soft-deleted identity and same-client/same-key `FiscalStage::Event` rows, read XML only from `Storage::disk('fiscal')` after authorization, render preview as bounded text via encoding class. For download use `Storage::disk('fiscal')->download($row->storage_path, $row->chave_acesso.'.xml', ['Content-Type' => 'application/xml'])`, return 404 if file missing. For POST use `Client` restricted route binding, reject blocked sources before dispatch, `Bus` dispatch is async in production (`QUEUE_CONNECTION=redis`), call `SupportAudit::logWrite($request,'fiscal','capture',$client->id,['source'=>$source->value])`; do not wait for the SEFAZ response or claim a resulting NSU synchronously.

  ```php
  Gate::authorize('capture', [FiscalDocument::class, $client]);
  $source = FiscalSource::from($request->validate(['source' => ['sometimes', Rule::in(array_column(FiscalSource::cases(), 'value'))]])['source'] ?? 'nfe_distribuicao');
  CaptureFiscalDocumentsJob::dispatch((int) $client->id, $source);
  SupportAudit::logWrite($request, 'fiscal', 'capture', (int) $client->id, ['source' => $source->value]);
  return response()->json(['data' => ['queued' => true, 'client_id' => $client->id]], 202);
  ```
- [ ] **Step 4: Verify and commit.** `php artisan route:list --path=api/fiscal`, all API tests, Pint; commit `feat(fiscal): authorize XML and queue capture`.

### Task 4: Typed client contract and module navigation

**Files:** Create `frontend/app/types/fiscal.ts`, `frontend/app/composables/useFiscal.ts`, `frontend/app/utils/fiscalNav.ts`, `frontend/app/pages/fiscal.vue`, `frontend/tests/fiscalNav.test.ts`; modify `frontend/app/layouts/default.vue`.

**Interfaces:** `useFiscal()` returns `summary():Promise<FiscalSummary>`, `list(filters:FiscalListFilters):Promise<FiscalPage>`, `show(id:number):Promise<FiscalDetail>`, `download(id:number):Promise<Blob>`, `capture(clientId:number,source:FiscalSource):Promise<{queued:true,client_id:number}>`. Keys/types exactly match Tasks 1–3. `fiscalSidebarChildren(path:string): NavigationMenuItem[]` and `fiscalNav` pages `/fiscal`, `/fiscal/documentos`.

- [ ] **Step 1: Write red Node test.** Import `../app/utils/fiscalNav.ts`; assert `fiscalSidebarChildren('/fiscal')` marks only Painel active, `'/fiscal/documentos'` only Documentos, and detail route keeps Documentos active. TS utilities have type-only imports (`import type { NavigationMenuItem } from '@nuxt/ui'`) so Node's native type stripping can import them.

  ```ts
  assert.deepEqual(fiscalSidebarChildren('/fiscal/documentos').map(item => item.active), [false, true])
  assert.deepEqual(fiscalSidebarChildren('/fiscal').map(item => item.active), [true, false])
  ```
- [ ] **Step 2: Verify red.** `cd frontend && node --test tests/fiscalNav.test.ts`; expect module missing.
- [ ] **Step 3: Implement.** Use `$api('/fiscal/...')` everywhere, `queryOf(filters)` as in `useClients`; for download use `$api<Blob>(path,{responseType:'blob'})` with credentials preserved by plugin. `fiscal.vue` mirrors `work.vue` (`definePageMeta({middleware:'auth'})`, toolbar tabs, `<NuxtPage />`). Add sidebar group and searchable links to `default.vue` without cloning route navigation logic; put labels/routes in `fiscalNav.ts`.

  ```ts
  export const fiscalNav = [
    { label: 'Painel', icon: 'i-lucide-layout-dashboard', to: '/fiscal' },
    { label: 'Documentos', icon: 'i-lucide-files', to: '/fiscal/documentos' }
  ] as const
  // useFiscal(): const { $api } = useNuxtApp(); return $api('/fiscal/documents', { query: queryOf(filters) })
  ```
- [ ] **Step 4: Verify and commit.** Run Node test, `pnpm lint`, `pnpm typecheck`; commit `feat(fiscal): add fiscal navigation and typed API`.

### Task 5: Coverage-first dashboard with honest empty states

**Files:** Create `frontend/app/utils/fiscalPresentation.ts`, `frontend/tests/fiscalPresentation.test.ts`, `frontend/app/pages/fiscal/index.vue`.

**Interfaces:** `coverageState(summary:FiscalSummary): 'no_clients'|'no_capturable'|'no_documents'|'with_documents'`; `attentionLabel(reason:FiscalAttentionReason): string`, `attentionGroups(items:FiscalAttention[]): {reason:FiscalAttentionReason,items:FiscalAttention[]}[]`. Keep independent document count and capturable-client count.

- [ ] **Step 1: Write red Node tests.** Assert zero clients → `no_clients`; 2 noncapturable → `no_capturable` with “Reenvie o certificado” for `certificate_reupload`; 1 capturable/0 documents → `no_documents`; interruption copy contains “período perdido não pode ser recuperado” (no claim that resuming capture fixes it); empty attention returns an empty list instead of synthetic counts.

  ```ts
  assert.equal(coverageState({ coverage: { total: 2, capturable: 0, not_capturable: 2 }, documents: { total: 0 } }), 'no_capturable')
  assert.equal(attentionLabel('certificate_reupload'), 'Reenvie o certificado')
  ```
- [ ] **Step 2: Verify red.** `node --test tests/fiscalPresentation.test.ts`; expect missing functions.
- [ ] **Step 3: Implement.** Pure utility uses type-only import `../types/fiscal.ts`; page calls `useFiscal().summary()` with SSR-safe `useAsyncData('fiscal-summary', ...)` and refresh after capture, leads with coverage share, then totals by model, issuance-month series (reuse existing `unovis` charts/`MetricCard`), recent capture and grouped attention. Render a reason and client link for every noncapturable client, blocked remaining time, retryable upstream failure, and an explicit no-attention state. Do not render invented zero measurements when no documents exist; `v-if` on `coverageState` controls the four cases.

  ```ts
  export function coverageState(summary: Pick<FiscalSummary, 'coverage' | 'documents'>) {
    if (summary.coverage.total === 0) return 'no_clients'
    if (summary.coverage.capturable === 0) return 'no_capturable'
    return summary.documents.total === 0 ? 'no_documents' : 'with_documents'
  }
  ```
- [ ] **Step 4: Verify and commit.** Run Node test, `pnpm lint`, `pnpm typecheck`; commit `feat(fiscal): show portfolio coverage before volume`.

### Task 6: URL-driven unified table and escaped XML detail

**Files:** Create `frontend/app/utils/fiscalFilters.ts`, `frontend/tests/fiscalFilters.test.ts`, `frontend/app/pages/fiscal/documentos.vue`; modify `frontend/app/pages/fiscal/index.vue` to link into filtered table.

**Interfaces:** `parseFiscalFilters(query:Record<string,unknown>): FiscalListFilters`, `fiscalQuery(filters:FiscalListFilters): Record<string,string|string[]>`, `availableFiscalModels(rows:readonly FiscalDocumentRow[]): FiscalModel[]`; sort and pagination query keys match Task 2.

- [ ] **Step 1: Write red Node tests.** Assert `?model=nfe&model=cte&client_id=2&page=3` round-trips, unknown models ignored, page 0 clamped to 1, model options for rows `[nfe,cte,cte]` are `[nfe,cte]`, empty rows give none, and a selected model remains selected after reload. Use explicit `.ts` extension for imports; no Vue SFC imports (Node test runner cannot compile them).

  ```ts
  assert.deepEqual(parseFiscalFilters({ model: ['nfe', 'cte'], client_id: '2', page: '3' }).model, ['nfe', 'cte'])
  assert.equal(parseFiscalFilters({ model: 'desconhecido', page: '0' }).page, 1)
  ```
- [ ] **Step 2: Verify red.** `node --test tests/fiscalFilters.test.ts`; expect missing functions.
- [ ] **Step 3: Implement.** Read `useRoute().query` as the source of truth, update with `navigateTo({query:...})`, reset page to 1 on filter change; fetch through `useFiscal().list`, use generation guard from `customers/[documento]/[[situacao].vue` to prevent stale responses replacing new filters. Reuse `DataTableFilter`, `sheetTableUi` and existing pagination patterns. Show model, issuer, recipient, value, issuance, event count (explicit “Sem eventos” when zero), and “Nenhum documento corresponde aos filtros” on empty list. Detail sheet uses `useFiscal().show(id)` for metadata and chronological events; render `xml_preview` with `<pre>{{ detail.xml_preview }}</pre>` (no `v-html`), provide download from authenticated Blob response with a short-lived `URL.createObjectURL()` revoked after click. Capture action only if `useAuth().canManageClients.value` (script setup), show API's blocked reason until `blocked_until`, and never display a capture control for read-only members.

  ```ts
  const filters = computed(() => parseFiscalFilters(route.query))
  const updateFilters = (next: FiscalListFilters) => navigateTo({ query: fiscalQuery({ ...next, page: 1 }) })
  const models = computed(() => availableFiscalModels(rows.value))
  ```
- [ ] **Step 4: Verify and commit.** Run three fiscal Node tests, `pnpm lint && pnpm typecheck && pnpm test`; commit `feat(fiscal): browse and download captured XML`.

## Integrated verification and handoff

Run `cd backend && vendor/bin/pint --dirty --format agent && php artisan test --compact`; `cd frontend && pnpm lint && pnpm typecheck && pnpm test`; `openspec validate add-fiscal-document-capture` (check `openspec validate --help` if CLI differs). Search responses/log tests for certificate password, private storage path and unbounded raw XML. Check POST support audit, role matrix, soft-deleted client, and `api/_nuxt_icon` routing regression without modifying nginx. Any PostgreSQL-specific performance index (change task 2.6) is conditional on representative read-only `EXPLAIN`, not a blanket migration. Neither plan execution nor this verification authorizes a Swarm deploy or a real SEFAZ request.
