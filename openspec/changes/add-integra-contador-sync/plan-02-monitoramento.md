# Monitoring Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fictitious monitoring module with a server-driven one — remove the ten hand-written companies and the arithmetic status derivation, read the counters, the per-client situation and the named cause behind each aggregate from the API, classify each of the nineteen obligations by what the SERPRO catalogue actually serves, and add the connection, terms, runs and mailbox-message screens.

**Architecture:** `monitoringNav.ts` keeps its role as a route registry and becomes the single declaration of each obligation — its slug, its navigation position, its columns, its service and its catalogue category. A new `app/types/serpro.ts` and `app/composables/useSerpro.ts` carry the wire contract, following `useWork`/`useClients`. A new `app/utils/monitoringPresentation.ts` owns every label, colour and format, so no template writes a situation name inline. `MonitoringSheet.vue` becomes a server-filtered, paginated list under a four-state ladder. Pages own their state through `useAsyncData`, as everywhere else in this codebase.

**Tech Stack:** Nuxt 4.5, Vue 3, `@nuxt/ui` ^4.11.1, Tailwind 4, zod ^4.6.5, `node --test` (no test framework).

**Spec:** `openspec/changes/add-integra-contador-sync/specs/monitoring/spec.md` (9 requirements), `specs/serpro-connection/spec.md`, `design.md` D5, D6, D8, D14, D15, D19, D20, D21, D22, and `tasks.md` groups 8, 9 and 10.

**Worktree:** `/home/obsidian/dev/opmoni/.worktrees/monitoring-server-driven`, branch `monitoring/server-driven`. Every path below is relative to that worktree's root. All `cd frontend` commands run from there.

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

## File Structure

Files created by this plan, and the single responsibility each one owns:

| File | Responsibility |
|---|---|
| `frontend/app/types/serpro.ts` | The wire contract. Unions and shapes only — no behaviour, no labels. |
| `frontend/app/composables/useSerpro.ts` | One `async function` per endpoint, `queryOf` params, envelope unwrapping. |
| `frontend/app/utils/monitoringPresentation.ts` | Every label, colour, icon and format the monitoring screens use. The only place a vocabulary value becomes Portuguese. |
| `frontend/app/components/monitoring/ObligationCounters.vue` | The counter row: five readings plus `Encerrado`, each a navigation target, and the "Adicionar clientes" entry point. |
| `frontend/app/components/monitoring/AssociateClientsModal.vue` | The candidate picker and the three ways to associate. |
| `frontend/app/components/monitoring/MessageDetail.vue` | The consent step and the single call to `readMessage`. |
| `frontend/app/pages/monitoring/termos.vue` | The office's authorization term. |
| `frontend/app/pages/monitoring/execucoes.vue` | The sync-run list. |
| `frontend/app/pages/monitoring/execucoes/[id].vue` | One run's per-client items and the re-sync action. |
| `frontend/app/pages/admin/serpro.vue` | The platform credential, its metadata and the connectivity test. |

Files modified:

| File | Change |
|---|---|
| `frontend/app/utils/monitoringNav.ts` | Becomes the nineteen-obligation registry: columns, service, category, catalogue revision. Keeps only the route helpers. |
| `frontend/app/utils/adminNav.ts` | Gains the `Serpro` entry. |
| `frontend/app/layouts/default.vue` | Renders the two integration links after the groups. No structural change. |
| `frontend/app/pages/monitoring.vue` | The parent panel: group title and the sub-tab bar. Carries the selected situation from one obligation to the next. |
| `frontend/app/pages/monitoring/index.vue` | Reads the overview from the API. |
| `frontend/app/pages/monitoring/[...slug].vue` | Passes the registry entry, not `page`/`status`. |
| `frontend/app/components/monitoring/MonitoringSheet.vue` | Server-filtered, paginated, four-state ladder, category branch. |

---

## The nineteen obligations

This table is the contract. It is derived from the provider's own catalogue, and it is **configuration, not code** (D22), carrying the catalogue revision it was read from. A test asserts all nineteen still resolve.

| Slug | Obligation | Service | Procuração | Category | Columns beyond `name` + `situacao` |
|---|---|---|---|---|---|
| `simples-nacional` | Simples Nacional | `REGIMEAPURACAO/CONSULTAROPCAOREGIME103` | `00060` | `direct` | `regime_escolhido`, `data_da_opcao`, `due_on` |
| `mei` | MEI | `PGMEI/DIVIDAATIVA24` | — | `direct` | `divida_ativa` |
| `dctfweb` | DCTFWeb | `DCTFWEB/CONSXMLDECLARACAO38` | `00103` | `direct` | `gi_declaracao`, `receitas`, `due_on` |
| `fgts-digital` | FGTS Digital | `DCTFWEB/CONSXMLDECLARACAO38` | `00103` | `derived` | `valor_apurado_1718` |
| `parcelamentos/simples-nacional` | Parcelamentos › Simples Nacional | `PARCSN` | `00076`+`00188` | `direct` | — |
| `parcelamentos/pgfn` | Parcelamentos › PGFN | — | — | `unavailable` | — |
| `parcelamentos/receita-federal` | Parcelamentos › Receita Federal | `PERTSN` + `RELPSN` | `00149`+`10011`, `00210`+`10036` | `derived` | — |
| `parcelamentos/especiais` | Parcelamentos › Especiais | `PARCSN-ESP` | `00125` | `direct` | — |
| `situacao-fiscal/relatorio-fiscal` | Situação Fiscal › Relatório Fiscal | `SITFIS/RELATORIOSITFIS92` | `00002` | `direct` | — |
| `situacao-fiscal/certidoes` | Situação Fiscal › Certidões | `SITFIS/RELATORIOSITFIS92` | `00002` | `derived` | `certidao`, `emissao`, `validade` |
| `situacao-fiscal/comprovantes` | Situação Fiscal › Comprovantes | `PAGTOWEB/PAGAMENTOS71` | `00004` | `direct` | — |
| `caixas-postais/e-cac` | Caixas Postais › e-CAC | `CAIXAPOSTAL/MSGCONTRIBUINTE61` | `00006` | `direct` | `nao_lidas`, `ultima` |
| `caixas-postais/fgts-digital` | Caixas Postais › FGTS Digital | `CAIXAPOSTAL`, filtro de assunto | `00006` | `derived` | — |
| `caixas-postais/det` | Caixas Postais › DET | `CAIXAPOSTAL`, filtro de assunto | `00006` | `derived` | — |
| `declaracoes/pgdas` | Declarações › PGDAS | `PGDASD/CONSDECLARACAO13` | `00146` | `direct` | `gi_declaracao`, `guia_emitida`, `guia_paga`, `due_on` |
| `declaracoes/dctfweb` | Declarações › DCTFWeb | `DCTFWEB/CONSXMLDECLARACAO38` | `00103` | `direct` | — |
| `declaracoes/fgts` | Declarações › FGTS | — | — | `unavailable` | — |
| `declaracoes/defis` | Declarações › DEFIS | `DEFIS/CONSDECLARACAO142` | `00146` | `direct` | — |
| `declaracoes/dirf` | Declarações › DIRF | — | — | `extinct` | — |

Sixteen have a source, two do not, one is obsolete. The three that are not `direct` exist for reasons worth carrying in a comment beside them, because they are the ones an implementer will otherwise "fix":

- **`00146` is shared by `PGDASD` and `DEFIS`.** One client's grant covers both obligations, so the office setup instructions must not present them as two grants (D5).
- **`PGFN` has no service.** All eight parcelamento systems say the debts are Simples Nacional ones under collection at the RFB; federal parcelamento runs on Receita's own channels. A "Receita Federal" tab is served by `PERTSN` and `RELPSN`, which are Simples Nacional debts under federal programmes — so the name is a misnomer and the registry comment says so.
- **`DIRF` is not waiting for data.** `IN RFB 2.043/2021` replaced it with EFD-Reinf and eSocial, and `IN RFB 2.181/2024` moved that to facts from 1 January 2025. Neither successor is exposed.

**An obligation that is `unavailable` or `extinct` presents no counter and no client row.** It says which of the two it is and why. That is a different state from "no clients", and the spec forbids presenting it as a client's pending.

`due_on` is declared as a column, not a global one, because only the obligations with a deadline have one. A column set is a list of values the source actually returns, which is what the spec's "Colunas descrevem o que a fonte entrega" demands.

---

## The contract

```
GET /api/serpro/monitoring/overview
→ { data: { portfolio_total: 42, attention: { "simples-nacional": 3, … } } }

GET /api/serpro/monitoring/obligations/{obligation}
    ?situacao=&q=&tag_id[]=&page=
→ { data: { obligation, category, total, em_dia, processando, pendencias,
            atencao, encerrado, current_page,
            attention_reasons: [ { code, count, label? } ] },
    data_rows: [ MonitoringClient ] }

GET /api/serpro/monitoring/obligations/{obligation}/messages/{id}
→ { data: { codigo, assunto, corpo, lida_em, ciencia_em, prazo_limite } }

POST /api/serpro/monitoring/obligations/{obligation}/clients
     { client_ids: number[] }
→ { data: { associated: number, already: number } }
```

`encerrado` sits outside the four, and `total` is the sum of the four. The counters are zero-filled, never omitted. `attention_reasons` carries a **code** and a count; `label` is the backend's own wording and is used **only** as the fallback for a code this client cannot resolve. The client resolves label and colour from the code, so a new cause renders without a redeploy, and an unknown code still renders as something an operator can read.

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

### Task 1: The wire contract and the composable

**Files:**
- Create: `frontend/app/types/serpro.ts`
- Create: `frontend/app/composables/useSerpro.ts`

**Interfaces:**
- Consumes: `queryOf` from `app/composables/useApiQuery.ts`; `$api` from `app/plugins/api.ts`; `FormData` upload shape from `useClients().uploadCertificate` (`app/composables/useClients.ts:128-133`).
- Produces: `ObligationCategory`, `MonitoringCounter`, `MonitoringSituacao`, `AttentionReasonCode`, `MonitoringSlipStatus`, `SerproSyncRunState`, `SerproRunItemState`, `SerproAuthorizationTermState`, `AttentionReason`, `MonitoringClient`, `MonitoringMessageStub`, `MonitoringMessage`, `MonitoringAssessmentPeriod`, `MonitoringObligationSummary`, `MonitoringOverview`, `SerproConnectionMetadata`, `SerproConnectionPayload`, `SerproConnectivityResult`, `SerproAuthorizationTerm`, `SerproSyncRun`, `SerproSyncRunItem`, `SerproSyncRunDetail`, `SerproAssociateResult`, `ObligationListParams`, `useSerpro()`.

This task is purely additive: nothing imports either file yet, so it lands without touching a working screen. Its whole gate is `pnpm typecheck`, because it contains no runtime behaviour to assert.

- [ ] **Step 1: Create `app/types/serpro.ts`**

