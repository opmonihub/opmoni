# Monitoring Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fictitious monitoring module with a server-driven one — remove the ten hand-written companies and the arithmetic status derivation, read the counters, the per-client situation and the named cause behind each aggregate from the API, classify each of the nineteen obligations by what the SERPRO catalogue actually serves, and add the connection, terms, runs and mailbox-message screens.

**Architecture:** `monitoringNav.ts` keeps its role as a route registry and becomes the single declaration of each obligation — its slug, its navigation position, its columns, its service and its catalogue category. A new `app/types/serpro.ts` and `app/composables/useSerpro.ts` carry the wire contract, following `useWork`/`useClients`. A new `app/utils/monitoringPresentation.ts` owns every label, colour and format, so no template writes a situation name inline. `MonitoringSheet.vue` becomes a server-filtered, paginated list under a four-state ladder. Pages own their state through `useAsyncData`, as everywhere else in this codebase.

**Tech Stack:** Nuxt 4.5, Vue 3, `@nuxt/ui` ^4.11.1, Tailwind 4, zod ^4.6.5, `node --test` (no test framework).

**Spec:** `openspec/changes/add-integra-contador-sync/specs/monitoring/spec.md` (9 requirements), `specs/serpro-connection/spec.md`, `design.md` D5, D6, D8, D14, D15, D19, D20, D21, D22, and `tasks.md` groups 8, 9 and 10.

---

## The dependency you must know before starting

**The API below does not exist yet.** The backend read API is `tasks.md` groups 6 and 7. This plan ships against a **contract**, not a running server.

That is deliberate. Every read degrades honestly: a missing endpoint answers `404`, and `404` is rendered as an empty list — the pattern `app/pages/inbox.vue:20-30` already uses. It is honest in both worlds, because the obligation slug comes from the registry and is never typed by a user, so a `404` means "no data", never "wrong URL". This is the inert state `tasks.md` 10.3 specifies.

Do **not** add mock data, sample rows or placeholder counts to make the screens look alive. That is the sin this whole change exists to remove, and a fabricated `regular` is the worst possible regression.

---

## The nineteen obligations

This table is the contract. It is derived from the provider's own catalogue, and it is **configuration, not code** (D22), carrying the catalogue revision it was read from. A test asserts all nineteen still resolve.

| Obligation | Service | Procuração | Category | Columns |
|---|---|---|---|---|
| Simples Nacional | `REGIMEAPURACAO/CONSULTAROPCAOREGIME103` | `00060` | `direct` | regime escolhido, data da opção |
| MEI | `PGMEI/DIVIDAATIVA24` | — | `direct` | dívida ativa |
| DCTFWeb | `DCTFWEB/CONSXMLDECLARACAO38` | `00103` | `direct` | GI_Declaracao, receitas |
| FGTS Digital | `DCTFWEB/CONSXMLDECLARACAO38` | `00103` | `derived` | valor apurado `1718` |
| Parcelamentos › Simples Nacional | `PARCSN` | `00076`+`00188` | `direct` | — |
| Parcelamentos › PGFN | — | — | `unavailable` | — |
| Parcelamentos › Receita Federal | `PERTSN` + `RELPSN` | `00149`+`10011`, `00210`+`10036` | `derived` | — |
| Parcelamentos › Especiais | `PARCSN-ESP` | `00125` | `direct` | — |
| Situação Fiscal › Relatório Fiscal | `SITFIS/RELATORIOSITFIS92` | `00002` | `direct` | — |
| Situação Fiscal › Certidões | `SITFIS/RELATORIOSITFIS92` | `00002` | `derived` | certidão, emissão, validade |
| Situação Fiscal › Comprovantes | `PAGTOWEB/PAGAMENTOS71` | `00004` | `direct` | — |
| Caixas Postais › e-CAC | `CAIXAPOSTAL/MSGCONTRIBUINTE61` | `00006` | `direct` | não lidas, última |
| Caixas Postais › FGTS Digital | `CAIXAPOSTAL`, filtro de assunto | `00006` | `derived` | — |
| Caixas Postais › DET | `CAIXAPOSTAL`, filtro de assunto | `00006` | `derived` | — |
| Declarações › PGDAS | `PGDASD/CONSDECLARACAO13` | `00146` | `direct` | GI_Declaracao, guia emitida e paga |
| Declarações › DCTFWeb | `DCTFWEB/CONSXMLDECLARACAO38` | `00103` | `direct` | — |
| Declarações › FGTS | — | — | `unavailable` | — |
| Declarações › DEFIS | `DEFIS/CONSDECLARACAO142` | `00146` | `direct` | — |
| Declarações › DIRF | — | — | `extinct` | — |

