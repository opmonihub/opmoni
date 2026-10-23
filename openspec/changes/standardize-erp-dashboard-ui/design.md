## Context

See [proposal.md](proposal.md) for scope and [the audit](../../../docs/reviews/2026-10-23-erp-dashboard-consistency/README.md) for D01-D09, source locations, inventory, and current browser measurements. The source baseline is `5ce7d51`; the earlier 2026-10-20 plan is already completed historical work and must not be reopened as this implementation ledger.

Nuxt UI 4.11.3, Nuxt 4.6.0, Vue 3.5.43, existing Node/Vue test helpers, and Tailwind are sufficient. The existing `ErpCatalogPageShell`, `ErpCatalogResource`, `ErpSettingsSectionForm`, catalog field/reference components, and `data-table/panel.ts` are the reuse boundaries. `ErpModuleHub` has no current application consumer.

## Goals / Non-Goals

**Goals:** fix measured geometry defects; make active ERP list/filter/footer compositions consistent; improve current settings feedback; preserve exact decimal values and existing authority/lifecycle behavior. Cover every inventory entry with a disposition: changed directly, covered by a shared change, already conforming, or justified exception.

**Non-Goals:** a replacement application shell, universal CRUD abstraction, business workflow changes, migrations, new backend APIs, identifier remapping, dependency upgrades, or changes to the office/admin design system. Specialized import/label/print/report/accounting detail structures remain specialized. Public registration keeps `layout: false`; the contract keeps its narrow record-page measure.

## Decisions

### 1. Incremental shared composition

Use existing tokens and small, controlled compositions. Page-by-page patches would duplicate decisions; a universal ERP table/form framework would introduce state ownership and hide business-specific controls. Neither is needed.

Add only these runtime units:

| File | Responsibility |
| --- | --- |
| `frontend/app/components/erp/erpPresentation.ts` | ERP page body token and a table-style adapter derived from `data-table/panel.ts`. No business data/state. |
| `frontend/app/components/erp/ErpListFooter.vue` | Confirmed count and controlled server pagination. No fetching, filtering, aggregation, or state caching. |
| `frontend/shared/utils/erpDecimalDisplay.ts` | Display-only localization of exact canonical decimal strings. No mutation or serialization. |

Keep `ErpCatalogPagination` as the owner of catalog loading/stale/error/retry announcements; it delegates only its confirmed footer. Keep all existing workspace composables as owners of queries, drafts, permissions, idempotency, and operation recovery.

### 2. Geometry and visual contract

- Sidebar: override the **ERP sidebar's header slot classes** with `h-auto min-h-(--ui-header-height) py-3`, retaining native horizontal padding. Retain the native 64px minimum and compact state, 15rem default width, current context menu, selection API, and footer. No new controlled collapse state is needed merely to size the header.
- Navbar: keep native 4rem height, semantic theme, 16px title, and one sidebar toggle. Do not add a second page navbar.
- Page gutter: `erpPageBodyClass = 'space-y-5 p-4 sm:p-6'`. The native panel body is padding-free when this wrapper owns the gutter. Settings keep `max-w-4xl`, with one outer gutter and normal card padding. Contract/public registration are documented exceptions.
- Catalog filters: use a body-level responsive filter row aligned to the table frame; remove the nested dashboard toolbar's additional horizontal padding. Native toolbars remain in panel headers for navigation.
- Controls: retain default `md` for primary actions and filters. Keep existing justified `sm`/`xs` secondary/row actions rather than enlarge every control. Group fields with `gap-4` and responsive columns; preserve accessible names and native keyboard behavior.
- Theme: retain Public Sans, green/zinc semantic tokens, `UApp` locale, and the ERP light-mode contrast correction. Do not reset colors to the brighter reference palette without separate contrast evidence.

At 390px, plain catalog content/table edges must start at x=16px; settings cards at x=16px and their standard inner content at x=32px. At 1366px with the 240px sidebar, catalog filter and table edges must start at x=264px. Tests allow a 1px geometry tolerance for borders/fractional layout.

### 3. Active table styling and server pagination

Export `erpOperationalTableUi` from `erpPresentation.ts`, deriving header, row-rule, rounding, and separator slots from `panelTableUi`. Override only its base with `min-w-full table-auto border-separate border-spacing-0` for business tables whose column widths are not fixed. This preserves the reference surface while avoiding blindly imposing `table-fixed` on variable operational columns. Do not change global `panelTableUi` or office/admin consumers.

Apply the adapter to compatible active catalog, finance, cash, purchase, and stock lists. Review label selection/import preview/report tables for compatible header styling without replacing their sizing or semantics. Keep the semantic accounting-delivery HTML table; align tokens only where needed. Do not modify the unreferenced hub to represent progress on active screens.

`ErpListFooter` consumes:

```ts
type PaginationMeta = ErpPaginated<unknown>['meta']
// Props: { meta: PaginationMeta, pending?: boolean, summary?: string }
// Defaults: pending=false; summary uses a localized registro/registros count.
// Required model: page: number; emits update:page only for a valid user transition.
```

The footer displays confirmed counts, a responsive layout, and `UPagination` only when `last_page > 1`. It uses `total` and `per_page`; it neither slices current records nor calculates business totals. Pending/invalid/unchanged transitions emit nothing. Mounting or receiving new metadata must not emit an automatic page request. Callers retain their current page-change/load handler and filter semantics.

### 4. Finance empty state belongs to the visible frame