```ts
/**
 * Wire contract for the Integra Contador (SERPRO) integration.
 *
 * Field names are the backend's `snake_case` verbatim. `interface` describes a
 * shape, `type` a union — there is no barrel for domain types, so consumers
 * import this file by module path.
 */

/** What the provider's catalogue actually serves for an obligation (D21). */
export type ObligationCategory = 'direct' | 'derived' | 'unavailable' | 'extinct'

/** The four states that partition the total. `encerrado` sits outside them. */
export type MonitoringCounter = 'em_dia' | 'processando' | 'pendencias' | 'atencao'

/** The five states a row can be in. */
export type MonitoringSituacao = MonitoringCounter | 'encerrado'

/**
 * The causes behind an `atencao` aggregate, declared as their own union so the
 * compiler rejects a cause used where a counter belongs. A cause is never a
 * free-standing state: it refines `atencao` on the row.
 */
export type AttentionReasonCode
  = | 'sem_declaracao'
    | 'sem_procuracao'
    | 'procuracao_invalida'
    | 'contam_debitos'

/** Collection-slip status, derived from synchronized data with no extra call. */
export type MonitoringSlipStatus = 'paid' | 'issued' | 'owed' | 'none'

export type SerproSyncRunState = 'queued' | 'running' | 'completed' | 'partial' | 'failed'

/**
 * `nao_processado` has not happened yet; `ignorado` happened and was skipped.
 * The obligation vocabulary does not apply here.
 */
export type SerproRunItemState
  = | 'sincronizado'
    | 'ignorado'
    | 'falhou'
    | 'indeterminado'
    | 'nao_processado'

export type SerproAuthorizationTermState
  = | 'ausente'
    | 'pendente'
    | 'validado'
    | 'autenticado'
    | 'vencido'
    | 'recusado'

export interface AttentionReason {
  /**
   * The backend's code. Typed as the union widened with `string` on purpose:
   * a cause added server-side must reach this client without a redeploy, and
   * the compiler must not reject the unknown code at the boundary.
   */
  code: AttentionReasonCode | string
  count: number
  /** The backend's own wording — the fallback when this client cannot resolve `code`. */
  label?: string | null
}

export interface MonitoringMessageStub {
  id: number
  assunto: string
  received_at: string
  lida_em: string | null
  /** The moment the office came to know. Starts the legal deadline (D19). */
  ciencia_em: string | null
  prazo_limite: string | null
  unread: boolean
}

export interface MonitoringClient {
  client_id: number
  name: string
  tax_id: string | null
  situacao: MonitoringSituacao
  cause: AttentionReasonCode | null
  due_on: string | null
  /** The power of attorney lapsed: the retained data is out of date. */
  stale: boolean
  power_of_attorney_expires_on: string | null
  /** Values for the obligation's declared `columns`, keyed by column id. */
  fields: Record<string, string | number | null>
  /** Caixas Postais rows are messages; reading one is a legal act (D19). */
  message: MonitoringMessageStub | null
}

export interface MonitoringMessage {
  id: number
  codigo: string | null
  assunto: string
  corpo: string
  lida_em: string | null
  ciencia_em: string | null
  prazo_limite: string | null
}

export interface MonitoringAssessmentPeriod {
  /** `YYYY-MM`. */
  period: string
  /** When the declaration was transmitted; `null` when the period owes. */
  declared_at: string | null
  rectified: boolean
  slip_number: string | null
  slip_issued_at: string | null
  due_on: string | null
  slip_paid: boolean | null
}

export interface MonitoringObligationSummary {
  obligation: string
  category: ObligationCategory
  /** The sum of the four. `encerrado` is not part of it. */
  total: number
  em_dia: number
  processando: number
  pendencias: number
  atencao: number
  /** Outside the partition: a closed obligation never inflates an action state. */
  encerrado: number
  current_page: number
  attention_reasons: AttentionReason[]
}

export interface MonitoringOverview {
  portfolio_total: number
  attention: Record<string, number>
}

export interface SerproConnectionMetadata {
  configured: boolean
  consumer_key_hint: string | null
  contracting_document: string | null
  certificate_subject: string | null
  certificate_serial: string | null
  certificate_not_before: string | null
  certificate_not_after: string | null
  updated_at: string | null
}

export interface SerproConnectionPayload {
  consumer_key: string
  consumer_secret?: string
  certificate?: File
  password?: string
}

export interface SerproConnectivityResult {
  ok: boolean
  /** `configuracao`, `certificado`, `credencial`, `provedor` — what to name. */
  failed_element: string | null
  message: string | null
  checked_at: string
}

export interface SerproAuthorizationTerm {
  state: SerproAuthorizationTermState
  expires_on: string | null
  signed_at: string | null
  /** The signed document is kept verbatim and is never rendered. */
  document_present: boolean
}

export interface SerproSyncRun {
  id: number
  state: SerproSyncRunState
  total: number
  synchronized: number
  skipped: number
  failed: number
  started_at: string | null
  finished_at: string | null
}

export interface SerproSyncRunItem {
  client_id: number
  name: string
  tax_id: string | null
  state: SerproRunItemState
  /** Present when `state` is `ignorado` or `falhou`. */
  reason: string | null
  /** The obligation slug this item covers. */
  obligation: string | null
  updated_at: string | null
}

export interface SerproSyncRunDetail extends SerproSyncRun {
  items: SerproSyncRunItem[]
}

export interface SerproAssociateResult {
  associated: number
  already: number
}
```

- [ ] **Step 2: Create `app/composables/useSerpro.ts`**

```ts
import type {
  MonitoringClient,
  MonitoringMessage,
  MonitoringObligationSummary,
  MonitoringOverview,
  MonitoringSituacao,
  SerproAssociateResult,
  SerproAuthorizationTerm,
  SerproConnectivityResult,
  SerproConnectionMetadata,
  SerproConnectionPayload,
  SerproSyncRun,
  SerproSyncRunDetail
} from '~/types/serpro'
import { queryOf } from './useApiQuery'

export interface ObligationListParams {
  situacao?: MonitoringSituacao | ''
  q?: string
  tag_id?: number[]
  page?: number
}

export function useSerpro() {
  const { $api } = useNuxtApp()

  async function overview() {
    const res = await $api<{ data: MonitoringOverview }>('/serpro/monitoring/overview')
    return res.data
  }

  /**
   * The whole envelope, not `res.data` alone. The page renders the counters and
   * `attention_reasons` from the summary and the rows from `data_rows`; a page
   * that issued two calls to render one list could disagree with itself.
   */
  async function listObligation(obligation: string, params: ObligationListParams = {}) {
    return $api<{ data: MonitoringObligationSummary, data_rows: MonitoringClient[] }>(
      `/serpro/monitoring/obligations/${obligation}`,
      { query: queryOf(params) }
    )
  }

  /**
   * Separate from listing rows, and load-bearing. `MSGDETALHAMENTO62` says that
   * executing it characterizes ciência da intimação (D19), so the body is
   * fetched only after the member consents — never as a side effect of drawing
   * a row. Keeping one function that did both would make the legal act
   * unreachable to gate.
   */
  async function readMessage(obligation: string, id: number) {
    const res = await $api<{ data: MonitoringMessage }>(
      `/serpro/monitoring/obligations/${obligation}/messages/${id}`
    )
    return res.data
  }

  async function associateClients(obligation: string, clientIds: number[]) {
    const res = await $api<{ data: SerproAssociateResult }>(
      `/serpro/monitoring/obligations/${obligation}/clients`,
      { method: 'POST', body: { client_ids: clientIds } }
    )
    return res.data
  }

  async function connection() {
    const res = await $api<{ data: SerproConnectionMetadata }>('/serpro/connection')
    return res.data
  }

  /**
   * `FormData`, like `useClients().uploadCertificate`, because the certificate
   * is a file. The three optional fields are appended only when present, which
   * is what makes an omitted `consumer_secret` mean "keep the stored one"
   * instead of "store an empty string".
   */
  async function saveConnection(payload: SerproConnectionPayload) {
    const body = new FormData()
    body.append('consumer_key', payload.consumer_key)
    if (payload.consumer_secret) body.append('consumer_secret', payload.consumer_secret)
    if (payload.certificate) body.append('certificate', payload.certificate)
    if (payload.password) body.append('password', payload.password)
    const res = await $api<{ data: SerproConnectionMetadata }>('/serpro/connection', { method: 'PUT', body })
    return res.data
  }

  async function testConnectivity() {
    const res = await $api<{ data: SerproConnectivityResult }>('/serpro/connectivity', { method: 'POST' })
    return res.data
  }

  async function authorizationTerm() {
    const res = await $api<{ data: SerproAuthorizationTerm }>('/serpro/authorization-terms')
    return res.data
  }

  async function syncRuns(params: { page?: number } = {}) {
    return $api<{ data: SerproSyncRun[], meta?: { total: number, current_page?: number, last_page?: number } }>(
      '/serpro/sync-runs',
      { query: queryOf(params) }
    )
  }

  async function showSyncRun(id: number) {
    const res = await $api<{ data: SerproSyncRunDetail }>(`/serpro/sync-runs/${id}`)
    return res.data
  }

  async function resyncRun(id: number) {
    const res = await $api<{ data: SerproSyncRun }>(`/serpro/sync-runs/${id}/resync`, { method: 'POST' })
    return res.data
  }

  return {
    overview,
    listObligation,
    readMessage,
    associateClients,
    connection,
    saveConnection,
    testConnectivity,
    authorizationTerm,
    syncRuns,
    showSyncRun,
    resyncRun
  }
}
```

- [ ] **Step 3: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. Nothing imports these files yet, so the only possible failure is a typo in the declarations themselves.

- [ ] **Step 4: Commit**

```bash
cd frontend && git add app/types/serpro.ts app/composables/useSerpro.ts
git commit -m "feat(serpro): monitoring wire contract and api composable"
```

---

### Task 2: Presentation

**Files:**
- Create: `frontend/app/utils/monitoringPresentation.ts`
- Test: `frontend/tests/monitoringStatus.test.ts`
- Test: `frontend/tests/monitoringFormat.test.ts`

**Interfaces:**
- Consumes: the unions from `app/types/serpro.ts`.
- Produces: `monitoringCounterPresentation`, `monitoringSituacaoPresentation`, `monitoringAttentionReasonPresentation(code, label?)`, `monitoringCategoryPresentation`, `monitoringSlipStatusPresentation`, `monitoringStalePresentation`, `monitoringTotalLabel`, `serproRunStatePresentation`, `serproRunItemStatePresentation`, `serproTermStatePresentation`, `monitoringCountersTotal(summary)`, `formatMonitoringCount(value)`, `formatMonitoringDate(value)`, `formatMonitoringDueOn(value)`, `slipStatusFor(periods, period)`.

`serproRunStatePresentation` and `serproRunItemStatePresentation` are declared here rather than in Task 7 because Task 7 is a page, and a page that inlines a run-state colour is exactly what the global constraint forbids.

- [ ] **Step 1: Write the failing tests**

```ts
// tests/monitoringStatus.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  monitoringAttentionReasonPresentation,
  monitoringCategoryPresentation,
  monitoringCounterPresentation,
  monitoringCountersTotal,
  monitoringSituacaoPresentation,
  serproRunItemStatePresentation,
  serproRunStatePresentation,
  serproTermStatePresentation
} from '../app/utils/monitoringPresentation.ts'
import type { AttentionReasonCode, MonitoringCounter, MonitoringSituacao, ObligationCategory, SerproRunItemState, SerproSyncRunState } from '../app/types/serpro.ts'

const counters: MonitoringCounter[] = ['em_dia', 'processando', 'pendencias', 'atencao']
const situacoes: MonitoringSituacao[] = ['em_dia', 'processando', 'pendencias', 'atencao', 'encerrado']
const categories: ObligationCategory[] = ['direct', 'derived', 'unavailable', 'extinct']
const runStates: SerproSyncRunState[] = ['queued', 'running', 'completed', 'partial', 'failed']
const itemStates: SerproRunItemState[] = ['sincronizado', 'ignorado', 'falhou', 'indeterminado', 'nao_processado']
const causes: AttentionReasonCode[] = ['sem_declaracao', 'sem_procuracao', 'procuracao_invalida', 'contam_debitos']

describe('monitoring counters', () => {
  it('labels, colours and icons every counter of the partition', () => {
    for (const counter of counters) {
      const entry = monitoringCounterPresentation[counter]
      assert.ok(entry.label, `${counter} has no label`)
      assert.ok(entry.color, `${counter} has no colour`)
      assert.ok(entry.icon, `${counter} has no icon`)
    }
  })

  it('keeps encerrado out of the four counters', () => {
    assert.equal((monitoringCounterPresentation as Record<string, unknown>).encerrado, undefined)
  })

  it('sums to the total', () => {
    assert.equal(monitoringCountersTotal({
      obligation: 'simples-nacional',
      category: 'direct',
      total: 12,
      em_dia: 8,
      processando: 1,
      pendencias: 1,
      atencao: 2,
      encerrado: 3,
      current_page: 1,
      attention_reasons: []
    }), 12)
  })
})

describe('monitoring situations', () => {
  it('labels, colours and icons every row state', () => {
    for (const situacao of situacoes) {
      const entry = monitoringSituacaoPresentation[situacao]
      assert.ok(entry.label, `${situacao} has no label`)
      assert.ok(entry.color, `${situacao} has no colour`)
      assert.ok(entry.icon, `${situacao} has no icon`)
    }
  })

  it('presents a closed obligation as neutral, outside the partition', () => {
    assert.equal(monitoringSituacaoPresentation.encerrado.color, 'neutral')
  })
})

describe('monitoring attention reasons', () => {
  it('resolves each cause code to a label and a colour', () => {
    for (const code of causes) {
      const entry = monitoringAttentionReasonPresentation(code)
      assert.ok(entry.label, `${code} has no label`)
      assert.ok(entry.color, `${code} has no colour`)
    }
  })

  it('resolves an unknown code from the backend label rather than going blank', () => {
    const entry = monitoringAttentionReasonPresentation('causa_nova', 'Procuração suspensa')
    assert.equal(entry.label, 'Procuração suspensa')
    assert.equal(entry.color, 'warning')
  })

  it('falls back to the code itself when no wording arrives', () => {
    const entry = monitoringAttentionReasonPresentation('causa_nova')
    assert.equal(entry.label, 'causa_nova')
    assert.equal(entry.color, 'warning')
  })
})

describe('obligation categories', () => {
  it('labels and explains every category', () => {
    for (const category of categories) {
      const entry = monitoringCategoryPresentation[category]
      assert.ok(entry.label, `${category} has no label`)
      assert.ok(entry.description.length > 20, `${category} has no explanation`)
    }
  })

  it('says an unserved obligation is not a client pending', () => {
    assert.match(monitoringCategoryPresentation.unavailable.description, /obrigação/i)
    assert.match(monitoringCategoryPresentation.extinct.description, /deixou de ser devida/i)
  })
})

describe('run and term vocabulary', () => {
  it('labels every run state and item state', () => {
    for (const state of runStates) assert.ok(serproRunStatePresentation[state].label)
    for (const state of itemStates) assert.ok(serproRunItemStatePresentation[state].label)
  })

  it('separates not-processed from skipped', () => {
    assert.notEqual(serproRunItemStatePresentation.nao_processado.label, serproRunItemStatePresentation.ignorado.label)
  })

  it('labels every term state', () => {
    for (const state of ['ausente', 'pendente', 'validado', 'autenticado', 'vencido', 'recusado'] as const) {
      assert.ok(serproTermStatePresentation[state].label)
    }
  })
})
```

