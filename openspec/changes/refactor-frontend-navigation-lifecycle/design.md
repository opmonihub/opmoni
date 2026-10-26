## Context

See [proposal.md](proposal.md) for scope and [specs/](specs/) for the behavior contract. This planning worktree starts at `2454fa8`, the committed Planos follow-up. Installed baseline is Nuxt 4.6.0, Vue 3.5.43 and Nuxt UI 4.11.3; the Nuxt skill also describes later versions, so implementation must verify APIs against the installed baseline rather than assume a framework upgrade.

The application already uses persistent `NuxtLayout`/`NuxtPage` shells and native Nuxt UI links. Normal links are not systematically reloading the document. Fiscal pages already preserve hydration payloads while revalidating later entries, and most navigation registries are reactive. `useAccessApi` snapshots session/tenant assertions and routes requests through a per-Nuxt-app serialized admission queue. Backend authorization remains authoritative.

### Audit coverage and evidence boundaries

The preceding exploration inspected scripts and supporting shared flows for all 105 page files: company ERP 43; Clients/Fiscal/Monitoring/Work/Team including their shells 24; office commercial 12; Admin 11; Settings 8; remaining root/access/demo pages 7. It exercised 32 representative routes in Paseo, in addition to the seven commercial tabs. Dynamic record/link cases and live company ERP flows were not fully accepted; the browser identity had no selected Company. Browser timing examples are local observations, not performance service levels.

| Finding | Evidence available before implementation | Primary source |
| --- | --- | --- |
| Commercial active tabs freeze after navigation | Authenticated Faturas to Assinaturas/Planos retained the Faturas current marker while document, header and navigation nodes stayed mounted | `pages/gestao-erp.vue` |
| Hydration substitutes defaults | Direct Monitoring load had 16 SSR attention keys and zero client attention keys with success status; SPA entry retained 16 | `pages/monitoring/index.vue`; analogous cache policy in Settings certificate/WhatsApp |
| Shared settings update erases another draft | A synthetic, in-memory probe of the real section form reproduced overwrite after same-company shared-state replacement | `components/erp/ErpSettingsSectionForm.vue`, `ErpCompanyProductTaxProfileDefault.vue`, `useErpCompanySettings.ts` |
| Query/hash remounts settings editor | Source-confirmed broad key; live Company reproduction pending | `pages/erp/configuracoes.vue` |
| Duplicate operational initialization | Source shows both component and composable initiating the selected read; Company request-count acceptance pending | `ErpPurchaseWorkspace.vue`, `useErpPurchaseWorkspace.ts`, `ErpSalesWorkspace.vue`, `useErpSalesWorkspace.ts` |
| Redundant commercial context work | Configuration entry sent two context GETs; context and resource reads accounted for roughly 2–4 seconds in sampled entries | `useOfficeErpContext.ts`, commercial page loaders |
| Departed reads block current navigation | Assinaturas to Usuários still dispatched the old plan catalog before the current users request | `pages/admin/assinaturas.vue`, `utils/adminListFilters.ts` |
| Duplicate Work URL synchronization | Tarefas and Clientes each made two equivalent replacements on missing-month entry | `useWorkReferenceMonth.ts`, Work shell and children |
| List filters reset on return | Clientes ERP search became empty after a tab round trip | Office commercial list refs; other real list families need the same acceptance matrix |

The current focused pre-commit baseline passed 69 tests, changed-path ESLint, strict onboarding-change validation and typecheck. Typecheck needed a temporary `NODE_PATH` to resolve the already-installed Nuxt transitive Vue Router Volar plugin; no dependency/configuration was changed. The prior expanded audit ran another 134 existing focused tests successfully. These are baseline results, not acceptance of this unimplemented change.

Two tentative distinct-ID stale-record findings were withdrawn after checking Nuxt's native interpolated route keys: ordinary parameter changes already remount those detail pages. Do not add broad key overrides to solve an unproven reuse problem. Raw awaited detail loaders in client and Work template pages remain lower-confidence direct-entry double-fetch candidates; establish SSR plus hydration counts before changing them.

## Goals / Non-Goals

**Goals:** Use the existing Nuxt lifecycle primitives consistently; make route/query ownership explicit; preserve same-context drafts and list navigation; avoid sending obsolete safe reads; extend regression tests across transitions rather than only isolated mounts.