Sixteen have a source, two do not, one is obsolete. The three that are not `direct` exist for reasons worth carrying in a comment beside them, because they are the ones an implementer will otherwise "fix":

- **`00146` is shared by `PGDASD` and `DEFIS`.** One client's grant covers both obligations, so the office setup instructions must not present them as two grants (D5).
- **`PGFN` has no service.** All eight parcelamento systems say the debts are Simples Nacional ones under collection at the RFB; federal parcelamento runs on Receita's own channels. A "Receita Federal" tab is served by `PERTSN` and `RELPSN`, which are Simples Nacional debts under federal programmes — so the name is a misnomer and the registry comment says so.
- **`DIRF` is not waiting for data.** `IN RFB 2.043/2021` replaced it with EFD-Reinf and eSocial, and `IN RFB 2.181/2024` moved that to facts from 1 January 2025. Neither successor is exposed.

**An obligation that is `unavailable` or `extinct` presents no counter and no client row.** It says which of the two it is and why. That is a different state from "no clients", and the spec forbids presenting it as a client's pending.

---

## The contract

```
GET /api/serpro/monitoring/overview
→ { data: { portfolio_total: 42, attention: { "simples-nacional": 3, … } } }

GET /api/serpro/monitoring/obligations/{obligation}
    ?situacao=atencao&q=&tag_id[]=
→ { data: { total: 12, em_dia: 8, processando: 1, pendencias: 1, atencao: 2,
            encerrado: 3,
            attention_reasons: [ { code, label, color, count } ] },
    data_rows: [ MonitoringClient ] }

GET /api/serpro/monitoring/obligations/{obligation}/messages/{id}
→ { data: { codigo, assunto, corpo, lida_em, ciencia_em, prazo_limite } }

POST /api/serpro/monitoring/obligations/{obligation}/clients
     { client_ids: number[] }
→ { data: { associated: number, already: number } }
```

`encerrado` sits outside the four, and `total` is the sum of the four. The counters are zero-filled, never omitted. `attention_reasons` is the backend's list; the client maps `code` to presentation and never hardcodes a cause.

Associating a client creates its tracking row and **starts no run**. The run is a separate action, and the spec refuses a second concurrent run per Account, so coupling the two would mean every association queued work behind whatever was already in flight. The response reports how many were associated and how many were already there, so the client can say so rather than treating a no-op as a silent success.

```
GET  /api/serpro/connection          → configured flag + non-secret metadata only
PUT  /api/serpro/connection          → key, secret?, certificate?, password?
POST /api/serpro/connectivity        → exercises authentication, touches no client
GET  /api/serpro/authorization-terms → the office's term
GET  /api/serpro/sync-runs           → list with state, counts, timestamp
GET  /api/serpro/sync-runs/{id}      → items per client
POST /api/serpro/sync-runs/{id}/resync
```

`PUT /connection` sends `consumer_secret` **only when the operator typed something** — the "Segredo preservado na atualização" scenario. The form disappears after saving because the API never returns the secret.

---

## Global Constraints