```ts
// tests/monitoringFormat.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  formatMonitoringCount,
  formatMonitoringDate,
  formatMonitoringDueOn,
  slipStatusFor
} from '../app/utils/monitoringPresentation.ts'
import type { MonitoringAssessmentPeriod } from '../app/types/serpro.ts'

describe('formatMonitoringCount', () => {
  it('renders zero rather than hiding it', () => {
    assert.equal(formatMonitoringCount(0), '0')
  })

  it('groups thousands in pt-BR', () => {
    assert.equal(formatMonitoringCount(1234), '1.234')
  })
})

describe('formatMonitoringDate', () => {
  it('renders a date as dd/mm/yyyy', () => {
    assert.equal(formatMonitoringDate('2026-04-20T00:00:00Z'), '20/04/2026')
  })

  it('renders an absent value as an em dash', () => {
    assert.equal(formatMonitoringDate(null), '—')
    assert.equal(formatMonitoringDueOn(null), '—')
  })
})

function period(overrides: Partial<MonitoringAssessmentPeriod>): MonitoringAssessmentPeriod {
  return {
    period: '2026-03',
    declared_at: null,
    rectified: false,
    slip_number: null,
    slip_issued_at: null,
    due_on: '2026-04-20',
    slip_paid: null,
    ...overrides
  }
}

describe('slipStatusFor', () => {
  it('reads an issued and paid slip with both dates', () => {
    const result = slipStatusFor([
      period({ declared_at: '2026-03-31T10:00:00Z', slip_number: '0815', slip_issued_at: '2026-04-01T09:00:00Z', slip_paid: true })
    ], '2026-03')
    assert.equal(result.status, 'paid')
    assert.equal(result.slip_number, '0815')
    assert.equal(result.issued_on, '2026-04-01T09:00:00Z')
  })

  it('reads an issued and unpaid slip', () => {
    const result = slipStatusFor([
      period({ declared_at: '2026-03-31T10:00:00Z', slip_number: '0815', slip_paid: false })
    ], '2026-03')
    assert.equal(result.status, 'issued')
  })

  it('reads a declared period with no slip as owing', () => {
    const result = slipStatusFor([period({ declared_at: '2026-03-31T10:00:00Z' })], '2026-03')
    assert.equal(result.status, 'owed')
  })

  it('presents the rectified transmission, not the original', () => {
    const result = slipStatusFor([
      period({ declared_at: '2026-03-31T10:00:00Z', slip_number: '0815', slip_paid: true }),
      period({ declared_at: '2026-04-10T10:00:00Z', rectified: true, slip_number: '0821', slip_paid: false })
    ], '2026-03')
    assert.equal(result.status, 'issued')
    assert.equal(result.slip_number, '0821')
  })

  it('reports no slip for a period the data does not mention', () => {
    assert.equal(slipStatusFor([period({ period: '2026-02' })], '2026-03').status, 'none')
  })
})
```

- [ ] **Step 2: Run them to see them fail**

Run: `cd frontend && node --test tests/monitoringStatus.test.ts`
Expected: FAIL with `ERR_MODULE_NOT_FOUND` for `app/utils/monitoringPresentation.ts`.

- [ ] **Step 3: Implement `app/utils/monitoringPresentation.ts`**

```ts
import type {
  AttentionReasonCode,
  MonitoringAssessmentPeriod,
  MonitoringCounter,
  MonitoringObligationSummary,
  MonitoringSituacao,
  MonitoringSlipStatus,
  ObligationCategory,
  SerproAuthorizationTermState,
  SerproRunItemState,
  SerproSyncRunState
} from '~/types/serpro'

type Tone = 'neutral' | 'info' | 'success' | 'warning' | 'error'

/** The counter row's own label — the fifth reading, which is not a state. */
export const monitoringTotalLabel = 'Total'

/**
 * Staleness is an attribute of the synchronized data, never a situation. The
 * spec is explicit: a client whose power of attorney lapsed keeps its retained
 * data, and that data is labelled out of date while its situation stands.
 */
export const monitoringStalePresentation: { label: string, color: Tone, icon: string } = {
  label: 'Dado desatualizado',
  color: 'warning',
  icon: 'i-lucide-history'
}

export const monitoringCounterPresentation: Record<MonitoringCounter, { label: string, color: Tone, icon: string }> = {
  em_dia: { label: 'Em dia', color: 'success', icon: 'i-lucide-circle-check' },
  processando: { label: 'Processando', color: 'info', icon: 'i-lucide-arrow-repeat' },
  pendencias: { label: 'Pendências', color: 'warning', icon: 'i-lucide-clock' },
  atencao: { label: 'Atenção', color: 'error', icon: 'i-lucide-circle-alert' }
}

export const monitoringSituacaoPresentation: Record<MonitoringSituacao, { label: string, color: Tone, icon: string }> = {
  ...monitoringCounterPresentation,
  encerrado: { label: 'Encerrado', color: 'neutral', icon: 'i-lucide-lock' }
}

const attentionReasonPresentation: Record<AttentionReasonCode, { label: string, color: Tone, icon: string }> = {
  sem_declaracao: { label: 'Sem declaração', color: 'warning', icon: 'i-lucide-file-x' },
  sem_procuracao: { label: 'Sem procuração', color: 'error', icon: 'i-lucide-shield-off' },
  procuracao_invalida: { label: 'Procuração inválida', color: 'error', icon: 'i-lucide-shield-alert' },
  contam_debitos: { label: 'Contam débitos', color: 'warning', icon: 'i-lucide-file-warning' }
}

/**
 * Resolves by code, so a cause added server-side renders without a redeploy.
 * An unrecognised code falls back to the backend's own wording and then to the
 * code itself — never to a neutral blank, because a cause the operator cannot
 * read is worse than one that is missing.
 */
export function monitoringAttentionReasonPresentation(code: string, label?: string | null) {
  return attentionReasonPresentation[code as AttentionReasonCode]
    ?? { label: label || code, color: 'warning' as Tone, icon: 'i-lucide-circle-help' }
}

export const monitoringCategoryPresentation: Record<ObligationCategory, { label: string, description: string, color: Tone, icon: string }> = {
  direct: {
    label: 'Leitura direta',
    description: 'O Integra Contador publica um serviço que devolve estes dados estruturados.',
    color: 'success',
    icon: 'i-lucide-plug'
  },
  derived: {
    label: 'Leitura derivada',
    description: 'Estes dados saem de outro serviço do provedor, ou de um filtro sobre o assunto de uma mensagem. Não é uma fonte independente.',
    color: 'info',
    icon: 'i-lucide-layers'
  },
  unavailable: {
    label: 'Não servido pelo provedor',
    description: 'O catálogo do Integra Contador não publica serviço para esta obrigação. A ausência é da obrigação, não de um cliente: nenhum cliente aparece como pendente por causa dela.',
    color: 'warning',
    icon: 'i-lucide-unplug'
  },
  extinct: {
    label: 'Obrigação extinta',
    description: 'Esta obrigação deixou de ser devida. A IN RFB 2.043/2021 a substituiu por EFD-Reinf e eSocial, e a IN RFB 2.181/2024 adiou isso a 1º/1/2025. Nenhum dos sistemas sucessores é exposto pela integração.',
    color: 'neutral',
    icon: 'i-lucide-ban'
  }
}

export const monitoringSlipStatusPresentation: Record<MonitoringSlipStatus, { label: string, color: Tone, icon: string }> = {
  paid: { label: 'Guia emitida e paga', color: 'success', icon: 'i-lucide-circle-check' },
  issued: { label: 'Guia emitida, não paga', color: 'warning', icon: 'i-lucide-receipt' },
  owed: { label: 'Período em aberto', color: 'warning', icon: 'i-lucide-clock' },
  none: { label: 'Sem guia', color: 'neutral', icon: 'i-lucide-circle-minus' }
}

export const serproRunStatePresentation: Record<SerproSyncRunState, { label: string, color: Tone, icon: string }> = {
  queued: { label: 'Na fila', color: 'neutral', icon: 'i-lucide-clock' },
  running: { label: 'Em execução', color: 'info', icon: 'i-lucide-arrow-repeat' },
  completed: { label: 'Concluída', color: 'success', icon: 'i-lucide-circle-check' },
  partial: { label: 'Parcial', color: 'warning', icon: 'i-lucide-triangle-alert' },
  failed: { label: 'Falhou', color: 'error', icon: 'i-lucide-circle-alert' }
}

export const serproRunItemStatePresentation: Record<SerproRunItemState, { label: string, color: Tone, icon: string }> = {
  sincronizado: { label: 'Sincronizado', color: 'success', icon: 'i-lucide-circle-check' },
  ignorado: { label: 'Ignorado', color: 'neutral', icon: 'i-lucide-minus' },
  falhou: { label: 'Falhou', color: 'error', icon: 'i-lucide-circle-alert' },
  indeterminado: { label: 'Indeterminado', color: 'warning', icon: 'i-lucide-circle-help' },
  nao_processado: { label: 'Não processado', color: 'neutral', icon: 'i-lucide-minus-circle' }
}

export const serproTermStatePresentation: Record<SerproAuthorizationTermState, { label: string, color: Tone, icon: string }> = {
  ausente: { label: 'Termo não emitido', color: 'warning', icon: 'i-lucide-file-x' },
  pendente: { label: 'Em validação', color: 'info', icon: 'i-lucide-clock' },
  validado: { label: 'Validado', color: 'success', icon: 'i-lucide-circle-check' },
  autenticado: { label: 'Autenticado', color: 'success', icon: 'i-lucide-shield-check' },
  vencido: { label: 'Vencido', color: 'error', icon: 'i-lucide-circle-alert' },
  recusado: { label: 'Recusado', color: 'error', icon: 'i-lucide-ban' }
}

/** The total is the sum of the four; `encerrado` is deliberately not in it. */
export function monitoringCountersTotal(summary: Pick<MonitoringObligationSummary, 'em_dia' | 'processando' | 'pendencias' | 'atencao'>) {
  return summary.em_dia + summary.processando + summary.pendencias + summary.atencao
}

export function formatMonitoringCount(value: number) {
  return new Intl.NumberFormat('pt-BR').format(value)
}

export function formatMonitoringDate(value: string | null | undefined) {
  if (!value) return '—'
  const [date] = value.split('T')
  const [year, month, day] = (date ?? '').split('-')
  if (!year || !month || !day) return '—'
  return `${day}/${month}/${year}`
}

export function formatMonitoringDueOn(value: string | null | undefined) {
  return formatMonitoringDate(value)
}

export type MonitoringSlip = {
  status: MonitoringSlipStatus
  slip_number: string | null
  issued_on: string | null
  due_on: string | null
}

const NO_SLIP: MonitoringSlip = { status: 'none', slip_number: null, issued_on: null, due_on: null }

/**
 * The most recent transmission wins: a rectified declaration for a period that
 * already had an original is the one that is current, and the earlier one must
 * not be shown as such. Derived from synchronized data — no provider call.
 */
export function slipStatusFor(periods: readonly MonitoringAssessmentPeriod[], period: string): MonitoringSlip {
  const candidates = periods.filter(item => item.period === period)
  if (!candidates.length) return NO_SLIP

  const latest = candidates.reduce<MonitoringAssessmentPeriod | null>((chosen, item) => {
    if (!chosen) return item
    if (!item.declared_at) return chosen
    if (!chosen.declared_at) return item
    return item.declared_at > chosen.declared_at ? item : chosen
  }, null)

  if (!latest) return NO_SLIP
  if (!latest.declared_at || !latest.slip_number) {
    return { status: latest.declared_at ? 'owed' : 'none', slip_number: null, issued_on: null, due_on: latest.due_on }
  }
  return {
    status: latest.slip_paid ? 'paid' : 'issued',
    slip_number: latest.slip_number,
    issued_on: latest.slip_issued_at,
    due_on: latest.due_on
  }
}
```

- [ ] **Step 4: Run them to see them pass**

Run: `cd frontend && node --test tests/monitoringStatus.test.ts tests/monitoringFormat.test.ts`
Expected: PASS, 6 test blocks green.

- [ ] **Step 5: Verify nothing else broke, then commit**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. The new utils are imported by nothing yet.

```bash
cd frontend && git add app/utils/monitoringPresentation.ts tests/monitoringStatus.test.ts tests/monitoringFormat.test.ts
git commit -m "feat(monitoring): presentation for counters, situations and categories"
```

---

### Task 3: The obligation registry, the navigation, and the fictitious data

**Files:**
- Modify: `frontend/app/utils/monitoringNav.ts` (full rewrite)
- Modify: `frontend/app/layouts/default.vue`
- Modify: `frontend/app/utils/adminNav.ts`
- Test: `frontend/tests/monitoringRoutes.test.ts`

**Interfaces:**
- Consumes: `MonitoringSituacao`, `ObligationCategory` from `app/types/serpro.ts`.
- Produces: `MonitoringColumn`, `MonitoringObligation`, `MonitoringGroup`, `monitoringCatalogueRevision`, `monitoringGroups` (eight groups, nineteen obligations), `monitoringObligations` (flat, nineteen), `monitoringIntegrationLinks`, `monitoringListPath(obligation, situacao?)`, `parseMonitoringSlug(slug)` → `{ obligation, situacao } | null`, `monitoringSidebarChildren(path)`, and a `Serpro` entry in `adminPages`.

The old registry and the new one cannot coexist: the old `MonitoringStatus` was four invented states, the new one is a server-owned five, and `MonitoringSheet`/`index.vue`/`[...slug].vue` break the moment either appears alone. So the surgery is one task, and the route test is its gate.

- [ ] **Step 1: Write the failing test**