**Non-Goals:** Replace the router or API client, upgrade Nuxt, introduce a state-management dependency, change backend admission/authorization, add cross-session browser persistence, redesign page families, migrate data, or convert reference/demo screens into product features. A legitimate first load or authorization refresh can still show progress; this design does not promise zero network requests or a fixed millisecond latency.

## Decisions

### 1. Reuse native shells and narrow route identity

Keep the current shell hierarchy and Nuxt UI `to`/`exact` conventions. Make commercial tabs reactive through the existing registry style or a computed item collection. Preserve child-prefix matching where it is intentional and exact matching for the workspace root and exact destinations. Test one mounted shell through several route changes and history operations.

Remove `route.fullPath` as the settings child component key. Native Nuxt page identity already distinguishes a changed child or dynamic entity; query/hash alone must not destroy an unchanged editor. Retain explicit authority-keyed ERP content invalidation and existing authentication/context-switch reloads. Preserve mobile sidebar focus, disclosure state and print layout isolation.

Alternative: blanket KeepAlive could hide repeated initialization but would retain protected editors, polling and command state across unrelated views. It is excluded. A visual shell rewrite would not address ownership and would expand the scope unnecessarily.

### 2. Separate hydration reuse from later-visit freshness

Use the already-correct Fiscal policy for affected readers: during hydration return the matching `nuxtApp.payload.data[key]`; outside hydration retain the page's explicit revalidation policy. Preserve valid null/absence and errors; do not use truthiness to reject an empty result. Route page-data reads through the existing `$api`/`useAccessApi` transport.

For any confirmed raw-loader double fetch, use the installed `useAsyncData` behavior with an authority/entity/query-aware key and its cancellation signal, or an equally explicit payload-aware wrapper. Keep actions as commands and provider/legal-consent operations behind their current explicit user actions. Do not prefetch commands or move certificate/password/QR/recovery secrets into new shared serialized state.

Alternative: permanently returning cached payload would repair hydration but violate the Monitoring return-after-synchronization contract. Disabling SSR or forcing another client request would mask the defect while adding churn.

### 3. Coordinate reads without weakening serialized admission

Keep `useAccessApi` and `createAccessApiAdmissionQueue` as the only business transport path. Extend existing read runners to accept an `AbortSignal`, abort on supersession/disposal, and ignore intentional cancellation in page feedback. Reuse working cancellation patterns in catalog/Fiscal loaders. Leave session headers, dispatch-time authority checks, CSRF, wire snapshots and response-cookie behavior unchanged.

Track the one pending commercial context refresh in nonserialized storage scoped to the Nuxt app and its captured authority. An app-keyed WeakMap or app-owned private coordinator is acceptable; a module-global unkeyed promise or a promise in `useState` is not. Preserve the public refresh/capture semantics for consumers. Context confirmation remains current per entry/explicit refresh; coalescing only shares concurrent work, not indefinite cached authorization. App-owned shared confirmation is not aborted merely because one page consumer leaves; authority invalidation prevents its late commit, and one consumer cannot cancel another's current work.

Publish valid capabilities atomically and distinguish initial confirmation from a later capability change. Avoid the existing first-confirmation watcher cycle initiating a second confirmation/data load. Choose one initialization owner in Compras/Vendas that selects the permitted initial hash before initiating required reads; other mounting hooks only initialize local presentation/recovery. Preserve intentional distinct references, returns and list reads.

Only safe obsolete reads participate in this cleanup. Explicit downloads, commands already admitted, and unknown-outcome recovery keep their current semantics. Cancellation is never proof of server rollback. Do not remove serialization to make `Promise.all` appear parallel; avoid redundant work before it reaches the queue.

### 4. Preserve navigation state with explicit authority ownership

Use Nuxt `useState` for a small validated list-state registry and reuse existing filter codecs. Store only supported search/filter/page-size/page values. Namespace by logical list and capture actor, session generation, selected Account/Company and effective authorization stamp as applicable; platform pages remain usable without inventing an Account membership. Clear the registry on authority changes rather than keeping historical tenant buckets. Do not persist list records or mutation forms in localStorage/sessionStorage through this feature.