- **`AGENTS.md` (root) and the frontend conventions are authoritative.** Frontend is `pnpm`, never `npm`. No new dependencies.
- **No mock data, no sample rows, no placeholder counts.** An empty screen is a correct screen.
- **`useAsyncData`, never `useFetch`.** Destructure `data, status, error, refresh`; `isLoading = computed(() => status.value === 'pending')`; `pending` is never destructured.
- **Composables return functions, not refs.** `const { $api } = useNuxtApp()` once at the top; one `async function` per endpoint; `$api<{ data: X }>` unwrapped with `return res.data`; params through `queryOf()` imported as `from './useApiQuery'`. Reference: `app/composables/useWork.ts:4-82`.
- **Types in `app/types/`, `interface` for shapes and `type` for unions**, field names in the backend's `snake_case`, imported by module path — there is no barrel for domain types.
- **No situation label, colour or column is written inline in a template.** Every one comes from `app/utils/monitoringPresentation.ts`, following `app/utils/portfolioLabels.ts:46-89`.
- **A `403` is never a global redirect and never a hard error on a page.** `app/plugins/api.ts:42-52` only redirects on 401/419 by design. Gate preventively on the role, and degrade with `color: 'warning'` only when the integration is not enabled — never when the member simply lacks the role.
- **Toast for events, `UAlert` for state.** `toast.add({ title, description?, color })`, pt-BR, `success` / `error` / `warning`.
- **The overview must not serve a stale count.** Pass `getCachedData: () => undefined`, or the spec scenario "Atualização após sincronização" fails.
- **Forms follow `app/components/customers/ClientCadastroModal.vue`, not `settings/index.vue`.** The latter is a stub whose `onSubmit` only toasts and never calls the API.
- **Verify with the narrowest command**: `pnpm lint`, `pnpm typecheck`, `node --test tests/`.

---

### Task 1: Types, composable, and the obligation registry

**Files:**
- Create: `frontend/app/types/serpro.ts`
- Create: `frontend/app/composables/useSerpro.ts`
- Modify: `frontend/app/utils/monitoringNav.ts`

**Interfaces:**
- Produces `MonitoringStatus` (the nine row labels), `MonitoringCounter` (the four), `ObligationCategory` (`direct` | `derived` | `unavailable` | `extinct`), `MonitoringClient`, `MonitoringObligationSummary`, `AttentionReason`, `MonitoringMessage`, `SerproConnectionMetadata`, `SerproConnectivityResult`, `SerproAuthorizationTerm`, `SerproSyncRun`, `SerproSyncRunItem`, and `useSerpro()`.

- [ ] **Step 1: Declare the types**

Create `app/types/serpro.ts`. `MonitoringCounter` is the four values that sum to the total. `MonitoringStatus` is the nine row labels seen in the reference: `em_dia`, `pendencias`, `processando`, `atencao`, `encerrado`, `sem_declaracao`, `sem_procuracao`, `procuracao_invalida`, `contam_debitos`. The last four are *causes* and reach the client inside `AttentionReason`, not as free-standing states — declare them as a `AttentionReasonCode` union so the compiler enforces that a cause is never mistaken for a counter.

- [ ] **Step 2: Declare the nineteen obligations in the registry**

Each entry gains three fields beyond today's `label`/`icon`/`segments`: `columns`, `service` and `category`. `service` is `null` for `unavailable` and `extinct`. The table at the top of this plan is the source; transcribe it exactly, and put the three carrying comments in code — the shared `00146`, the PGFN absence, and the DIRF extinction.

`columns` is per obligation, replacing the `monitoringColumns` record keyed by family. The reference shows why: Parcelamentos, Situação Fiscal and Caixas Postais carry no obligation column at all, while FGTS carries two. `family` stops choosing columns and survives only as a navigation grouping.

- [ ] **Step 3: Write the composable**

Following `useWork.ts`. `listObligation` returns the **whole envelope**, because the page needs both the counters and `attention_reasons`. `readMessage` is a separate function from `listMessages`, and that separation is load-bearing — see Task 8.

