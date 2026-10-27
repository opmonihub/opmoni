## Context

See `proposal.md` for motivation and the five capability deltas for observable contracts. The baseline is root `main` at `dac7293`; uncommitted planning maps and interrupted `erp-settings-*` worktrees are evidence to preserve, not an implementation to merge automatically.

The [system map](../../../docs/superpowers/plans/2026-10-26-system-ui-standardization-map.md), [crosswalk](../../../docs/reviews/2026-10-26-system-ui-pattern-crosswalk.md), [inventory](../../../docs/reviews/2026-10-26-system-ui-component-inventory.csv) and [ERP/reference comparison](../../../docs/reviews/2026-10-26-erp-settings-reference-divergences.md) record the source baseline. The inventory contains 31 actual table declarations and seven slideovers; wrapper consumers exceed those counts. Four slideovers host bounded editors, including the catalog resource instantiated 13 times across nine page files.

Nuxt UI 4.11.3, Vue, TanStack, shared data-table controls, multiple table-style utilities and existing domain composables already provide the necessary primitives. Settings and team follow the reference closely; portfolio/Work already provide useful selection/sorting patterns. Some commercial, administrative and catalog query contracts lack ordering fields, so frontend-only sorting would be misleading across server pages.

Canonical navigation, client selection snapshots, Work task scope, fiscal references, company catalog precision and platform response envelopes remain authoritative. The existing `simplify-office-erp-client-onboarding` change is separate; its canonical/pending lifecycle must not be overwritten by this visual change.

## Goals / Non-Goals

**Goals:**

- Converge equivalent tasks on shared compositions and controls while retaining domain workflow ownership.
- Add useful global ordering to three bounded query families, with compatible defaults and authority-safe behavior.
- Make modal editors predictable without losing dirty state, validation, focus or recovery.
- Preserve advanced existing table behavior rather than replace capable lists with simplistic wrappers.
- Deliver in reviewable waves with a complete capability/exception ledger and paired reference acceptance.

**Non-Goals:**

- A new universal table engine, form schema generator, theme, UI dependency or business authorization layer.
- Work/monitoring server-query expansion, new bulk endpoints, new mass financial/fiscal/licence/admin commands or changed transaction atomicity.
- Changing audit/summary ordering, selector defaults, immutable snapshots, posting behavior or selected-tenant access.
- Converting every drawer to a modal or every report/card into an operational table.
- Altering fiscal service boundaries, production gates, certificates, secrets, environment keys or development data.

## Decisions

### 1. Compose existing primitives; keep domain controllers/composables

Use `DataTablePanelList`, `DataTableFilterPanel`, `DataTableSortButton`, `DataTableColumnMenu`, `DataTableRowActionsMenu`, `DataTableIdentity` and `DataTableMetaList` as the primary shared vocabulary. Review their actual interfaces before adapting consumers. Reduce `OfficeErpPanel` to a compatibility adapter or migrate callers when their slot contracts are proven equivalent; do not retain an independent visual implementation.

Compare `panel.ts`, `sheet.ts`, `workGroupedTable.ts`, `erpPresentation.ts` and `pageShell.ts` for shared geometry, preserving intentional dense-table scroll ownership and grouped-row styling. Consolidate a selection-bar shell only where the same count/clear/action presentation is genuinely shared; leave snapshot, task-leaf and action policies in their existing domain owners.

Alternative rejected: a single configurable table/form supercomponent. It would duplicate the native engine and obscure resource-specific reads, posting and recovery.

### 2. Define a small set of shared page compositions

Use Settings' naked heading plus subtle grouped-body composition for equivalent lists/forms. Keep page-specific primary actions in the shared heading and secondary actions in the established toolbar/menu. Use a centered Settings reading measure for scalar forms, a broader operational-list measure and intentional full-panel dense workspaces. Preserve existing page-measure utilities rather than encode widths independently in each module.

User-approved final color refinement: operational buttons default to the native neutral palette, with solid neutral for main commands and outline/ghost neutral for secondary actions. Explicit green operational buttons adopt neutral as well. Keep green brand/focus/active indicators and the existing success, warning and error semantics; do not recolor all components or replace native light/dark contrast states. The untouched demonstration customer modal and public trial recovery keep their explicit primary accents.

Forms use aligned label/control rows on desktop and stacking on mobile; repeated invoice lines and comparable financial facts retain appropriate grids. Prefer one useful description over decorative page/subsection copy. Group reports, histories and preference sections by actual task. No global `UCard` replacement is planned.

For administrative commercial plans, the target is a subtle horizontal `UPageCard` list with price/cycle/status and module disclosure, preserving search/status/paging/edit/archive. Its precise spacing is evaluated with the reference; this extension does not pretend Settings contains a plan-management screen.

Alternative rejected: exact template markup everywhere. Demo member lists, static notifications and narrow profile forms do not supply the product's financial, selection or authority semantics.