Keep a minimum-width scrollable table for confirmed populated rows. Render the confirmed-empty explanation with native `UEmpty` **outside that minimum-width area**. Preserve the current message, retry/error states, retained data, and toolbar action. Loading/unknown/failed reads must not become confirmed-empty content.

This fixes D08 without misclassifying native `UTable` internal scrolling as broken in purchase or stock lists.

### 5. Settings renderer and feedback

Use the existing exported `ErpSettingsField` in `erpCompanySettingsFields.ts` for both the metadata and renderer; include existing `options` plus optional `description` and `hint`. Avoid a second divergent inline field definition. Keep existing input kinds and value conversion unless a separate verified field contract requires a change.

Keep `UForm` bound to the reactive form and named `UFormField` controls. Extract 422 errors through the existing `apiFailure` convention and feed the matching first message into each field. Preserve all entered values, show non-field failures visibly, prevent duplicate submits, and clear errors on a new attempt or authority change. Late errors and success messages must pass the same current-authority check as current saves. Email settings map nested `email_settings.<key>` errors to their existing fields without changing their PATCH structure.

Replace visible Blade paths/internal change references across settings with concise domain guidance. Unsupported sections must say what is available today; do not invent an editor or successful save to replace an honest placeholder.

### 6. Legacy-reference compatibility is a separate dependency

The code currently stores the following as allowlisted opaque settings; none has a verified settings-specific label/identifier contract:

| Field | Current editing | Available candidate, not a proven mapping | Disposition in this frontend change |
| --- | --- | --- | --- |
| `default_unit` | Text | Native `/erp/units` exists. | Preserve value/editing until its identifier namespace is established. |
| `default_sales_tax` | Text | Native `/erp/tax-settings/tax-rates` exists. | Same; a matching number is insufficient evidence. |
| `currency_id` | Number | No currency options in settings metadata. | Preserve; record missing catalog/namespace contract. |
| `purchase_currency_id` | Number | No currency options in settings metadata. | Preserve; record missing catalog/namespace contract. |
| `cidade_id` | Number | No city options/mapping in settings metadata. | Preserve; record missing city/namespace contract. |

Produce a field-by-field contract register and a separately scoped follow-up for unresolved sources/validation/mapping. Only integrate `ErpCatalogReferenceSelect` for a field if actual contract evidence becomes available before implementation and the proposal/spec/task coverage is updated coherently. Do not build an unused speculative adapter. D06 remains **partially addressed / compatibility work pending**, not resolved by changing labels or adding a dropdown.

### 7. Decimal localization is display-only

Create:

```ts
formatErpDecimalDisplay(
  value: string | number | null | undefined,
  options?: { minimumFractionDigits?: number }
): string
```

Default `minimumFractionDigits` is 0; product prices pass 2. For canonical fixed-point strings, group the integer part with dots, use a decimal comma, and remove only insignificant trailing fractional zeros beyond the requested minimum. Preserve all meaningful digits and signs. Null/undefined/empty produce `—`; non-canonical values retain their original display string rather than becoming zero. Do not pass decimal strings through `Number`, `parseFloat`, or rounding `Intl.NumberFormat` calls.

Use it only in read-only catalog cells in products, fiscal rates, units, pricing, and customer groups. Keep `formatErpDecimal`, form models, validation, CSV/labels, and API serialization unchanged. Test the chosen display contract separately from existing lossless payload tests.

### 8. Isolation and execution order

Prepare a suitable existing worktree under `.worktrees/`; the audit-shell worktree can be reused after its state is checked and a `codex/standardize-erp-dashboard-ui` branch is created. Preserve copied instruction files, current main changes, audit files, and development data. This plan creates no worktree, branch, seed, or runtime itself.

Execute sequentially while tasks share layout/finance/settings files. If delegation is chosen later, only independent, explicitly disjoint file sets may run in separate worktrees; serialize shared tokens, footer interfaces, generated types, build, and acceptance. No new delegation is required to review this plan.

For browser checks, confirm that the frontend serves the implementation worktree. Use normal authentication and explicit Company selection. Read-only existing demo/fixture data may be reused; invalid submissions and mutations require an isolated backend database/session/cache setup. No development DB resets or implicit membership grants.

## Risks / Trade-offs

- **Shared context markup also appears outside ERP** → size its ERP host, preserve `WorkspaceContextMenu` behavior, and inspect the office shell as a regression sample.
- **Table layout changes can obscure action/selection columns** → retain auto layout for variable columns and check long names, numeric columns, selection, last-column actions, and local horizontal scrolling.
- **New footer accidentally duplicates requests or changes totals** → a controlled interface with no fetches; test pending/invalid/same-page transitions and each workspace adapter.
- **Generic settings errors leak across contexts** → retain authority tokens and test late 422/409/403 responses with Company/Account/session changes.
- **Legacy and native IDs overlap numerically** → no inferred mapping; preserve editing and track the unresolved backend contract.
- **Decimal localization corrupts precision or payloads** → separate string-based display helper and explicit payload regression assertions.
- **A test harness stubs away geometry or new components** → mount real shared components for behavior; browser measurements prove layout. No source-string/CSS-class snapshot tests for reversible presentation changes.

## Migration Plan

No schema, stored-value, session, dependency, or environment migration. Ship reviewable frontend checkpoints in task order. Revert a checkpoint or restore a compatible application version if a regression appears; never reset data, rotate keys, change release gates, or replay financial/fiscal operations as rollback. Keep the baseline audit immutable and write implementation results into a separate evidence directory. Reference-contract follow-up is explicitly outside this deployment.