- [ ] **Step 4: Verify**

Run: `cd frontend && pnpm typecheck`
Expected: PASS, with nothing else broken yet.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add app/types/serpro.ts app/composables/useSerpro.ts app/utils/monitoringNav.ts
git commit -m "feat(monitoring): obligation registry with catalogue sources and categories"
```

---

### Task 2: Presentation

**Files:**
- Create: `frontend/app/utils/monitoringPresentation.ts`
- Test: `frontend/tests/monitoringStatus.test.ts`
- Test: `frontend/tests/monitoringFormat.test.ts`

**Interfaces:**
- Produces `monitoringCounterPresentation`, `monitoringStatusPresentation`, `monitoringAttentionReasonPresentation(code)`, `monitoringCategoryPresentation`, `formatMonitoringCount`, `formatMonitoringDueOn`, `slipStatusFor`.

- [ ] **Step 1: Write the failing tests**

Using the house prelude — `import assert from 'node:assert/strict'`, `import { describe, it } from 'node:test'`, and a relative import with the explicit `.ts` extension, because `~/` does not exist under `node --test`.

Cover: every one of the four counters has a label; every one of the nine row labels has a label, a colour and an icon; every category has a label and a paragraph explaining it; `formatMonitoringCount(0)` returns `'0'`; and `slipStatusFor` picks the most recent transmission when a period has both an original and a rectified declaration.

- [ ] **Step 2: Run them to see them fail**

Run: `cd frontend && node --test tests/monitoringStatus.test.ts`
Expected: FAIL — the module does not exist.

- [ ] **Step 3: Implement**

The maps are the single place a value becomes a label and a colour, layered the way `portfolioLabels.ts:46-89` layers label, appearance and merged presentation. `monitoringAttentionReasonPresentation` takes the **code** and resolves it, so a new cause arriving from the backend renders without a deploy — and a code it does not know must fall back visibly rather than silently to a neutral badge, because a cause the operator cannot read is worse than one that is missing.

- [ ] **Step 4: Run them to see them pass**

Run: `cd frontend && node --test tests/monitoringStatus.test.ts tests/monitoringFormat.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add app/utils/monitoringPresentation.ts tests/monitoringStatus.test.ts tests/monitoringFormat.test.ts
git commit -m "feat(monitoring): presentation for counters, situations and categories"
```

---

### Task 3: Registry surgery and navigation

**Files:**
- Modify: `frontend/app/utils/monitoringNav.ts`
- Modify: `frontend/app/layouts/default.vue`
- Modify: `frontend/app/utils/adminNav.ts`
- Test: `frontend/tests/monitoringRoutes.test.ts`

**Interfaces:**
- Produces `monitoringGroups` (eight top-level obligations, four with sub-tabs), `monitoringIntegrationLinks` (termos, execuções), `monitoringListPath`, `parseMonitoringSlug`, `monitoringSidebarChildren`, and a `Serpro` entry in `adminPages`.

- [ ] **Step 1: Write the failing test**

`tests/monitoringRoutes.test.ts` covering: an obligation slug resolves; an obligation slug with a situation segment resolves and returns it; an unknown obligation returns `null`; an unknown trailing segment is treated as part of the body and returns `null`; every path in `monitoringIntegrationLinks` returns `null` from `parseMonitoringSlug`; and all nineteen obligations resolve to a registry entry.

- [ ] **Step 2: Remove the fake data**

Delete `monitoringCompanies`, `statusCycle`, `monitoringStatusFor`, `pageOrder` and `monitoringAttentionCount`. Move `MonitoringStatus` to `app/types/serpro.ts` and update the five importers.

- [ ] **Step 3: Restructure the navigation**

Eight top-level items — `Simples Nacional`, `MEI`, `DCTFWeb`, `FGTS Digital`, `Parcelamentos`, `Situação Fiscal`, `Caixas Postais`, `Declarações` — with `Simples Nacional` and `MEI` split, and sub-tabs on the other four, exactly as the reference. `monitoringSidebarChildren` keeps collapsing each group to its first page. Append `monitoringIntegrationLinks` after the groups.

The two integration screens are static routes, resolved by Nuxt ahead of the `[...slug].vue` catch-all, which is why `parseMonitoringSlug` never sees them — and that is what the route test pins.

- [ ] **Step 4: Add the admin entry**

`adminPages` gains `{ label: 'Serpro', icon: 'i-lucide-plug', to: '/admin/serpro' }`. It inherits the `super-admin` middleware from the parent `app/pages/admin.vue`, which is what makes the platform credential platform-only.

- [ ] **Step 5: Verify**

Run: `cd frontend && node --test tests/monitoringRoutes.test.ts && pnpm typecheck`
Expected: PASS after the remaining references to the deleted exports are fixed in the files that still use them.

- [ ] **Step 6: Commit**

```bash
cd frontend && git add app/utils/monitoringNav.ts app/layouts/default.vue app/utils/adminNav.ts tests/monitoringRoutes.test.ts
git commit -m "refactor(monitoring): eight-item navigation, drop fictitious data"
```

---

### Task 4: The overview

**Files:**
- Modify: `frontend/app/pages/monitoring/index.vue`

- [ ] **Step 1: Replace the data source**

One `useAsyncData('serpro-monitoring-overview', …)` with **`getCachedData: () => undefined`**, so a synchronization that just finished shows up on the next visit. Each card takes its value from the backend and displays a zero rather than hiding it. The "Na carteira" card takes `portfolio_total`. Render the two `monitoringIntegrationLinks` below the groups.

- [ ] **Step 2: Add the states**

The ladder of `work/processos.vue:595-632`: a `UAlert` with "Tentar novamente" calling `onRefresh()`, and a `watch(error, …)` that toasts. `onRefresh` wraps `refresh()` in try/catch. `MetricCard` takes its `loading` prop while pending — the prop exists and is currently unused.

- [ ] **Step 3: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. The overview now shows zeros, which is the correct inert state.

- [ ] **Step 4: Commit**

```bash
cd frontend && git add app/pages/monitoring/index.vue
git commit -m "feat(monitoring): overview reading counters from the API"
```

---

### Task 5: The obligation page

**Files:**
- Create: `frontend/app/components/monitoring/ObligationCounters.vue`
- Modify: `frontend/app/components/monitoring/MonitoringSheet.vue`

- [ ] **Step 1: Delete the three local filters**

Remove the `searched` computed, the status filter inside `rows`, and the `statusCount` recomputation. All three filter the same local array the fake data lived in. `agency`, `document` and `mailbox` stop being `page.label` and start reading the row's real fields through the registry's `columns`.

- [ ] **Step 2: Build the counter row**

A component taking the obligation's summary and rendering `Total`, `Em dia`, `Processando`, `Pendências` and `Atenção`, plus `Encerrado` shown as a row-level state rather than a counter. `Total` displays the backend's total, and a test asserts the four sum to it.

Each counter is a **navigation target**, not a local filter: clicking navigates to `/monitoring/<obligation>/<situacao>`, so the selection is carried by the route and shareable, per the spec. The selected counter is marked on arrival.

The two progress counters the reference shows beside the state row are a **separate axis** fed by the run, not a client state. Do not mix them into the four.

- [ ] **Step 3: Add server-side filtering and pagination**

A `DataTableFilter` with a filter descriptor, and `refDebounced(search, 350)` — the house debounce is 350 ms (`useClientListFilters.ts:31`), never on every keystroke. Empty multi-selects become `undefined` so `queryOf` drops them.

Pagination follows `customers/[documento]/[[situacao]].vue:113-136`: a generation counter, a `loadingMore` ref, and a `loadMore()` that captures the generation and discards the result if it moved, so two fast filter changes cannot interleave and append a stale page.

- [ ] **Step 4: Build the ladder**

The order of `work/processos.vue:595-632`: `UAlert` with "Tentar novamente" → skeleton → empty with no filter → empty after filter with "Limpar filtros" → content. The spec forbids presenting a failed load as an empty list, and the current component has neither an error nor a loading branch.

A `404` renders as the empty state, following `inbox.vue:20-30`. Guard the two empty branches on a `failed` ref so a 404 cannot reach the error alert.

- [ ] **Step 5: Branch on the obligation's category**

`unavailable` and `extinct` render **no counters and no rows** — a page that states which of the two it is and why, from `monitoringCategoryPresentation`. `tasks.md` 10.5 exists because this branch is the one most likely to be quietly dropped in a later refactor, leaving a permanently empty page that reads as a bug.

- [ ] **Step 6: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
cd frontend && git add app/components/monitoring/MonitoringSheet.vue app/components/monitoring/ObligationCounters.vue
git commit -m "feat(monitoring): obligation counters, server filtering and category branch"
```