### 3. Maintain a capability ledger per real list consumer

Record data mode, filters, sort fields/default, paging/loading strategy, stable row identity, visibility controls, row actions/details, hierarchy, selection scope, existing bulk commands and unsupported features. Classify each capability as present, missing/useful, not applicable with a reason, or separately scoped API/product work.

The concrete new query work in this change is ordering for company catalogs, office-commercial resources and Admin Accounts/users/subscriptions. Other families retain their supported contracts and gain consistent presentation. Missing new Work/monitoring queries or mass operations remain explicit follow-ups, not silently declared complete.

Alternative rejected: enable every optional table control on every resource. Read-only users, reports and monitoring selection have different permitted actions.

### 4. Extend ordering additively and explicitly

Use optional `sort` plus `direction=asc|desc`. A supported `sort` without direction uses ascending order; a direction without a sort and unsupported fields/directions return 422 field errors. Reset removes both parameters and restores the endpoint's exact existing default. Keep existing search aliases and normalization paths unchanged.

Initial allowlists are deliberately bounded:

| Resource | Ordering fields added | No-sort behavior retained |
| --- | --- | --- |
| Company catalogs with a visible `name` identity | `id`, `name`, and `status` when already exposed | Existing catalog `id ASC` |
| Company carriers | `id`, `legal_name`, `status` | Existing catalog `id ASC` |
| Company units | `id`, `actual_name`, `short_name`, `status` | Existing catalog `id ASC` |
| Company vehicles | `id`, `plate`, `status` | Existing catalog `id ASC` |
| Company operation natures | `id`, `cfop`, `status` | Existing catalog `id ASC` |
| Office clients | `id`, `name` | Existing name-ascending query |
| Office offers | `id`, `name`, `status` | Existing name-ascending query |
| Office contracts | `id`, `client`, `agreement_status` | Existing identifier-descending query |
| Office invoices | `id`, `number`, `due_on`, `document_status` | Existing issued-at DESC, ID DESC |
| Office receipts | `id`, `received_on`, `reference` | Existing received-on DESC, ID DESC |
| Admin Accounts | `id`, `name`, `status` | Existing ID DESC and Account-specific meta |
| Admin global users | `id`, `name`, `email` | Existing ID DESC and resource meta |
| Admin subscriptions | `id`, `account`, `status` | Existing ID DESC and resource meta |

Allowlists map to trusted resource-owned columns or constrained relations; never interpolate arbitrary requested identifiers into SQL. Apply current authorization and entity filters before ordering/paging. Use the same-direction identifier tie-breaker when the selected field is not ID; order nullable fields with nulls last. Revalidate joins against current scope, avoid row multiplication and keep correct counts. Sorting does not choose a CFOP default or alter any reference selection.

Frontend headers expose only these supported fields. Existing money/date display values remain exact; aggregate balance/price sorting not listed above is not promised. Raw-array Admin plans may use bounded local ordering; support logs and summary events keep their separate canonical orders.

Laravel changes stay within current catalog scopes/controllers, `OfficeErpQueries` and commercial validation, and existing administrative validators/readers. Platform response adapters remain in place. No migration is expected; any measured index need is a separate reviewed schema decision.

### 5. Put supported sort state in existing navigation ownership

Extend existing list criteria/codec allowlists and authority keys rather than create another persistence store. Valid explicit URL/history criteria win over remembered state. Query identity includes normalized sort/direction. Supported filter/sort changes reset page once and cancel or supersede obsolete reads using existing lifecycle ownership; hydration must not issue duplicate initialization reads.

Keep unsupported stored criteria out of dispatched queries. Selection and mutation drafts remain outside remembered navigation. On ordinary page/sort changes, stable IDs and resource policy determine whether an in-view selection survives; authority changes always invalidate protected state.

Alternative rejected: local table sorting of a server page or persisting all native table state. Both misrepresent scope and can restore private selection into later navigation.

### 6. Preserve selection and bulk meaning

Portfolio keeps explicit IDs and filter-wide server snapshots with exclusions. Work keeps eligible task leaves, aggregate group selection and independent per-task result handling. Monitoring keeps selected loaded clients for its existing search operation. Fiscal exports remain distinct from fiscal mutation.

Use `getRowId` for stable identities and a visible selection scope/count. Reuse present bulk actions only; shared chrome never supplies new powers. Preserve pending, partial failure and unknown-outcome recovery. Global users remain read-only. No implicit all-server selection is inferred from a local native-table checkbox.

Alternative rejected: a generic selected-ID batch that calls every row action. That would confuse read-only exports, independent task changes and irreversible financial/fiscal operations.

### 7. Keep detail expansion, grouping and tree data distinct

Use the official `TableRowExpandableExample`/`#expanded` pattern for one-record facts. Clients/subscriptions use the same record-owned detail semantics across desktop/mobile, with fewer essential narrow-screen columns and reachable displaced actions. Reset or reconcile expansions when result identity changes so an old row position cannot expose another record's details.