```ts
// tests/monitoringRoutes.test.ts
import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  monitoringGroups,
  monitoringIntegrationLinks,
  monitoringListPath,
  monitoringObligations,
  parseMonitoringSlug
} from '../app/utils/monitoringNav.ts'

describe('monitoring routes', () => {
  it('resolves an obligation slug', () => {
    const listing = parseMonitoringSlug(['simples-nacional'])
    assert.equal(listing?.obligation.slug, 'simples-nacional')
    assert.equal(listing?.situacao, null)
  })

  it('resolves a two-segment obligation slug', () => {
    const listing = parseMonitoringSlug(['parcelamentos', 'pgfn'])
    assert.equal(listing?.obligation.slug, 'parcelamentos/pgfn')
  })

  it('resolves a situation segment and returns it', () => {
    const listing = parseMonitoringSlug(['caixas-postais', 'det', 'atencao'])
    assert.equal(listing?.obligation.slug, 'caixas-postais/det')
    assert.equal(listing?.situacao, 'atencao')
  })

  it('returns null for an unknown obligation', () => {
    assert.equal(parseMonitoringSlug(['nao-existe']), null)
  })

  it('treats an unknown trailing segment as part of the body, not a situation', () => {
    assert.equal(parseMonitoringSlug(['simples-nacional', 'nao-existe']), null)
  })

  it('returns null for a situation with no obligation', () => {
    assert.equal(parseMonitoringSlug(['atencao']), null)
  })

  it('does not claim the integration screens, which resolve as static routes', () => {
    for (const link of monitoringIntegrationLinks) {
      const slug = link.to.replace('/monitoring/', '').split('/')
      assert.equal(parseMonitoringSlug(slug), null, `${link.to} must resolve ahead of the catch-all`)
    }
  })
})

describe('the obligation registry', () => {
  it('resolves all nineteen obligations', () => {
    assert.equal(monitoringObligations.length, 19)
  })

  it('has no duplicate slug', () => {
    const slugs = monitoringObligations.map(item => item.slug)
    assert.equal(new Set(slugs).size, slugs.length)
  })

  it('declares a catalogue source for every served obligation', () => {
    for (const item of monitoringObligations) {
      if (item.category === 'direct' || item.category === 'derived') {
        assert.ok(item.service, `${item.slug} is ${item.category} with no service`)
      } else {
        assert.equal(item.service, null, `${item.slug} is ${item.category} with a service`)
        assert.deepEqual(item.columns, [], `${item.slug} is ${item.category} and must declare no column`)
      }
    }
  })

  it('names what a derived obligation projects over', () => {
    for (const item of monitoringObligations) {
      if (item.category === 'derived') assert.ok(item.derivedFrom, `${item.slug} does not name its source`)
    }
  })

  it('groups into eight top-level items, four with sub-tabs', () => {
    assert.equal(monitoringGroups.length, 8)
    assert.equal(monitoringGroups.filter(group => group.pages.length > 1).length, 4)
  })

  it('builds a list path carrying the situation', () => {
    const simples = monitoringObligations.find(item => item.slug === 'simples-nacional')
    assert.ok(simples)
    assert.equal(monitoringListPath(simples), '/monitoring/simples-nacional')
    assert.equal(monitoringListPath(simples, 'atencao'), '/monitoring/simples-nacional/atencao')
  })
})
```

- [ ] **Step 2: Run it to see it fail**

Run: `cd frontend && node --test tests/monitoringRoutes.test.ts`
Expected: FAIL — `monitoringObligations` and `monitoringIntegrationLinks` are not exported yet.

- [ ] **Step 3: Rewrite `app/utils/monitoringNav.ts`**

```ts
import type { NavigationMenuItem } from '@nuxt/ui'
import type { MonitoringSituacao, ObligationCategory } from '~/types/serpro'

export interface MonitoringColumn {
  id: string
  header: string
  /** Right-aligned, for counts and amounts. */
  numeric?: boolean
}

export interface MonitoringObligation {
  /** The path under `/monitoring`, and the key the backend is addressed by. */
  slug: string
  label: string
  icon: string
  /** Only the columns this obligation's source actually returns (D21). */
  columns: readonly MonitoringColumn[]
  /** `null` when the catalogue publishes no service for it. */
  service: string | null
  procuracao: string | null
  category: ObligationCategory
  /** What a `derived` obligation projects over, named for the office. */
  derivedFrom?: string
}

export interface MonitoringGroup {
  label: string
  icon: string
  description: string
  pages: readonly MonitoringObligation[]
}

/**
 * The published catalogue revision this mapping was read from (D22). The
 * catalogue is a moving document that contradicts itself, so the mapping
 * records its shelf life instead of being trusted as settled.
 */
export const monitoringCatalogueRevision = '2026-09'

const NAME: MonitoringColumn = { id: 'name', header: 'Cliente' }
const SITUACAO: MonitoringColumn = { id: 'situacao', header: 'Situação' }
const DUE_ON: MonitoringColumn = { id: 'due_on', header: 'Vencimento' }

/** Every served obligation carries the client's name and the situation. */
const served = (...specific: MonitoringColumn[]) => [NAME, ...specific, SITUACAO] as const
/** An obligation the provider does not serve presents no column at all. */
const unserved: readonly MonitoringColumn[] = []

export const monitoringGroups: readonly MonitoringGroup[] = [
  {
    label: 'Simples Nacional',
    icon: 'i-lucide-store',
    description: 'Apuração do regime e data da opção.',
    pages: [
      {
        slug: 'simples-nacional',
        label: 'Simples Nacional',
        icon: 'i-lucide-store',
        columns: served(
          { id: 'regime_escolhido', header: 'Regime escolhido' },
          { id: 'data_da_opcao', header: 'Data da opção' },
          DUE_ON
        ),
        service: 'REGIMEAPURACAO/CONSULTAROPCAOREGIME103',
        procuracao: '00060',
        category: 'direct'
      }
    ]
  },
  {
    label: 'MEI',
    icon: 'i-lucide-store',
    description: 'Dívida ativa do microempreendedor.',
    pages: [
      {
        slug: 'mei',
        label: 'MEI',
        icon: 'i-lucide-store',
        columns: served({ id: 'divida_ativa', header: 'Dívida ativa', numeric: true }),
        service: 'PGMEI/DIVIDAATIVA24',
        procuracao: null,
        category: 'direct'
      }
    ]
  },
  {
    label: 'DCTFWeb',
    icon: 'i-lucide-file-spreadsheet',
    description: 'Entregas da DCTFWeb.',
    pages: [
      {
        slug: 'dctfweb',
        label: 'DCTFWeb',
        icon: 'i-lucide-file-chart-column',
        columns: served(
          { id: 'gi_declaracao', header: 'GI_Declaração' },
          { id: 'receitas', header: 'Receitas', numeric: true },
          DUE_ON
        ),
        service: 'DCTFWEB/CONSXMLDECLARACAO38',
        procuracao: '00103',
        category: 'direct'
      }
    ]
  },
  {
    label: 'FGTS Digital',
    icon: 'i-lucide-landmark',
    description: 'Valores apurados no FGTS Digital.',
    pages: [
      {
        // PGDAS-D publishes a closed tax-code table with no FGTS, and FGTS due
        // from a Simples optant is not collected in the DAS. The only
        // structured FGTS in the catalogue is inside the DCTFWeb declaration.
        slug: 'fgts-digital',
        label: 'FGTS Digital',
        icon: 'i-lucide-landmark',
        columns: served({ id: 'valor_apurado_1718', header: 'Valor apurado (1718)', numeric: true }),
        service: 'DCTFWEB/CONSXMLDECLARACAO38',
        procuracao: '00103',
        category: 'derived',
        derivedFrom: 'o valor 1718 da declaração DCTFWeb'
      }
    ]
  },
  {
    label: 'Parcelamentos',
    icon: 'i-lucide-calendar-clock',
    description: 'Parcelas em aberto por programa.',
    pages: [
      {
        slug: 'parcelamentos/simples-nacional',
        label: 'Simples Nacional',
        icon: 'i-lucide-store',
        columns: served(),
        service: 'PARCSN',
        procuracao: '00076+00188',
        category: 'direct'
      },
      {
        // All eight parcelamento systems say the debts are Simples Nacional
        // ones under collection at the RFB: federal parcelamento runs on
        // Receita's own channels, and the catalogue publishes nothing for it.
        slug: 'parcelamentos/pgfn',
        label: 'PGFN',
        icon: 'i-lucide-scale',
        columns: unserved,
        service: null,
        procuracao: null,
        category: 'unavailable'
      },
      {
        // "Receita Federal" is a misnomer: PERTSN and RELPSN are Simples
        // Nacional debts under federal programmes. The name is kept because it
        // is the office's word for the tab, and the comment keeps it honest.
        slug: 'parcelamentos/receita-federal',
        label: 'Receita Federal',
        icon: 'i-lucide-building-2',
        columns: served(),
        service: 'PERTSN+RELPSN',
        procuracao: '00149+10011, 00210+10036',
        category: 'derived',
        derivedFrom: 'os sistemas PERTSN e RELPSN'
      },
      {
        slug: 'parcelamentos/especiais',
        label: 'Especiais',
        icon: 'i-lucide-folder-lock',
        columns: served(),
        service: 'PARCSN-ESP',
        procuracao: '00125',
        category: 'direct'
      }
    ]
  },
  {
    label: 'Situação Fiscal',
    icon: 'i-lucide-shield-check',
    description: 'Relatório, certidões e comprovantes.',
    pages: [
      {
        slug: 'situacao-fiscal/relatorio-fiscal',
        label: 'Relatório Fiscal',
        icon: 'i-lucide-file-text',
        columns: served(),
        service: 'SITFIS/RELATORIOSITFIS92',
        procuracao: '00002',
        category: 'direct'
      },
      {
        // Not a separate source: a projection of the same SITFIS PDF, titled
        // "informações de apoio para emissão de certidão".
        slug: 'situacao-fiscal/certidoes',
        label: 'Certidões',
        icon: 'i-lucide-badge-check',
        columns: served(
          { id: 'certidao', header: 'Certidão' },
          { id: 'emissao', header: 'Emissão' },
          { id: 'validade', header: 'Validade' }
        ),
        service: 'SITFIS/RELATORIOSITFIS92',
        procuracao: '00002',
        category: 'derived',
        derivedFrom: 'o relatório SITFIS, que já traz o número negativo, a emissão e a validade'
      },
      {
        slug: 'situacao-fiscal/comprovantes',
        label: 'Comprovantes',
        icon: 'i-lucide-receipt',
        columns: served(),
        service: 'PAGTOWEB/PAGAMENTOS71',
        procuracao: '00004',
        category: 'direct'
      }
    ]
  },
  {
    label: 'Caixas Postais',
    icon: 'i-lucide-mailbox',
    description: 'Mensagens por caixa.',
    pages: [
      {
        slug: 'caixas-postais/e-cac',
        label: 'e-CAC',
        icon: 'i-lucide-landmark',
        columns: served(
          { id: 'nao_lidas', header: 'Não lidas', numeric: true },
          { id: 'ultima', header: 'Última mensagem' }
        ),
        service: 'CAIXAPOSTAL/MSGCONTRIBUINTE61',
        procuracao: '00006',
        category: 'direct'
      },
      {
        // A subject filter over CAIXAPOSTAL, not a service of its own.
        slug: 'caixas-postais/fgts-digital',
        label: 'FGTS Digital',
        icon: 'i-lucide-wallet',
        columns: served(),
        service: 'CAIXAPOSTAL',
        procuracao: '00006',
        category: 'derived',
        derivedFrom: 'um filtro por assunto sobre a caixa postal e-CAC'
      },
      {
        slug: 'caixas-postais/det',
        label: 'DET',
        icon: 'i-lucide-inbox',
        columns: served(),
        service: 'CAIXAPOSTAL',
        procuracao: '00006',
        category: 'derived',
        derivedFrom: 'um filtro por assunto sobre a caixa postal e-CAC'
      }
    ]
  },
  {
    label: 'Declarações',
    icon: 'i-lucide-files',
    description: 'Obrigações acessórias da carteira.',
    pages: [
      {
        // `00146` is shared by PGDASD and DEFIS: one client's grant covers
        // both, so the office setup must not present them as two grants (D5).
        slug: 'declaracoes/pgdas',
        label: 'PGDAS',
        icon: 'i-lucide-file-spreadsheet',
        columns: served(
          { id: 'gi_declaracao', header: 'GI_Declaração' },
          { id: 'guia_emitida', header: 'Guia emitida' },
          { id: 'guia_paga', header: 'Guia paga' },
          DUE_ON
        ),
        service: 'PGDASD/CONSDECLARACAO13',
        procuracao: '00146',
        category: 'direct'
      },
      {
        slug: 'declaracoes/dctfweb',
        label: 'DCTFWeb',
        icon: 'i-lucide-file-chart-column',
        columns: served(),
        service: 'DCTFWEB/CONSXMLDECLARACAO38',
        procuracao: '00103',
        category: 'direct'
      },
      {
        slug: 'declaracoes/fgts',
        label: 'FGTS',
        icon: 'i-lucide-wallet',
        columns: unserved,
        service: null,
        procuracao: null,
        category: 'unavailable'
      },
      {
        // Shares `00146` with PGDAS-D — see the comment there.
        slug: 'declaracoes/defis',
        label: 'DEFIS',
        icon: 'i-lucide-file-text',
        columns: served(),
        service: 'DEFIS/CONSDECLARACAO142',
        procuracao: '00146',
        category: 'direct'
      },
      {
        // Not waiting for data. IN RFB 2.043/2021 replaced DIRF with EFD-Reinf
        // and eSocial; IN RFB 2.181/2024 made that a fact from 1 January 2025.
        // Neither successor is exposed by the integration.
        slug: 'declaracoes/dirf',
        label: 'DIRF',
        icon: 'i-lucide-files',
        columns: unserved,
        service: null,
        procuracao: null,
        category: 'extinct'
      }
    ]
  }
]

export const monitoringObligations: readonly MonitoringObligation[] = monitoringGroups.flatMap(group => group.pages)

/** Static siblings, resolved by Nuxt ahead of the `[...slug].vue` catch-all. */
export const monitoringIntegrationLinks = [
  { label: 'Termo de autorização', icon: 'i-lucide-file-signature', to: '/monitoring/termos' },
  { label: 'Execuções de sincronização', icon: 'i-lucide-refresh-cw', to: '/monitoring/execucoes' }
] as const

const situacaoSlug: Record<MonitoringSituacao, string> = {
  em_dia: 'em-dia',
  processando: 'processando',
  pendencias: 'pendencias',
  atencao: 'atencao',
  encerrado: 'encerrado'
}

const situacaoBySlug = {
  'em-dia': 'em_dia',
  processando: 'processando',
  pendencias: 'pendencias',
  atencao: 'atencao',
  encerrado: 'encerrado'
} as const satisfies Record<string, MonitoringSituacao>

export function monitoringListPath(obligation: MonitoringObligation, situacao?: MonitoringSituacao) {
  const base = `/monitoring/${obligation.slug}`
  return situacao ? `${base}/${situacaoSlug[situacao]}` : base
}

/**
 * The situation is a route segment, never local state, so a list restricted to
 * one state is a link that reproduces for whoever follows it. A trailing
 * segment the vocabulary does not know is treated as part of the obligation
 * path and therefore fails to resolve — the alternative is a mistyped situation
 * silently showing everything.
 */
export function parseMonitoringSlug(slug: unknown) {
  const source = Array.isArray(slug) ? slug : typeof slug === 'string' ? [slug] : []
  const parts = source.filter((part): part is string => typeof part === 'string' && part !== '')
  if (!parts.length) return null

  let situacao: MonitoringSituacao | null = null
  let body = parts
  const last = parts[parts.length - 1]
  if (last && last in situacaoBySlug) {
    situacao = situacaoBySlug[last as keyof typeof situacaoBySlug]
    body = parts.slice(0, -1)
  }
  if (!body.length) return null

  const obligation = monitoringObligations.find(item => item.slug === body.join('/'))
  if (!obligation) return null
  return { obligation, situacao }
}

function obligationActive(path: string, obligation: MonitoringObligation) {
  const base = monitoringListPath(obligation)
  return path === base || path.startsWith(`${base}/`)
}

export function monitoringSidebarChildren(path: string): NavigationMenuItem[] {
  const items: NavigationMenuItem[] = [{
    label: 'Painel',
    to: '/monitoring',
    exact: true,
    active: path === '/monitoring'
  }]

  for (const group of monitoringGroups) {
    const first = group.pages[0]
    if (!first) continue
    items.push({
      label: group.label,
      to: monitoringListPath(first),
      active: group.pages.some(obligation => obligationActive(path, obligation))
    })
  }

  for (const link of monitoringIntegrationLinks) {
    items.push({
      label: link.label,
      icon: link.icon,
      to: link.to,
      active: path === link.to || path.startsWith(`${link.to}/`)
    })
  }

  return items
}
```