---

### Task 6: Associating clients to an obligation

**Files:**
- Create: `frontend/app/components/monitoring/AssociateClientsModal.vue`
- Modify: `frontend/app/components/monitoring/ObligationCounters.vue`
- Modify: `frontend/app/composables/useSerpro.ts`

- [ ] **Step 1: Build the modal**

A `UModal` following `EcacPowerOfAttorneyModal.vue` and `ClientDeleteModal.vue` for the shell, and `TagsModal.vue` for the two-column list layout. The header sentence **names the obligation** — "Adicione clientes ao monitoramento de DAS do Simples" — so it is impossible to add to the wrong one without reading.

Three affordances, and the reference's set is right: a per-row **green `+`** that associates one client without selecting it, a **"Selecionar todos"** toggle, and a footer **"+ Adicionar selecionados"** enabled only when something is selected. Adding one client should not require selecting it first, and a bulk action should not be the only way to do it.

- [ ] **Step 2: Scope the list, honestly**

Three rules, each of which the naive version gets wrong:

**Only company clients are offered.** The integration acts for legal entities only, so a natural person is not a candidate — consistent with the portfolio count excluding them, and with the run leaving them out entirely. Listing them and then refusing on submit would be a worse experience than not listing them.

**Already-associated clients are excluded**, and the header states how many remain. Re-adding is a no-op, and showing an already-associated client in a picker labelled "Adicionar" makes the list lie about what it will do.