Keep Work's `getGroupedRowModel` and group/leaf semantics through its existing utility. Use the official `TableTreeDataExample` and `getSubRows` only when a real parent/child consumer exists; this change does not invent a tree resource or a separate `TableTree` dependency. Verify keyboard controls and indeterminate selection separately from expansion.

### 8. Migrate four editor hosts to native modals

Migrate `OfficeErpPlanEditor`, Admin plan/subscription forms and catalog create/edit modes to `UModal`. Reuse existing domain forms and command handlers, `ModalFooterSlotExample` and `FormExampleOnError`; keep form IDs when submit buttons are outside the form element. Use responsive width/content scrolling and preserve pending-close guards, named errors and focus return.

`ErpCatalogResource` requires special care: preserve create/edit/detail mode distinction, dirty-state review, nested product dialogs, tabs, archive confirmation, focus return and authority invalidation. Its read-only detail host can remain an inspector; editor modes use a modal. Ensure only the current host owns keyboard/focus/close listeners.

Long enrollment, subscription/invoice and Work model/process workflows remain pages. Notifications, mobile Inbox, fiscal inspection and the mobile filter drawer keep their task-specific containers. A `Sheet` filename or table style is not evidence of a form overlay.

Alternative rejected: converting every panel into a modal, or changing only the root tag and dropping lifecycle guards.

### 9. Reference acceptance is behavioral as well as visual

Capture corresponding Settings and product pages under the same theme, viewport, zoom, font readiness and sidebar state. Include desktop, intermediate width, phones and the user's 1046px content-panel case. Compare content measure, heading/action alignment, section spacing, card variants, field rows, dividers and control states. Record intentional business differences instead of inventing a pixel-fidelity percentage.

Use one batched inspection, one material correction batch and confirmation; preserve the bounded Impeccable review process. Reuse existing tests and add meaningful coverage for changed sorting, selection, modal and authority behavior. Scope simulated API layout evidence separately from real backend/PostgreSQL/concurrency acceptance.

### 10. Preserve security and service boundaries

Nuxt owns presentation and criteria state; Laravel owns authorization, validation, authoritative sorting and domain effects. Browser/SSR remain on relative `/api/v1` through the private proxy. D009 remains unchanged: Super Admin has no implicit tenant access. Preserve origin/CSRF checks, protected no-store responses and current error/resource-order contracts.

No secrets enter sort state, UI examples, screenshots or durable reports. No fiscal capture/issuance, certificate storage, Redis/session strategy or separate fiscal-service extraction changes are included. A user closing a modal or cancelling a read never implies server mutation rollback.

## Risks / Trade-offs

- [Shared styling changes affect many consumers] → integrate common primitives first, test all adapters, migrate small families and retain intentional table geometry.
- [Related-field sorting leaks scope or duplicates rows] → resource-owned allowlists, scoped relation ordering, stable ties, count tests and forbidden/foreign-scope cases.
- [SQLite hides PostgreSQL ordering differences] → verify ties/nulls and scoped pagination on isolated PostgreSQL when those queries change; do not infer that gate from SQLite alone.
- [Modal migration loses nested focus or dirty fields] → retain the existing editor owner, test product/nested discard dialogs, server errors, save/close races and Account/Company switches.
- [Selection appears broader than its backend meaning] → show scope and preserve each domain's snapshot/leaf/local-selection policies and partial-result behavior.
- [Reference simplification hides necessary controls] → acceptance exercises complete mobile business tasks, recovery and financial facts, not only the first viewport.
- [Historical planning/configuration drifts from the implementation] → reconcile maps, examples and global/surface design documentation after acceptance, without rewriting historical evidence as current success.

## Migration Plan

1. Inventory consumers, reconcile interrupted drafts without modifying them automatically, and record capability/sort/default/selection/exception contracts.
2. Consolidate shared compositions and sort/navigation plumbing without global consumer replacement.
3. Add backward-compatible ordering to the three query families, with focused validation, authorization, default-order, envelope and multi-page tests.
4. Migrate the commercial list/plan-modal and one shared-panel pilot; obtain paired reference evidence before multiplying the pattern.
5. Migrate catalog modal consumers, then portfolio/Work/monitoring/fiscal, Admin/team/commercial ERP, and company operations/reports in separate ownership groups. Only independent consumer waves run in parallel after shared prerequisites are integrated.
6. Run matching frontend/backend checks and independent database/process checks where their guarantees are exercised. Review meaningful financial/authority changes independently.
7. Reconcile global design guidance, surface exceptions, capability ledger and documented remaining follow-ups; retain demo/live-provider limitations.
8. Integrate only after the completed change is explicitly finalized. Roll back by restoring the prior compatible app and retaining default-compatible API queries; no destructive database reset, key rotation or automatic worktree cleanup is part of this proposal.