- [ ] **Step 4: Delete the fictitious data and fix its importers**

In `app/utils/monitoringNav.ts` the rewrite above has already removed `monitoringCompanies`, `statusCycle`, `monitoringStatusFor`, `pageOrder`, `monitoringAttentionCount`, `monitoringColumns`, `monitoringStatuses`, `monitoringStatusPresentation`, the local `MonitoringStatus` and the `MonitoringCompany` interface.

Four files imported them and all four must be fixed, or nothing typechecks:

`app/pages/monitoring/index.vue` — replace its whole `<script setup>` with the Task 4 version, and drop `monitoringAttentionCount` / `monitoringCompanies` from the import. Until Task 4 lands, the interim state is:

```ts
import { monitoringGroups, monitoringListPath, monitoringObligations } from '~/utils/monitoringNav'
const firstObligation = monitoringObligations[0]
const attentionFor = () => '0'
```

`app/pages/monitoring.vue` — the parent panel, which reads `parseMonitoringSlug` for the group title and builds the sub-tab bar from `group.pages`. It keeps that structure and takes the new fields: `listing.obligation` instead of `listing.page`, and the tab target becomes `monitoringListPath(page, listing.situacao ?? undefined)`. That second half is not cosmetic — it is the spec's "Situação preservada entre obrigações": moving from one obligation to another while a situation is selected must keep the situation in the resulting route. `pageTabs` stays `null` for a group with a single obligation.

`app/pages/monitoring/[...slug].vue` — pass the registry entry and the nullable situation:

```vue
<template>
  <MonitoringSheet
    v-if="listing"
    :obligation="listing.obligation"
    :situacao="listing.situacao"
  />
</template>
```

`app/components/monitoring/MonitoringSheet.vue` — the props change from `page`/`status` to `obligation`/`situacao`, and the columns come from `obligation.columns` instead of `monitoringColumns[page.family]`. The three local filters are removed in Task 5; until then, rewrite the `<script setup>` so it compiles against the new registry, keeping the existing template structure working against the new props, with the row list coming from a local `const rows: MonitoringClient[] = []`.

- [ ] **Step 5: Add the two integration links and the admin entry**

`app/utils/adminNav.ts` — one line in `adminPages`:

```ts
  { label: 'Serpro', icon: 'i-lucide-plug', to: '/admin/serpro' },
```

It inherits the `super-admin` middleware from `app/pages/admin.vue`, which is what makes the platform credential platform-only. Do not add a guard inside the page.

`app/layouts/default.vue` — no change is needed: line 148 already maps `monitoringSidebarChildren(route.path)`, which now appends the two integration links on its own. Delete nothing; verify line 147-148 still reads:

```ts
        defaultOpen: route.path.startsWith('/monitoring'),
        children: monitoringSidebarChildren(route.path).map(child => ({
```

- [ ] **Step 6: Run the test to see it pass**

Run: `cd frontend && node --test tests/monitoringRoutes.test.ts`
Expected: PASS, 13 tests across both `describe` blocks.

- [ ] **Step 7: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. `monitoring/index.vue` shows zeros until Task 4 — the correct inert state, not a failure.

- [ ] **Step 8: Commit**

```bash
cd frontend && git add app/utils/monitoringNav.ts app/layouts/default.vue app/utils/adminNav.ts \
  app/pages/monitoring/index.vue 'app/pages/monitoring/[...slug].vue' app/components/monitoring/MonitoringSheet.vue \
  tests/monitoringRoutes.test.ts
git commit -m "refactor(monitoring): nineteen-obligation registry, drop fictitious data"
```

---

### Task 4: The overview

**Files:**
- Modify: `frontend/app/pages/monitoring/index.vue`

**Interfaces:**
- Consumes: `useSerpro().overview()`, `monitoringGroups`, `monitoringObligations`, `monitoringIntegrationLinks`, `monitoringListPath`, `monitoringCategoryPresentation`, `formatMonitoringCount`.
- Produces: the overview page. Its `attention` map is keyed by the **full** obligation slug, group prefix included — `declaracoes/pgdas`, not `pgdas`.

- [ ] **Step 1: Replace the data source**

`app/pages/monitoring/index.vue` in full:

```vue
<script setup lang="ts">
import { apiStatus } from '~/composables/useApiError'
import type { MonitoringOverview } from '~/types/serpro'
import {
  monitoringGroups,
  monitoringIntegrationLinks,
  monitoringListPath,
  monitoringObligations,
  type MonitoringObligation
} from '~/utils/monitoringNav'
import { formatMonitoringCount } from '~/utils/monitoringPresentation'

definePageMeta({ middleware: 'auth' })

const toast = useToast()
const { overview } = useSerpro()

/**
 * `getCachedData: () => undefined` is load-bearing, not a default. Without it
 * Nuxt serves the payload cached in the SSR context, and a synchronization
 * that finished a minute ago would keep showing the counters from before it —
 * the spec scenario "Atualização após sincronização" fails.
 */
const { data, status, error, refresh } = await useAsyncData<MonitoringOverview>(
  'serpro-monitoring-overview',
  () => overview(),
  {
    default: () => ({ portfolio_total: 0, attention: {} }),
    getCachedData: () => undefined
  }
)

const isLoading = computed(() => status.value === 'pending')

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar o painel', color: 'error' })
  }
}

watch(error, (value) => {
  // A 404 means the read API is not there yet, which is the inert state, not
  // a failure worth shouting about. Anything else is.
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar o monitoramento', color: 'error' })
  }
})

/**
 * The same exemption the toast applies, applied to the template. Guarding only
 * the toast would leave the page shouting "Não foi possível carregar" over a
 * backend that simply has not shipped the endpoint yet — and `tasks.md` 10.3
 * requires these screens to sit in an empty state in that situation.
 */
const showError = computed(() => !!error.value && apiStatus(error.value) !== 404)

function attentionFor(obligation: MonitoringObligation) {
  return formatMonitoringCount(data.value.attention[obligation.slug] ?? 0)
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-8 overflow-y-auto p-4 sm:p-6">
    <UAlert
      v-if="showError"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar o monitoramento"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
    />

    <UPageSkeleton v-else-if="isLoading && !error" :rows="6" />

    <template v-else>
      <section class="flex flex-col gap-4">
        <div>
          <h2 class="text-lg font-semibold text-highlighted">
            Carteira
          </h2>
          <p class="text-sm text-muted">
            Clientes com registros sincronizados. Pessoa física não entra: a integração só age para pessoa jurídica.
          </p>
        </div>

        <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
          <MetricCard
            icon="i-lucide-building-2"
            title="Na carteira"
            :to="monitoringObligations[0] ? monitoringListPath(monitoringObligations[0]) : undefined"
            :value="formatMonitoringCount(data.portfolio_total)"
          />
        </UPageGrid>
      </section>

      <section v-for="group in monitoringGroups" :key="group.label" class="flex flex-col gap-4">
        <div class="flex items-start gap-3">
          <UIcon :name="group.icon" class="mt-0.5 size-5 text-muted" />
          <div>
            <h2 class="text-lg font-semibold text-highlighted">
              {{ group.label }}
            </h2>
            <p class="text-sm text-muted">
              {{ group.description }}
            </p>
          </div>
        </div>

        <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
          <MetricCard
            v-for="obligation in group.pages"
            :key="obligation.slug"
            :icon="obligation.icon"
            :title="obligation.label"
            :to="monitoringListPath(obligation)"
            :value="attentionFor(obligation)"
          />
        </UPageGrid>
      </section>

      <section class="flex flex-col gap-4">
        <div>
          <h2 class="text-lg font-semibold text-highlighted">
            Integração
          </h2>
          <p class="text-sm text-muted">
            Termo do escritório e histórico de sincronizações.
          </p>
        </div>

        <UPageGrid class="lg:grid-cols-4 gap-4 sm:gap-6 lg:gap-px">
          <MetricCard
            v-for="link in monitoringIntegrationLinks"
            :key="link.to"
            :icon="link.icon"
            :title="link.label"
            :to="link.to"
            value="&rarr;"
          />
        </UPageGrid>
      </section>
    </template>
  </div>
</template>
```

Every card renders a value, including zero. An obligation whose category is `unavailable` or `extinct` still appears here — it shows its own attention count, which is zero, and its own page explains the absence. What it must never do is present itself as a client's pending, and the page that explains it is Task 5's job.

- [ ] **Step 2: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. With no backend behind it the overview reads zeros, which is the correct inert state.

- [ ] **Step 3: Commit**

```bash
cd frontend && git add app/pages/monitoring/index.vue
git commit -m "feat(monitoring): overview reading counters from the API"
```

---

### Task 5: The obligation page

**Files:**
- Create: `frontend/app/components/monitoring/ObligationCounters.vue`
- Modify: `frontend/app/components/monitoring/MonitoringSheet.vue` (full rewrite)

**Interfaces:**
- Consumes: `useSerpro().listObligation`, `apiStatus` from `~/composables/useApiError`, `sheetBodyClass` / `sheetTableUi` / `sheetToolbarUi` from `~/components/data-table/sheet`, `DataTableFilterColumn` / `DataTableFilterModel` from `~/components/data-table/Filter.vue`, `monitoringListPath`, and the Task 2 presentation maps.
- Produces: `MonitoringSheet` with props `{ obligation: MonitoringObligation, situacao: MonitoringSituacao | null }` and an event `refreshed`; `ObligationCounters` with props `{ obligation, summary, situacao, canAssociate }` and an event `associate`.

- [ ] **Step 1: Delete the three local filters**

`MonitoringSheet.vue` loses the `searched` computed, the status filter inside `rows`, and `statusCount`. All three filtered the local array the fake data lived in. `agency`, `document` and `mailbox` stop being `page.label` and become reads of `row.fields` through the registry's `columns` — a column the obligation does not declare no longer exists to be filled with the page's own name.

- [ ] **Step 2: Create `app/components/monitoring/ObligationCounters.vue`**