**"Selecionar todos" means every client matching the current search**, not every client on the page, and the footer button carries the count — "Adicionar 3 selecionados". An office with two hundred clients must be able to see whether they are about to add three or two hundred.

- [ ] **Step 3: Cap the list, do not paginate it**

Load at most **100** candidates. Above that, show a hint asking the member to narrow the search. Load-more inside a modal is awkward on the pointer and worse on a phone, and the honest alternative to a bounded list is a full one — which is what the reference does and which is why it works at its scale and would not at a larger one.

- [ ] **Step 4: Gate it**

`admin` and `operador` only, on the same pre-emptive role gate as starting a run; a `user` never sees the button. This is **not** the super-admin gate of the connection screen — associating a client is an Account-level act, and conflating the two would lock offices out of their own monitoring.

- [ ] **Step 5: Handle the response honestly**

The endpoint returns `associated` and `already`. When `already` is non-zero, the toast says so — "12 clientes associados, 3 já estavam" — rather than reporting the whole batch as added. Then refresh the obligation, because the counters just changed and the page must not show a count that the modal has already invalidated.

- [ ] **Step 6: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. Open the modal on an obligation, add one client by the row `+` and three by selection, and confirm the counters change.

- [ ] **Step 7: Commit**

```bash
cd frontend && git add app/components/monitoring/AssociateClientsModal.vue app/components/monitoring/ObligationCounters.vue app/composables/useSerpro.ts
git commit -m "feat(monitoring): associate clients to an obligation"
```