Initialization precedence is: explicit supported URL values, then matching live registry values for a normal tab return, then existing defaults. Fresh document entry has no remembered memory state. Existing URL-owned surfaces continue using their codecs; no unnecessary URL expansion is required for list surfaces currently using local filters. Preserve unrelated query keys. An absent query on a canonical tab link may restore the matching remembered list; explicit filters/history replace it.

Unify page-reset and request watchers so one user filter change produces one final normalized query, not an old-page read followed by page-one read. Server metadata still bounds restored pages. Bulk selection snapshots, recovery keys and sensitive search results retain their own existing contracts.

Work competence remains shared state. Give its route mirroring one owner in the Work shell (or a per-app shared coordinator) and let children consume values/actions without registering another URL writer. Acquire composables before asynchronous boundaries and keep all SSR state request-local.

### 5. Reconcile settings by field ownership

Retain private form state per editor and a baseline snapshot for fields that editor owns. On a same-authority settings update, merge confirmed values into untouched fields while preserving dirty fields and their validation feedback. On the editor's own successful save, advance the baseline and reconcile only its submitted/confirmed fields. A remote change to a dirty field produces a concise review notice with an explicit existing-component reload/discard action; it must not silently replace the draft or fabricate a successful save.

Apply the policy to `ErpSettingsSectionForm` and affected sibling consumers of `useErpCompanySettings`, including email where appropriate. On authority/grant change, clear private fields and feedback synchronously as today. Compare canonical strings/field values without converting money, decimal or classification codes through floating-point numbers. No global unsaved-changes modal or cross-route mutation-draft persistence is introduced.

### 6. Preserve accepted product and service boundaries

No API envelope, endpoint, Laravel policy, fiscal integration, background process or database schema changes. D009 membership isolation, Company delegation, maintenance/new-sale distinctions, the integrated onboarding/trial delta and all production gates remain unchanged. Additive commercial lifecycle requirements avoid replacing the still-unarchived onboarding workspace requirement. Canonical syncing/archiving either change is a separate action.

The `.dashboard` reference is ignored and absent in this new worktree; consult `/home/obsidian/dev/opmoni/.dashboard` read-only during implementation. Do not copy sample endpoints or use the unrelated `reference/sistema` tree as product authority. Consult Nuxt UI MCP and installed component examples before changing UI composition.

## Risks / Trade-offs

- **Shared cancellation could abort useful work** → distinguish page-owned reads from app-owned shared confirmation and retain dispatch/authority regression tests.
- **Authorization cache could become a privilege cache** → coalesce only pending same-authority work; retain current checks, backend authorization, invalidation and definitive refusal handling.
- **Remembered filters could leak tenant context** → clear on actor/session/tenant/grant changes, validate on restore, and keep only minimal navigation state in memory.
- **Draft merge could conceal a conflicting remote change** → track field baselines, preserve the draft, show review feedback, and keep server revision checks.
- **A hydration fix could freeze later visits** → separately test direct SSR, hydration, SPA reentry, explicit refresh, null, empty and failed responses.
- **Broad changes could disrupt financial recovery or provider operations** → keep command/consent paths out of generic read cancellation and run their existing regressions.
- **Synthetic tests can miss Nuxt routing behavior** → use real mounted components and real Nuxt browser flows; distinguish native parameter remounts from query/hash changes.
- **New worktree runtime is not bootstrapped** → planning does not copy `.env`, install packages, start containers, select a Company or reuse a checkout-bound runtime silently. Establish an isolated supported runtime before apply-time browser acceptance.

## Migration Plan

No data migration is required. Implement in verifiable waves: hydration/draft safety; route/list/competence ownership; read coalescing/cancellation and operational initialization. Keep shared-file changes serial and use scoped commits. A release uses the standard frontend build/deployment; rollback restores the prior compatible frontend version. Do not rotate keys, reset data or alter registration/fiscal gates.

Run focused regressions for each wave and the full frontend lint/typecheck/test/build matrix before completion. Browser acceptance must include authenticated office and Company contexts, direct entry and history, desktop/mobile, and light/dark. Record failed or unavailable gates explicitly; frontend mocks do not establish PostgreSQL/session concurrency or provider delivery. If the scope later requires backend session changes, it must be revised to include independent PostgreSQL/Redis-process verification before that work proceeds.