```vue
<script setup lang="ts">
import { useAuth } from '~/composables/useAuth'
import type { MonitoringCounter, MonitoringObligationSummary, MonitoringSituacao } from '~/types/serpro'
import { monitoringListPath, type MonitoringObligation } from '~/utils/monitoringNav'
import {
  formatMonitoringCount,
  monitoringCounterPresentation,
  monitoringSituacaoPresentation,
  monitoringTotalLabel
} from '~/utils/monitoringPresentation'

defineProps<{
  obligation: MonitoringObligation
  summary: MonitoringObligationSummary
  situacao: MonitoringSituacao | null
}>()

const emit = defineEmits<{ associate: [] }>()

/** Associating is an Account-level act — admin or operador, never a plain user. */
const { canManageClients } = useAuth()

const counters: readonly MonitoringCounter[] = ['em_dia', 'processando', 'pendencias', 'atencao']
</script>

<template>
  <div class="flex flex-col gap-2">
    <div class="flex items-center justify-between gap-3">
      <UPageGrid class="grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5 flex-1">
        <UButton
          :to="monitoringListPath(obligation)"
          :color="situacao === null ? 'primary' : 'neutral'"
          :variant="situacao === null ? 'soft' : 'outline'"
          :ui="{ base: 'h-auto w-full items-center justify-between gap-2 p-3' }"
        >
          <span class="flex min-w-0 items-center gap-1.5 text-sm">
            <UIcon name="i-lucide-users" class="shrink-0" />
            <span class="truncate">{{ monitoringTotalLabel }}</span>
          </span>
          <UKbd class="shrink-0">{{ formatMonitoringCount(summary.total) }}</UKbd>
        </UButton>

        <UButton
          v-for="counter in counters"
          :key="counter"
          :to="monitoringListPath(obligation, counter)"
          :color="situacao === counter ? 'primary' : 'neutral'"
          :variant="situacao === counter ? 'soft' : 'outline'"
          :ui="{ base: 'h-auto w-full items-center justify-between gap-2 p-3' }"
        >
          <span class="flex min-w-0 items-center gap-1.5 text-sm">
            <UIcon :name="monitoringCounterPresentation[counter].icon" class="shrink-0" />
            <span class="truncate">{{ monitoringCounterPresentation[counter].label }}</span>
          </span>
          <UKbd class="shrink-0">{{ formatMonitoringCount(summary[counter]) }}</UKbd>
        </UButton>
      </UPageGrid>

      <UButton
        v-if="canManageClients"
        icon="i-lucide-user-plus"
        color="neutral"
        variant="outline"
        label="Adicionar clientes"
        class="shrink-0 self-center"
        @click="emit('associate')"
      />
    </div>

    <!-- `encerrado` is a row state, not a fifth counter: folding it in would
         let a closed obligation inflate a state that requires action. -->
    <p class="flex items-center gap-1.5 text-xs text-muted">
      <UBadge
        size="sm"
        variant="subtle"
        :color="monitoringSituacaoPresentation.encerrado.color"
        :icon="monitoringSituacaoPresentation.encerrado.icon"
        :label="`${formatMonitoringCount(summary.encerrado)} ${monitoringSituacaoPresentation.encerrado.label}`"
      />
      <span>fora dos quatro contadores acima.</span>
    </p>
  </div>
</template>
```

Each counter is a `UButton` with a `to`, not a click handler mutating a ref. That is what makes a situation shareable, and what makes "Contador e listagem concordam" true by construction rather than by discipline: the list is fetched with the situation the URL names.

- [ ] **Step 3: Rewrite `app/components/monitoring/MonitoringSheet.vue`**

```vue
<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import type { TableColumn } from '@nuxt/ui'
import type { DataTableFilterColumn, DataTableFilterModel } from '~/components/data-table/Filter.vue'
import { sheetBodyClass, sheetTableUi, sheetToolbarUi } from '~/components/data-table/sheet'
import { apiStatus } from '~/composables/useApiError'
import type { MonitoringClient, MonitoringObligationSummary, MonitoringSituacao } from '~/types/serpro'
import { monitoringListPath, type MonitoringObligation } from '~/utils/monitoringNav'
import {
  formatMonitoringDueOn,
  monitoringAttentionReasonPresentation,
  monitoringCategoryPresentation,
  monitoringSituacaoPresentation,
  monitoringStalePresentation
} from '~/utils/monitoringPresentation'
import AssociateClientsModal from '~/components/monitoring/AssociateClientsModal.vue'
import ObligationCounters from '~/components/monitoring/ObligationCounters.vue'

const props = defineProps<{
  obligation: MonitoringObligation
  situacao: MonitoringSituacao | null
}>()

const emit = defineEmits<{ refreshed: [] }>()

const toast = useToast()
const { canManageClients } = useAuth()
const { listObligation } = useSerpro()
const { listTags } = useClients()

/** A 404 must never reach the error alert: it is the inert state, not a failure. */
const failed = ref(false)
const associateOpen = ref(false)

const search = ref('')
/** The house debounce is 350 ms — never a request per keystroke. */
const debouncedSearch = refDebounced(search, 350)
const tagFilter = ref<number[]>([])
const page = ref(1)

const UNSERVED_CATEGORIES = ['unavailable', 'extinct'] as const

function emptySummary(obligation: MonitoringObligation): MonitoringObligationSummary {
  return {
    obligation: obligation.slug,
    category: obligation.category,
    total: 0,
    em_dia: 0,
    processando: 0,
    pendencias: 0,
    atencao: 0,
    encerrado: 0,
    current_page: 1,
    attention_reasons: []
  }
}

/**
 * `page` is deliberately **not** in `params`. `params` is what `useAsyncData`
 * watches, so a `page` here would mean `loadMore`'s own `page.value = nextPage`
 * re-triggers the fetch and replaces the accumulated list with the last page
 * alone — and a filter or counter navigation would then request page N of a new
 * query. The `watch(data)` handler is the single owner of `page`, so it always
 * reflects the last page actually fetched, which is 1 after every refilter.
 */
const params = computed(() => ({
  situacao: props.situacao ?? '',
  q: debouncedSearch.value.trim(),
  tag_id: tagFilter.value.length ? tagFilter.value : undefined
}))

const listKey = computed(() => `serpro-monitoring-${props.obligation.slug}-${props.situacao ?? 'todas'}`)

const { data, status, error, refresh } = await useAsyncData(listKey, async () => {
  if (UNSERVED_CATEGORIES.includes(props.obligation.category as typeof UNSERVED_CATEGORIES[number])) {
    return { data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }
  }
  try {
    return await listObligation(props.obligation.slug, params.value)
  } catch (error) {
    // The obligation slug comes from the registry and is never typed, so a 404
    // means "no data", never "wrong URL". Answering with an empty envelope is
    // honest in both worlds — the endpoint not existing yet, or nothing to show.
    if (apiStatus(error) === 404) return { data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }
    failed.value = true
    throw error
  }
}, { watch: [params], default: () => ({ data: emptySummary(props.obligation), data_rows: [] as MonitoringClient[] }) })

const summary = computed(() => data.value?.data ?? emptySummary(props.obligation))
const isLoading = computed(() => status.value === 'pending')
const isUnserved = computed(() => UNSERVED_CATEGORIES.includes(props.obligation.category as typeof UNSERVED_CATEGORIES[number]))
const category = computed(() => monitoringCategoryPresentation[props.obligation.category])

const rows = ref<MonitoringClient[]>([])
const total = ref(0)
const loadingMore = ref(false)
let generation = 0

watch(data, (value) => {
  generation += 1
  rows.value = value?.data_rows ?? []
  total.value = value?.data.total ?? 0
  page.value = value?.data.current_page ?? 1
}, { immediate: true })

async function loadMore() {
  if (loadingMore.value || status.value === 'pending') return
  if (rows.value.length >= total.value) return

  const seen = generation
  const nextPage = page.value + 1
  loadingMore.value = true
  try {
    const response = await listObligation(props.obligation.slug, { ...params.value, page: nextPage })
    // Two fast filter changes must not interleave and append a stale page.
    if (seen !== generation) return
    const known = new Set(rows.value.map(row => row.client_id))
    const fresh = (response.data_rows ?? []).filter(row => !known.has(row.client_id))
    if (!fresh.length) {
      total.value = rows.value.length
      return
    }
    rows.value = [...rows.value, ...fresh]
    page.value = nextPage
  } catch {
    toast.add({ title: 'Não foi possível carregar mais clientes', color: 'error' })
  } finally {
    loadingMore.value = false
  }
}

const canLoadMore = computed(() => rows.value.length > 0 && rows.value.length < total.value)

const { data: tagCatalog } = await useAsyncData('serpro-monitoring-tags', () => listTags())

const filterColumns = computed<DataTableFilterColumn[]>(() => [
  { id: 'q', label: 'Busca', icon: 'i-lucide-search', type: 'text' },
  {
    id: 'tag_id',
    label: 'Tags',
    icon: 'i-lucide-tags',
    type: 'multiOption',
    // `listTags()` returns the envelope, not the array — `tagCatalog.value` is
    // `{ data: ClientTag[] }`, so the `.data` is what carries the options.
    options: (tagCatalog.value?.data ?? []).map(tag => ({ label: tag.name, value: String(tag.id) }))
  }
])

const filterModels = computed<DataTableFilterModel[]>(() => {
  const models: DataTableFilterModel[] = []
  if (debouncedSearch.value.trim()) models.push({ columnId: 'q', type: 'text', operator: 'contains', values: [debouncedSearch.value.trim()] })
  if (tagFilter.value.length) models.push({ columnId: 'tag_id', type: 'multiOption', operator: 'include', values: tagFilter.value.map(String) })
  return models
})

function onFilters(models: DataTableFilterModel[]) {
  search.value = models.find(model => model.columnId === 'q')?.values[0] ?? ''
  tagFilter.value = (models.find(model => model.columnId === 'tag_id')?.values ?? []).map(Number).filter(Number.isFinite)
}

/**
 * The situation is the list's identity — it is in the route, and a member
 * follows it as a link — so it is not one of the filters a member applied to a
 * list, and counting it here would offer "Limpar filtros" on a button that
 * cannot clear anything.
 */
const hasActiveFilters = computed(() => !!debouncedSearch.value.trim() || tagFilter.value.length > 0)

function clearFilters() {
  search.value = ''
  tagFilter.value = []
}

async function onRefresh() {
  try {
    await refresh()
  } catch {
    toast.add({ title: 'Não foi possível atualizar a lista', color: 'error' })
  }
}

watch(error, (value) => {
  if (value && apiStatus(value) !== 404) {
    toast.add({ title: 'Não foi possível carregar a lista', color: 'error' })
  }
})

const columns = computed<TableColumn<MonitoringClient>[]>(() =>
  props.obligation.columns.map(column => ({
    accessorKey: column.id,
    header: column.header,
    meta: { class: column.numeric ? 'text-right tabular-nums' : '' }
  }))
)

const detailFields = computed(() => props.obligation.columns.filter(column => column.id !== 'name' && column.id !== 'situacao'))

function fieldValue(row: MonitoringClient, id: string) {
  if (id === 'name') return row.name
  if (id === 'situacao') return monitoringSituacaoPresentation[row.situacao].label
  if (id === 'due_on') return formatMonitoringDueOn(row.due_on)
  const value = row.fields[id]
  return value == null || value === '' ? '—' : String(value)
}

/** The row's situation, refined by its named cause when it is `atencao`. */
function situacaoLabel(row: MonitoringClient) {
  if (row.situacao !== 'atencao' || !row.cause) return monitoringSituacaoPresentation[row.situacao].label
  const reason = summary.value.attention_reasons.find(item => item.code === row.cause)
  return monitoringAttentionReasonPresentation(row.cause, reason?.label).label
}

function situacaoIcon(row: MonitoringClient) {
  if (row.situacao !== 'atencao' || !row.cause) return monitoringSituacaoPresentation[row.situacao].icon
  const reason = summary.value.attention_reasons.find(item => item.code === row.cause)
  return monitoringAttentionReasonPresentation(row.cause, reason?.label).icon
}

async function afterAssociate() {
  associateOpen.value = false
  await onRefresh()
  emit('refreshed')
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <UDashboardToolbar class="hidden min-w-0 md:flex" :ui="sheetToolbarUi">
      <template #left>
        <div class="min-w-0 flex-1">
          <ObligationCounters
            v-if="!isUnserved"
            :obligation="obligation"
            :summary="summary"
            :situacao="situacao"
            @associate="associateOpen = true"
          />
        </div>
      </template>
    </UDashboardToolbar>

    <div :class="sheetBodyClass">
      <template v-if="isUnserved">
        <UAlert
          :color="category.color"
          variant="subtle"
          :icon="category.icon"
          :title="category.label"
          :description="category.description"
        />
      </template>

      <template v-else>
        <div class="md:hidden">
          <ObligationCounters
            :obligation="obligation"
            :summary="summary"
            :situacao="situacao"
            @associate="associateOpen = true"
          />
        </div>

        <UAlert
          v-if="error && failed"
          color="error"
          variant="subtle"
          icon="i-lucide-circle-alert"
          title="Não foi possível carregar esta obrigação"
          description="Verifique sua conexão e tente novamente."
          :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => onRefresh() }]"
        />

        <USkeleton v-else-if="isLoading && rows.length === 0" class="h-64 w-full" />

        <template v-else>
          <DataTableFilter
            :columns="filterColumns"
            :model-value="filterModels"
            :disabled="isLoading"
            class="min-w-0"
            @update:model-value="onFilters"
          >
            <UInput
              v-model="search"
              icon="i-lucide-search"
              placeholder="Buscar por nome ou CNPJ"
              class="min-w-0 flex-1"
              :disabled="isLoading"
            />
          </DataTableFilter>

          <UEmpty
            v-if="rows.length === 0 && !hasActiveFilters"
            icon="i-lucide-inbox"
            title="Nenhum cliente nesta obrigação"
            description="Nenhum cliente da carteira tem registro sincronizado para esta obrigação."
            variant="naked"
            :actions="canManageClients
              ? [{ label: 'Adicionar clientes', icon: 'i-lucide-user-plus', onClick: () => { associateOpen = true } }]
              : []"
          />

          <UEmpty
            v-else-if="rows.length === 0"
            icon="i-lucide-search-x"
            title="Nenhum resultado com estes filtros"
            description="Ajuste a busca ou limpe os filtros aplicados."
            variant="naked"
            :actions="[{ label: 'Limpar filtros', color: 'neutral', variant: 'outline', onClick: clearFilters }]"
          />

          <template v-else>
            <div class="flex min-h-0 flex-1 flex-col gap-2.5 overflow-y-auto md:hidden">
              <UCard v-for="row in rows" :key="row.client_id" :ui="{ body: 'p-3 sm:p-4' }">
                <div class="flex items-start justify-between gap-3">
                  <DataTableIdentity :title="row.name" :meta="row.tax_id" />
                  <div class="flex shrink-0 flex-col items-end gap-1">
                    <UBadge
                      :color="monitoringSituacaoPresentation[row.situacao].color"
                      :icon="situacaoIcon(row)"
                      variant="subtle"
                      :label="situacaoLabel(row)"
                    />
                    <UBadge
                      v-if="row.stale"
                      size="sm"
                      variant="subtle"
                      :color="monitoringStalePresentation.color"
                      :icon="monitoringStalePresentation.icon"
                      :label="monitoringStalePresentation.label"
                    />
                  </div>
                </div>
                <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2">
                  <div v-for="field in detailFields" :key="field.id" class="min-w-0">
                    <dt class="text-xs text-muted">
                      {{ field.header }}
                    </dt>
                    <dd class="truncate text-sm text-default tabular-nums">
                      {{ fieldValue(row, field.id) }}
                    </dd>
                  </div>
                </dl>
              </UCard>
            </div>

            <div class="hidden min-h-0 min-w-0 flex-1 flex-col md:flex">
              <UTable
                sticky
                :data="rows"
                :columns="columns"
                class="h-full min-h-0 w-full flex-1"
                :ui="sheetTableUi"
              >
                <template #name-cell="{ row }">
                  <DataTableIdentity :title="row.original.name" :meta="row.original.tax_id" />
                </template>

                <template #situacao-cell="{ row }">
                  <div class="flex flex-wrap items-center gap-1.5">
                    <UBadge
                      class="max-w-full"
                      :color="monitoringSituacaoPresentation[row.original.situacao].color"
                      :icon="situacaoIcon(row.original)"
                      variant="subtle"
                      :label="situacaoLabel(row.original)"
                      :ui="{ base: 'max-w-full', label: 'truncate' }"
                    />
                    <UBadge
                      v-if="row.original.stale"
                      size="sm"
                      variant="subtle"
                      :color="monitoringStalePresentation.color"
                      :icon="monitoringStalePresentation.icon"
                      :label="monitoringStalePresentation.label"
                    />
                  </div>
                </template>
              </UTable>
            </div>

            <div v-if="canLoadMore" class="flex justify-center">
              <UButton
                label="Carregar mais"
                color="neutral"
                variant="outline"
                icon="i-lucide-chevrons-down"
                :loading="loadingMore"
                @click="loadMore"
              />
            </div>
          </template>
        </template>
      </template>
    </div>

    <AssociateClientsModal
      v-if="canManageClients"
      v-model:open="associateOpen"
      :obligation="obligation"
      :associated-ids="rows.map(row => row.client_id)"
      @associated="afterAssociate"
    />
  </div>
</template>
```