---

### Task 7: Terms and runs

**Files:**
- Create: `frontend/app/pages/monitoring/termos.vue`
- Create: `frontend/app/pages/monitoring/execucoes.vue`
- Create: `frontend/app/pages/monitoring/execucoes/[id].vue`

- [ ] **Step 1: The terms page**

One card showing **the office's term** — state, expiry, and an explicit line stating the signature is the office's. Do not display the stored document. **Do not offer an upload of a signed document**: D6 and `tasks.md` 4.8 have the platform build, sign and submit the term itself, and 4.11 says explicitly not to ask the office to sign when the term is valid.

With no term, the page states that the office's certificate is the missing piece.

- [ ] **Step 2: The runs list**

`useAsyncData` over `syncRuns()` with the ladder of Task 4. State through a presentation map, the four counts, the timestamp.

- [ ] **Step 3: The run detail**

Items per client in the **five** item states — `sincronizado`, `ignorado` with its reason, `falhou`, `indeterminado`, `nao_processado`. `nao_processado` is distinct from `ignorado`: the first has not happened yet, the second happened and was skipped. The obligation vocabulary does not apply here, and the backend spec now says so.

The re-sync action is **hidden for `user`** by a pre-emptive gate. A `403` is a `warning` only when the integration is not enabled for the Account; a 403 from a missing role must not reach a member who was never offered the button.

- [ ] **Step 4: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. Navigate to `/monitoring/termos`, `/monitoring/execucoes` and a run id; each resolves, none 404s into the catch-all.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add app/pages/monitoring/termos.vue app/pages/monitoring/execucoes.vue app/pages/monitoring/execucoes/\[id\].vue
git commit -m "feat(monitoring): terms and sync run screens"
```

---

### Task 8: The connection screen

**Files:**
- Create: `frontend/app/pages/admin/serpro.vue`

- [ ] **Step 1: The credential form**

`import * as z from 'zod'` (the namespace import every zod file uses), `type Schema = z.output<typeof schema>`, `reactive<Partial<Schema>>`, `<UForm id="serpro-connection" :schema :state @submit>`. Copy the save cycle from `ClientCadastroModal.vue:88-110`: a local `submitting` ref, `try`/`catch`/`finally`, a success toast, an error toast with `apiMessage(err)`.

The secret is **write-only**: the form is never hydrated with a stored value, and `saveConnection` sends the field only when typed. After a successful save the form is replaced by the metadata card, because the API never returns the secret.

- [ ] **Step 2: Metadata, connectivity, enablement**

Non-secret metadata — certificate subject, serial, validity window, configured contracting document — and a `configured` flag. A "Testar conectividade" button reporting the result and, on an invalid credential, naming the missing or rejected element. The per-Account enablement control stays with the Account admin and is **not** on this screen.

- [ ] **Step 3: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. Confirm a non-super-admin cannot reach the page; the parent route's middleware handles it — do not add a redundant in-page guard.

- [ ] **Step 4: Commit**

```bash
cd frontend && git add app/pages/admin/serpro.vue
git commit -m "feat(admin): platform serpro connection screen"
```

---

### Task 9: Reading a message is a legal act

**Files:**
- Create: `frontend/app/components/monitoring/MessageDetail.vue`
- Modify: `frontend/app/components/monitoring/MonitoringSheet.vue`

This task is separate because it is the one place where the read-only framing of this change stops being true.

- [ ] **Step 1: Make reading a distinct action**

`CAIXAPOSTAL/MSGDETALHAMENTO62` says, in the provider's own words, that executing it *"caracteriza ciência da intimação, nos termos do art. 23, § 2º, inciso III, do Decreto nº 70.235/1972"*. Opening a contributor's message starts a legal deadline.

So the message **list** is free of consequence and the message **detail** is not. They must be separate interactions: a click on a row opens a confirmation that names what is about to happen, and only the confirmation calls `readMessage`. Never render the body on row click.

- [ ] **Step 2: Record and show the consequence**

The response carries `ciencia_em` and `prazo_limite`. Once a message has been read, the row shows that it was and when, and the deadline it started — an office that has missed one needs to see that it missed one.

- [ ] **Step 3: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS, with a test asserting the body is not fetched before the confirmation.

- [ ] **Step 4: Commit**

```bash
cd frontend && git add app/components/monitoring/MessageDetail.vue app/components/monitoring/MonitoringSheet.vue
git commit -m "feat(monitoring): consent before reading a legal notice"
```

---

### Task 10: Verification

- [ ] **Step 1: Run everything**

```bash
cd frontend && pnpm lint && pnpm typecheck && node --test tests/
```
Expected: PASS, the pre-existing 23 test files still green.

- [ ] **Step 2: Confirm nothing fictitious survives**

```bash
cd frontend && grep -rn "monitoringCompanies\|monitoringStatusFor\|statusCycle\|monitoringAttentionCount" app/ tests/
```
Expected: no matches.

- [ ] **Step 3: Confirm the inert and unserved states**

`pnpm dev`, then open `/monitoring`, `/monitoring/simples-nacional`, `/monitoring/caixas-postais/det` and `/monitoring/declaracoes/dirf`. The first three show empty states, and **DIRF shows the obsolete state, not an empty list** — that distinction is the whole point of D21 and `tasks.md` 10.5.

- [ ] **Step 4: Commit**

Nothing, unless a fix was needed above.

---

## Self-Review

**Spec coverage.** All nine requirements in `specs/monitoring/spec.md` are covered. Clientes derivados (Task 1, 4 — fake data gone, portfolio from the API, natural persons excluded). Obrigação classificada (Task 1, 5 — the four categories, and the no-counter branch). Contadores (Task 2, 5 — four summing to the total, `encerrado` outside, zero displayed, progress as a separate axis). Situação e causa (Task 2, 5 — nine labels, causes from the backend, `processando` per obligation). Dado desatualizado (Task 1, 6 — a separate attribute). Listagem (Task 5 — server-filtered, route-carried, 404 answered). Estados (Task 5 — the ladder plus the unserved state). Colunas por obrigação (Task 1, 5). Painel coerente (Task 4, 5 — and Task 6, whose association changes the counters and must refresh them). Guia (Task 2 — `slipStatusFor`, most recent transmission wins, no extra provider call).

**What this plan deliberately does not build.** No backend. No mock layer. **No removal or deactivation of an associated client** — the reference's modal only adds, and deactivating a tracked client changes what the next run does, which is a decision worth taking on its own rather than smuggling into a picker. No page for the fourteen provider systems the reference has no counterpart for — `DTE`, `PAGTOWEB` beyond the existing tab, `CCMEI`, `MIT`, `SICALC`, `EPROCESSO`, `PNRCONTADOR`, `EVENTOSATUALIZACAO` — each of which is a real obligation family the catalogue serves and which the D11 first-sync set excludes. That is a scope decision for a later plan, not an omission here.

**Type consistency.** The obligation table is transcribed once, in Task 1, and every later task reads it. `MonitoringCounter`, `MonitoringStatus`, `ObligationCategory` and `AttentionReasonCode` are declared once in Task 1 and consumed by Tasks 2, 5, 6 and 8. The obligation slug is the registry key throughout, and the contract fixes it for the backend.

**The risks this plan carries.** The backend contract does not exist, so a shape change breaks the frontend at integration time rather than at test time — the contract section is the single place to reconcile it. Three obligations are permanently in a non-populated state, which is honest but will look unfinished to an office until the wording is reviewed by someone who can defend it. And the catalogue is a moving document with eight known self-contradictions (D22), so the mapping carries a revision date and a test rather than being trusted as settled.