Four things in that component are load-bearing and easy to lose in review:

- The `isUnserved` branch comes **before** the error alert, skeleton and empty states. An obligation the provider does not serve must not be able to reach the "Nenhum cliente nesta obrigação" empty — that is the spec's "Obrigação não servida" scenario, and the difference between it and "no clients" is the entire point of D21.
- `apiStatus(error) === 404` is answered with an empty envelope rather than rethrown, and `failed` gates the error alert. Together they satisfy "Falha ao carregar" and "Lista vazia sem filtros" without one impersonating the other.
- `situacaoLabel` resolves the row's cause through the backend's code, so a cause the client has never seen renders with the backend's own wording instead of a bare "Atenção".
- `row.stale` is a separate badge, never folded into `situacao` — the spec forbids expressing staleness as a further situation.

`app/pages/monitoring/[...slug].vue` needs no change beyond the template already given in Task 3: it already passes `obligation` and `situacao` and throws a 404 for an unresolvable slug.

- [ ] **Step 4: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS.

Run: `cd frontend && node --test tests/`
Expected: PASS — the 23 pre-existing files plus the three new ones stay green. `AssociateClientsModal` does not exist yet, so create it as a stub first:

```vue
<!-- app/components/monitoring/AssociateClientsModal.vue -->
<script setup lang="ts">
import type { MonitoringObligation } from '~/utils/monitoringNav'

defineProps<{ open: boolean, obligation: MonitoringObligation, associatedIds: number[] }>()
const emit = defineEmits<{ 'update:open': [value: boolean], associated: [] }>()
void emit
</script>

<template>
  <div />
</template>
```

Task 6 replaces it.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add app/components/monitoring/MonitoringSheet.vue app/components/monitoring/ObligationCounters.vue app/components/monitoring/AssociateClientsModal.vue
git commit -m "feat(monitoring): obligation counters, server filtering and category branch"
```

---

### Task 6: Associating clients to an obligation

**Files:**
- Rewrite: `frontend/app/components/monitoring/AssociateClientsModal.vue`

**Interfaces:**
- Consumes: `useSerpro().associateClients`, `useClients().list`, `clientSheetTaxIdLabel` from `~/utils/portfolioLabels.ts`, `useAuth().canManageClients`.
- Produces: the modal, emitting `associated` once the counters behind it are stale.

**Candidates come from `useClients().list`, not from a new endpoint.** The contract publishes no candidate list, and inventing one would be a second source of truth for "who is in this office". The existing `/clients` list is already Account-scoped, already filtered server-side, and already the place the office recognises. `person_type === 'company'` is applied to the response rather than as a query parameter, because the existing list does not declare that filter.

The cap is 100 candidates. Above that the modal asks for a narrower search instead of paginating: a load-more inside a modal is awkward on the pointer and worse on a phone, and the honest alternative to a bounded list is a full one.

- [ ] **Step 1: Build the shell and the three affordances**

```vue
<script setup lang="ts">
import { refDebounced } from '@vueuse/core'
import type { ClientSheet } from '~/types/client'
import type { MonitoringObligation } from '~/utils/monitoringNav'
import { clientSheetTaxIdLabel } from '~/utils/portfolioLabels'

const props = defineProps<{
  open: boolean
  obligation: MonitoringObligation
  associatedIds: number[]
}>()

const emit = defineEmits<{
  'update:open': [value: boolean]
  associated: []
}>()

const isOpen = computed({
  get: () => props.open,
  set: value => emit('update:open', value)
})

const toast = useToast()
const { list } = useClients()
const { associateClients } = useSerpro()

const CANDIDATE_CAP = 100

const search = ref('')
const debouncedSearch = refDebounced(search, 350)
const candidates = ref<ClientSheet[]>([])
const selected = ref<number[]>([])
const loading = ref(false)
const saving = ref(false)
const capped = ref(false)
async function loadCandidates() {
  loading.value = true
  try {
    const response = await list({ q: debouncedSearch.value.trim() })
    const companies = (response.data ?? []).filter(client => client.person_type === 'company')
    const already = new Set(props.associatedIds)
    const fresh = companies.filter(client => !already.has(client.id))
    // Capped, never paginated. A load-more inside a modal is awkward on the
    // pointer and worse on a phone; the honest alternative to a bounded list
    // is a full one, and the office narrows the search instead.
    capped.value = fresh.length > CANDIDATE_CAP
    candidates.value = fresh.slice(0, CANDIDATE_CAP)
    selected.value = selected.value.filter(id => candidates.value.some(client => client.id === id))
  } catch {
    toast.add({ title: 'Não foi possível carregar os clientes', color: 'error' })
  } finally {
    loading.value = false
  }
}

watch([isOpen, debouncedSearch], ([open]) => {
  if (!open) return
  selected.value = []
  void loadCandidates()
})

/** "Selecionar todos" means every candidate matching the search, not the page. */
const allSelected = computed(() => candidates.value.length > 0 && selected.value.length === candidates.value.length)

function toggleAll() {
  selected.value = allSelected.value ? [] : candidates.value.map(client => client.id)
}

function toggleOne(id: number) {
  selected.value = selected.value.includes(id)
    ? selected.value.filter(item => item !== id)
    : [...selected.value, id]
}

const addingId = ref<number | null>(null)

async function associate(ids: number[]) {
  if (!ids.length) return
  saving.value = true
  addingId.value = ids.length === 1 ? ids[0]! : null
  try {
    const result = await associateClients(props.obligation.slug, ids)
    // Reporting the whole batch as added would be a lie whenever `already` > 0.
    if (result.already > 0) {
      toast.add({
        title: `${result.associated} clientes associados, ${result.already} já estavam`,
        color: 'warning'
      })
    } else {
      toast.add({ title: `${result.associated} clientes associados`, color: 'success' })
    }
    selected.value = selected.value.filter(id => !ids.includes(id))
    emit('associated')
  } catch {
    toast.add({ title: 'Não foi possível associar os clientes', color: 'error' })
  } finally {
    saving.value = false
    addingId.value = null
  }
}
</script>

<template>
  <UModal
    v-model:open="isOpen"
    :title="`Adicionar clientes a ${obligation.label}`"
    :description="`Acompanhe ${obligation.label} destes clientes. Nenhuma sincronização é disparada: a execução é uma ação à parte.`"
  >
    <template #body>
      <div class="flex flex-col gap-3">
        <UInput
          v-model="search"
          icon="i-lucide-search"
          placeholder="Buscar por nome ou CNPJ"
          aria-label="Buscar clientes para associar"
        />

        <UAlert
          v-if="capped"
          color="warning"
          variant="subtle"
          icon="i-lucide-info"
          title="A lista foi limitada a 100 clientes"
          description="Refine a busca para ver os demais."
        />

        <USkeleton v-if="loading" class="h-40 w-full" />

        <UEmpty
          v-else-if="candidates.length === 0"
          icon="i-lucide-user-round-search"
          title="Nenhum cliente para associar"
          description="A integração acompanha apenas clientes pessoa jurídica que ainda não estão nesta obrigação."
          variant="naked"
        />

        <div v-else class="flex max-h-80 flex-col gap-1 overflow-y-auto">
          <div
            v-for="client in candidates"
            :key="client.id"
            class="flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-elevated/50"
          >
            <UCheckbox
              :model-value="selected.includes(client.id)"
              :label="client.name"
              :description="clientSheetTaxIdLabel(client)"
              class="min-w-0 flex-1"
              @update:model-value="toggleOne(client.id)"
            />
            <UButton
              icon="i-lucide-plus"
              color="success"
              variant="ghost"
              size="sm"
              :loading="addingId === client.id"
              :aria-label="`Adicionar ${client.name}`"
              @click="associate([client.id])"
            />
          </div>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="flex w-full items-center justify-between gap-3">
        <UButton
          :label="allSelected ? 'Desmarcar todos' : 'Selecionar todos'"
          color="neutral"
          variant="ghost"
          :disabled="candidates.length === 0"
          @click="toggleAll"
        />
        <UButton
          :label="`Adicionar ${selected.length} selecionados`"
          icon="i-lucide-user-plus"
          :disabled="selected.length === 0"
          :loading="saving"
          @click="associate(selected)"
        />
      </div>
    </template>
  </UModal>
</template>
```

- [ ] **Step 2: Verify the three scoping rules hold**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS.

Then confirm by reading the code, not by testing it: `person_type === 'company'` filters the response; `associatedIds` excludes anyone already tracked; `allSelected` reads `candidates`, which is the whole filtered set for the current search, so its count is the count the footer prints.

- [ ] **Step 3: Commit**

```bash
cd frontend && git add app/components/monitoring/AssociateClientsModal.vue
git commit -m "feat(monitoring): associate clients to an obligation"
```

---

### Task 7: Terms and runs

**Files:**
- Create: `frontend/app/pages/monitoring/termos.vue`
- Create: `frontend/app/pages/monitoring/execucoes.vue`
- Create: `frontend/app/pages/monitoring/execucoes/[id].vue`

**Interfaces:**
- Consumes: `useSerpro().authorizationTerm` / `syncRuns` / `showSyncRun` / `resyncRun`, `serproTermStatePresentation`, `serproRunStatePresentation`, `serproRunItemStatePresentation`, `apiStatus`, `useAuth().canManageClients`.

- [ ] **Step 1: The terms page**

```vue
<script setup lang="ts">
import { apiStatus } from '~/composables/useApiError'
import type { SerproAuthorizationTerm } from '~/types/serpro'
import { formatMonitoringDate, serproTermStatePresentation } from '~/utils/monitoringPresentation'

definePageMeta({ middleware: 'auth' })

const { authorizationTerm } = useSerpro()

const { data, status, error, refresh } = await useAsyncData<SerproAuthorizationTerm | null>(
  'serpro-authorization-term',
  async () => {
    try {
      return await authorizationTerm()
    } catch (error) {
      if (apiStatus(error) === 404) return null
      throw error
    }
  },
  { default: () => null, getCachedData: () => undefined }
)

const isLoading = computed(() => status.value === 'pending')
const term = computed(() => data.value)
const presentation = computed(() => serproTermStatePresentation[term.value?.state ?? 'ausente'])
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 sm:p-6">
    <div>
      <h2 class="text-lg font-semibold text-highlighted">
        Termo de autorização
      </h2>
      <p class="text-sm text-muted">
        O termo é do escritório, não de cada cliente: a plataforma monta, assina e submete uma vez, com o e-CNPJ do próprio escritório.
      </p>
    </div>

    <UAlert
      v-if="error"
      color="error"
      variant="subtle"
      icon="i-lucide-circle-alert"
      title="Não foi possível carregar o termo"
      description="Verifique sua conexão e tente novamente."
      :actions="[{ label: 'Tentar novamente', color: 'error', variant: 'solid', onClick: () => refresh() }]"
    />

    <USkeleton v-else-if="isLoading" class="h-32 w-full" />

    <UCard v-else-if="term" :ui="{ body: 'p-4 sm:p-5 flex flex-col gap-4' }">
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-sm text-muted">
            Estado
          </p>
          <UBadge
            :color="presentation.color"
            :icon="presentation.icon"
            variant="subtle"
            :label="presentation.label"
            size="lg"
          />
        </div>
        <div class="text-right">
          <p class="text-sm text-muted">
            Vencimento
          </p>
          <p class="text-sm font-medium text-default tabular-nums">
            {{ formatMonitoringDate(term.expires_on) }}
          </p>
        </div>
      </div>

      <p class="text-sm text-muted">
        A assinatura é do escritório. A renovação é feita pela plataforma, sem nenhuma ação sua.
      </p>
    </UCard>

    <UAlert
      v-else
      color="warning"
      variant="subtle"
      icon="i-lucide-file-x"
      title="O escritório ainda não tem termo"
      description="Sem o certificado do escritório não há termo, e sem termo a integração não fala com o provedor em nome dos clientes."
    />
  </div>
</template>
```

There is **no upload of a signed document** and no button inviting the office to sign anything. D6 and `tasks.md` 4.8 have the platform build, sign and submit the term itself, and 4.11 says explicitly not to ask the office to sign while the term is valid. The signed document is stored verbatim and never rendered.

- [ ] **Step 2: The runs list**

`app/pages/monitoring/execucoes.vue`: one `useAsyncData('serpro-sync-runs', () => syncRuns())` with `getCachedData: () => undefined`, the same four-branch ladder, and a `UTable` of `state` / `total` / `synchronized` / `skipped` / `failed` / `finished_at`, where every state label, colour and icon comes from `serproRunStatePresentation` and every date from `formatMonitoringDate`.

- [ ] **Step 3: The run detail**

`app/pages/monitoring/execucoes/[id].vue`: `useAsyncData` over `showSyncRun(Number(route.params.id))`, with `Number.isInteger` guarding the param before the call so a non-numeric id renders the not-found state instead of calling `/serpro/sync-runs/NaN`. Items render in the **five** item states from `serproRunItemStatePresentation`; `ignorado` and `falhou` show `reason`. The obligation vocabulary does not apply here.

The re-sync button is behind `v-if="canManageClients"`. A `403` on the call is a `warning` toast **only** when the integration is not enabled for the Account, and never a redirect — `app/plugins/api.ts:42-52` redirects on 401/419 only, and that is by design.

- [ ] **Step 4: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS.

Run: `cd frontend && pnpm dev`, then open `/monitoring/termos`, `/monitoring/execucoes` and `/monitoring/execucoes/1`. Each must resolve. A static sibling under `app/pages/monitoring/` is matched before `[...slug].vue`, so none of them may 404 — which is exactly what the integration-link assertion in `tests/monitoringRoutes.test.ts` pins.

- [ ] **Step 5: Commit**

```bash
cd frontend && git add app/pages/monitoring/termos.vue app/pages/monitoring/execucoes.vue 'app/pages/monitoring/execucoes/[id].vue'
git commit -m "feat(monitoring): terms and sync run screens"
```

---

### Task 8: The connection screen

**Files:**
- Create: `frontend/app/pages/admin/serpro.vue`

**Interfaces:**
- Consumes: `useSerpro().connection` / `saveConnection` / `testConnectivity`, `apiMessage` from `~/composables/useApiError`, `formatMonitoringDate`.

- [ ] **Step 1: The credential form**

`import * as z from 'zod'` (the namespace import every zod file here uses), `type Schema = z.output<typeof schema>`, `reactive<Partial<Schema>>`, `<UForm id="serpro-connection" :schema :state @submit>`. Copy the save cycle from `ClientCadastroModal.vue:88-110`: a local `submitting` ref, `try`/`catch`/`finally`, a success toast, an error toast with `apiMessage(err)`.

```ts
const schema = z.object({
  consumer_key: z.string().min(1, 'Informe a chave de integração'),
  consumer_secret: z.string().optional(),
  password: z.string().optional()
})

type Schema = z.output<typeof schema>

const state = reactive<Partial<Schema>>({ consumer_key: '' })
const certificate = ref<File | null>(null)

async function onSubmit(event: FormSubmitEvent<Schema>) {
  submitting.value = true
  try {
    // The secret is sent only when typed. `saveConnection` drops the field
    // entirely when it is blank, which is what preserves the stored one.
    const saved = await saveConnection({
      consumer_key: event.data.consumer_key,
      consumer_secret: event.data.consumer_secret || undefined,
      password: event.data.password || undefined,
      certificate: certificate.value ?? undefined
    })
    metadata.value = saved
    toast.add({ title: 'Conexão com o Integra Contador salva', color: 'success' })
  } catch (error) {
    toast.add({ title: 'Não foi possível salvar a conexão', description: apiMessage(error), color: 'error' })
  } finally {
    submitting.value = false
  }
}
```

- [ ] **Step 2: Metadata, connectivity, and what is not on this screen**

Non-secret metadata only — certificate subject, serial, validity window, configured contracting document — and a `configured` flag. "Testar conectividade" reports the result; on an invalid credential it names `failed_element` rather than saying only "falhou".

The form is never hydrated with a stored secret, because the API never returns one, and it is replaced by the metadata card after a successful save. The per-Account enablement control stays with the Account admin and is **not** on this screen.

Do **not** add an in-page role guard. `app/pages/admin.vue` already declares `middleware: ['auth', 'super-admin']`, and a redundant check is a second thing to keep in sync.

- [ ] **Step 3: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck`
Expected: PASS. Confirm a non-super-admin cannot reach `/admin/serpro`; the parent route's middleware handles it.

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

So the message **list** is free of consequence and the message **detail** is not. `MessageDetail.vue` renders a confirmation that names what is about to happen, and only the confirmation calls `readMessage`. The component holds no call of its own until the member answers.

```ts
const confirmed = ref(false)
const message = ref<MonitoringMessage | null>(null)
const loading = ref(false)

// The body is fetched here and nowhere else. `watch(() => props.open)` must not
// call `readMessage`: opening the dialog is not consent.
watch(() => props.open, (open) => {
  if (!open) return
  confirmed.value = false
  message.value = null
})

async function confirm() {
  confirmed.value = true
  loading.value = true
  try {
    message.value = await readMessage(props.obligation.slug, props.messageId)
    emit('read', props.messageId)
  } catch {
    toast.add({ title: 'Não foi possível abrir a mensagem', color: 'error' })
  } finally {
    loading.value = false
  }
}
```

- [ ] **Step 2: Record and show the consequence**

Once read, the row shows that it was and when, plus the deadline it started — from `message.ciencia_em` and `message.prazo_limite`. An office that has missed one needs to see that it missed one. `MonitoringSheet.vue` renders a Caixas Postais row's `message` stub through a row action that opens `MessageDetail`, never through a click handler that fetches.

- [ ] **Step 3: Verify**

Run: `cd frontend && pnpm lint && pnpm typecheck && node --test tests/`
Expected: PASS.

The test that matters here is a reading of the component, not a unit test: `readMessage` has exactly one call site, inside `confirm`, and the open-watcher assigns `null`. A test asserting "the body is not fetched before the confirmation" against a Vue SFC needs a component test runner this project does not have; the assertion that holds is structural, and the structure is the deliverable.

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
Expected: PASS, the pre-existing 23 test files still green alongside the three new ones.

- [ ] **Step 2: Confirm nothing fictitious survives**

```bash
cd frontend && grep -rn "monitoringCompanies\|monitoringStatusFor\|statusCycle\|monitoringAttentionCount\|monitoringColumns" app/ tests/
```
Expected: no matches.

- [ ] **Step 3: Confirm the inert and unserved states**

`pnpm dev`, then open `/monitoring`, `/monitoring/simples-nacional`, `/monitoring/caixas-postais/det` and `/monitoring/declaracoes/dirf`. The first three show empty states with visible zeros, and **DIRF shows the obsolete state, not an empty list** — that distinction is the whole point of D21 and `tasks.md` 10.5.

- [ ] **Step 4: Confirm the counts close**

For every obligation the backend serves, `total` equals the sum of `em_dia`, `processando`, `pendencias` and `atencao`, and `encerrado` sits outside it. `monitoringCountersTotal` is the function that states it, and `tests/monitoringStatus.test.ts` asserts it.

- [ ] **Step 5: Commit**

Nothing, unless a fix was needed above.

---

## Self-Review

**Spec coverage.** All nine requirements in `specs/monitoring/spec.md` are covered. *Clientes derivados* (Tasks 3, 4 — the ten companies and the arithmetic gone, `portfolio_total` from the API, natural persons excluded by the same rule the association picker applies). *Obrigação classificada* (Tasks 1, 3, 5 — the four categories in the registry, and the no-counter branch). *Contadores* (Tasks 2, 5 — four summing to the total, `encerrado` outside, zero displayed, progress not mixed in). *Situação e causa* (Tasks 2, 5 — five row states plus four causes, the cause resolved from the backend's code). *Dado desatualizado* (Task 2's `monitoringStalePresentation`, Task 5's separate badge — never a sixth situation). *Listagem* (Task 5 — server-filtered, route-carried, 404 answered). *Estados* (Task 5 — the ladder plus the unserved state). *Colunas por obrigação* (Tasks 3, 5 — declared per obligation, none at all for an unserved one). *Painel coerente* (Tasks 4, 5, 6 — the counter is a link to the list it announced, and an association refreshes the counters it invalidated). *Guia* (Task 2's `slipStatusFor` — most recent transmission wins, no extra provider call).

**Type consistency.** The obligation table is transcribed once, in Task 3, and every later task reads it. `MonitoringCounter`, `MonitoringSituacao`, `AttentionReasonCode` and `ObligationCategory` are declared once in Task 1 and consumed by Tasks 2, 3, 5, 6, 7 and 8. The obligation slug is the registry key throughout and the contract fixes it for the backend. `parseMonitoringSlug` returns `{ obligation, situacao }` in Task 3 and `[...slug].vue` passes exactly those two names in Task 5. `ObligationCounters` takes `{ obligation, summary, situacao }` and emits `associate`; `MonitoringSheet` owns the modal and its `afterAssociate` handler. `useSerpro` is the only caller of every serpro endpoint.

**Four rulings this plan makes, and what each costs if wrong.**

1. **The nine row labels are one union of five plus one union of four, not one union of nine.** A cause refines `atencao` on a row; it is never a state a row is in. Costs: if the backend ever sends a cause as a bare `situacao`, the type rejects it and a field must be added.
2. **`attention_reasons` carries `code` and `count`, with `label` as a fallback only.** The client resolves label and colour from the code so a new cause needs no redeploy. Costs: a backend that sends only `label` and `color` and no `code` renders every cause as its raw code.
3. **Association candidates come from `useClients().list`, filtered to `person_type === 'company'` in the response.** The contract publishes no candidate endpoint and the existing list is already Account-scoped. Costs: at a few hundred clients the modal shows the 100 cap more often than the reference does.
4. **Task 1 is types and composable only; the registry rewrite moved into Task 3.** The old and new registries cannot coexist, so splitting them left a window where nothing typechecked. Costs: none — it is one fewer commit, not less work.

**What this plan deliberately does not build.** No backend. No mock layer. **No removal or deactivation of an associated client** — deactivating a tracked client changes what the next run does, which is a decision worth taking on its own rather than smuggling into a picker. No page for the fourteen provider systems the reference has no counterpart for — `DTE`, `PAGTOWEB` beyond the existing tab, `CCMEI`, `MIT`, `SICALC`, `EPROCESSO`, `PNRCONTADOR`, `EVENTOSATUALIZACAO` — each a real obligation family the catalogue serves and which the D11 first-sync set excludes. That is a scope decision for a later plan, not an omission here.

**The risks this plan carries.** The backend contract does not exist, so a shape change breaks the frontend at integration time rather than at test time — the contract section is the single place to reconcile it. Three obligations are permanently in a non-populated state, which is honest but will look unfinished to an office until the wording is reviewed by someone who can defend it. And the catalogue is a moving document with eight known self-contradictions (D22), so the mapping carries a revision date and a test rather than being trusted as settled.
