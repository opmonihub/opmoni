# System UI Standardization Map

Date: 2026-10-26
Status: implementation in progress; current acceptance is tracked in `docs/reviews/2026-10-26-system-ui-implementation/acceptance.md`
Scope: the entire product frontend, with staged migration

## Outcome and confirmed direction

Give office and ERP operators one coherent interface language across portfolio, Work, monitoring, fiscal, team, administration, settings, commercial ERP management and company ERP. Preserve each domain's tasks, authority and state. Consistency includes useful table capabilities and predictable forms, not just similar borders and colors.

Confirmed by the user:

- Reuse existing components and make the system's interface consistent.
- Align equivalent compositions closely with `.dashboard` and official installed-version Nuxt UI examples.
- Add a deliberate assessment of sorting, filtering, row selection and bulk actions to operational tables that currently lack useful controls.
- Prefer `UModal` over sheets/slideovers for bounded creation and editing forms.
- Use expandable, grouped or tree tables when the data/task calls for them.
- Continue planning and mapping before changing product code.

This system-wide map provides the common proposal. The [ERP map](2026-10-26-erp-ui-standardization-map.md) and [ERP/reference differences](../../reviews/2026-10-26-erp-settings-reference-divergences.md) remain the commercial module's detailed application. The [cross-system audit](../../reviews/2026-10-26-system-ui-pattern-crosswalk.md) records current evidence and gaps.

The current root `DESIGN.md` describes the previous commercial ERP design. It must not be treated as a verified global design system. A future accepted implementation should document global rules and module-specific exceptions separately; planning does not rewrite those files.

## One language, task-specific compositions

| Composition | Common structure | Where it applies |
| --- | --- | --- |
| Operational list | Dashboard shell; shared heading and primary action; one search/filter/action toolbar; capable `UTable`; result count and paging/loaded-range feedback | Portfolio, administration, monitoring, fiscal lists, catalogs and operational ERP lists |
| Settings / form page | Centered reading measure; restrained naked heading; subtle grouped fields; useful separators; aligned labels/controls on desktop and stacked controls on mobile | Preferences, company configuration and longer forms |
| Record detail / workflow | Shared heading and context; labeled facts; clearly separated domain sections; explicit primary operation and history | Client/company records, subscription/invoice details, long editors and transactional workflows |
| Overview / report | Consistent heading, filters and time basis; meaningful metrics/charts/groups; drilldown to operational records | Portfolio dashboard, home, module hubs and financial/reporting summaries |

These are compositions of the same primitives, not four independent visual systems. Preserve legitimate differences in reading width, scroll ownership and table density. A dense task workspace should not inherit a profile form's width; a preference form should not stretch into a spreadsheet.

Use semantic colors, the current font/icon system, restrained headings, shared control sizes and consistent spacing by role. Validate global contrast/state overrides separately. Do not introduce colors, corner shapes or title styles per module. Do not copy demo endpoints, mock actions or authentication behavior from reference examples.

## Reuse and ownership

| Responsibility | Current reusable source | Plan |
| --- | --- | --- |
| Dashboard and navigation | `UDashboardPanel`, navbar/toolbar/sidebar and current route utilities | Preserve persistence, selected context and route membership; align common chrome |
| List heading/card/toolbar | `DataTablePanelList`, `panelCardUi`, `panelToolbarClass` | Preferred composition for equivalent panel lists |
| Dense scrollable table layout | `sheetTableUi`, `workTableUi`, `erpOperationalTableUi`, `pageShell.ts` | Compare intentional geometry and consolidate overlapping tokens; do not delete necessary scroll behavior |
| Table engine | Native Nuxt UI `UTable` / installed TanStack APIs | Configure capabilities; avoid a new all-purpose table engine |
| Sorting | `DataTableSortButton` | Standard trigger and state presentation; connect to the correct local/remote query model |
| Filters | `DataTableFilterPanel`, shared filter model and applied-filter controls | Standard controls, draft/apply semantics and mobile presentation |
| Column visibility | `DataTableColumnMenu` and current table visibility state | One recognizable control where useful |
| Record identity/details | `DataTableIdentity`, `DataTableMetaList` | Consistent identity and facts; essential information first |
| Per-row actions | `DataTableRowActionsMenu` | Consistent labels and secondary-action menu; preserve domain permissions |
| Selection feedback | `CustomersSelectionBar`, `WorkSelectionBar`, `WorkTarefasBulkBar` | Compare their common count/clear/action chrome; consolidate only shared presentation, retaining domain handlers and selection scope |
| Selection ownership | `useClientListSelection` and Work's leaf-selection helpers | Reuse the proven principles; do not substitute one domain's selection semantics for another |
| Forms and validation | `UForm`, `UFormField`, existing typed schemas and form components | Preserve parsing, validation, dirty state and authority ownership |
| Bounded form overlay | `UModal` and existing product modal forms | Default for new/refactored record editors under the user's preference |
| Domain workflows | Existing ERP, fiscal, portfolio and Work components/composables | Keep commercial/financial/fiscal semantics outside generic presentation components |

Source locations: [shared data-table components](../../../frontend/app/components/data-table/), [page measures](../../../frontend/app/utils/pageShell.ts), [Work grouping](../../../frontend/app/utils/workGroupedTable.ts), [ERP table styles](../../../frontend/app/components/erp/erpPresentation.ts), [portfolio selection](../../../frontend/app/composables/useClientListSelection.ts).

No new dependency is required. A new shared wrapper needs a demonstrated repeated intent and an interface not already covered by existing components. A common look does not justify centralizing unrelated domain logic.

## Operational-table capability contract

Each operational list gets a capability record. For every capability, record **present**, **missing and useful**, **not applicable with reason**, or **requires API/product work**. Do not hide gaps behind optional flags, and do not add nonfunctional controls merely to make every table look alike.

| Capability | Product rule |
| --- | --- |
| Identity and status | Stable record identifier, readable main identity and meaningful status; no raw enum or unnecessary repeated descriptions |
| Search | One accessible search control, clear query scope, appropriate debounce, ownership/cancellation and current-result feedback |
| Filtering | Domain-relevant fields, visible applied criteria, clear/reset behavior and preserved query/navigation state |
| Sorting | Usable headers for meaningful sortable fields; visible direction and deterministic order. Global paginated sorting belongs in the API query contract. Never present sorting of one loaded page as sorting the entire result set |
| Paging / loading more | Explicit strategy and accurate count/range. Keep intentional infinite loading and server pagination; a single policy is not required for every resource |
| Column visibility | Hide optional columns when valuable; retain identity, selection and necessary action controls |
| Row details | Native `#expanded` for additional facts/actions belonging to the same record; accessible trigger and no duplicated desktop/mobile business logic |
| Hierarchy | Grouped rows for derived aggregation; tree subrows only for genuine parent/child data. Preserve group totals, eligible leaves and disclosure state |
| Selection | Stable domain IDs through `getRowId`; explicit meaning of page/loaded rows/filter-matched leaves/all-matching server snapshot. Header checkbox communicates its scope and indeterminate state |
| Bulk actions | Offer only useful, authorized operations with a defined backend strategy, limits, confirmation when consequential, pending/result states and partial-failure handling |
| Feedback/accessibility | Loading, empty, stale, denied, error/retry and unknown outcomes; labels, keyboard operation, focus and responsive access to actions |

The feature contract is especially important for catalog, administrative and commercial ERP lists. Adding a sort trigger or selection checkbox is insufficient when the corresponding server behavior does not exist.

### Query and bulk semantics

- Declare each resource's query mode: complete bounded local dataset, server pagination, or incremental loaded dataset. Do not mix global-looking controls with page-local operations.
- Define allowlisted sort/filter fields and directions, stable tie-breaking and page-reset behavior. Dates and monetary values use domain-appropriate ordering, not localized display strings or lossy JavaScript numeric conversions.
- Keep selection tied to the selected Account/Company, current authority and the chosen query scope. Define whether it survives paging/sorting, and invalidate or revalidate when its filter snapshot or authority changes.
- In a hierarchy, group checkboxes select eligible entity leaves. Do not submit synthetic grouping IDs as task/record IDs.
- Distinguish a server bulk command from sequential single-record commands. Existing Work behavior may report independent successes/failures; it must not be presented as atomic.
- Preserve the portfolio's all-matching server snapshot model where it already exists. A local `rowSelection` map is not evidence that every server-side match has been selected.
- Do not enable bulk invoice issue/cancel/reversal, licence suspension, destructive administration or fiscal operations from visual consistency alone. Define the actual business action and API guarantees first.

## Expansion, grouping and tree data

| Need | Native Nuxt UI pattern | Current project evidence |
| --- | --- | --- |
| Reveal facts about one client/contract/document | `TableRowExpandableExample`, `#expanded`, `row.toggleExpanded()` | Office ERP client/subscription detail expansion |
| Aggregate flat tasks under client/process headers | `TableGroupedRowsExample`, grouping options and group/leaf APIs | Work Clientes and Processos through `workGroupedTableOptions()` |
| Display actual parent/child records | `TableTreeDataExample`, `getSubRows` / `:get-sub-rows="row => row.children"` | Official example verified; no local `TableTree` component or `getSubRows` implementation found in the audited current root |

The requested tree-table behavior is available through `UTable`; it does not require another library. Select the correct data model before implementing it. Do not turn flat invoices into an artificial tree just to expose details.

## Forms, modals and sheets

**Default:** use `UModal` for bounded record creation/editing. Use a page for a long workflow that benefits from stable navigation, several sections or substantial comparison. Retain the shared Settings/Profile field composition in both.

Modal contract:

- Named dialog and clear task title; concise description only where useful.
- Form state, field-level errors and validation remain inside the owning task. Focus the first relevant invalid control or error region.
- One main submit action, secondary cancel/close, useful grouping and predictable footer placement. Preserve form IDs when submit controls live in the modal footer.
- Responsive width and content scrolling; a large/fullscreen modal is permitted where justified, but is not a reason to move every page into an overlay.
- Preserve dirty-draft confirmation, busy-close guards, focus return, account/company changes and uncertain-operation recovery. Changing container must not change mutation or dismissal semantics.
- Inspect nested controls/dialogs during migration, especially product catalogs and archive/discard confirmations.

| Current slideover editor | Planned direction | Priority |
| --- | --- | --- |
| `OfficeErpPlanEditor` | Modal form; preserve revision/publication/module/recovery behavior | Early pilot |
| Admin subscription editor | Modal form; preserve busy/dismissal guard and validation | Early pilot |
| Admin plan editor | Wider scrollable modal form | After modal contract |
| `ErpCatalogResource` create/edit modes | Shared modal editor; this one host serves 13 catalog instances across nine page files | After focus/dirty/nested-dialog regression review |

`NotificationsSlideover`, mobile Inbox and the contextual fiscal document inspector are not the bounded record editors targeted by this preference. Review their containers separately if needed. The mobile filter `UDrawer` is a filter interface, not a registration form. `MonitoringSheet.vue` and `sheetTableUi` name table/page layouts, not form overlays; do not migrate them based only on the word "sheet".

## Cards and page hierarchy

- Reuse the Settings naked heading/subtle grouped-body pattern for equivalent list/forms. Keep card styling and action placement consistent through existing compositions.
- A card groups a meaningful task, preference section, summary or comparison. Do not put every row in a separate card on desktop, stack arbitrary nested borders, or replace an operational table with cards when sorting/comparison is the task.
- Reports and dashboards may legitimately use cards and charts; classify their purpose before consolidating styles. The current number of `UCard` declarations is inventory evidence, not a count of defects.
- Keep long-form tasks and record histories organized as pages where appropriate. Shared presentation must not erase different business sections, snapshots or audit records.
- Administration, office management and company ERP remain distinct authority contexts even when their visual controls match.

## System-wide migration waves

The wave table below preserves the planned sequence. The active OpenSpec task file and implementation capability ledger track completed work and explicitly pending acceptance; this roadmap is not a blanket delivery claim.

| ID | Wave | Outcome | Depends on |
| --- | --- | --- | --- |
| S0 | Baseline and decisions | Review this concept, the crosswalk, modal preference, table capability matrix and business exceptions; do not treat interrupted drafts as approved | None |
| S1 | Shared composition | Align existing heading, toolbar, card, field, spacing and action primitives; identify compatible token consolidation without changing every consumer at once | S0 |
| S2 | Table/API contracts | Fill capability records for each family; prioritize missing useful sort/filter/selection/bulk features and identify endpoint work separately | S0 |
| S3 | Bounded pilots | Validate one shared-panel list, the commercial clients/subscriptions expansion and one plan-editor modal; compare with Settings and official examples | S1, S2 |
| S4 | Shared catalog editor | Migrate the catalog create/edit host to modal with focus/dirty-state coverage; propagate its shared presentation to its catalog consumers | S3 |
| S5 | Operational tables | Migrate portfolio, Work, monitoring and fiscal with their existing selection/hierarchy semantics; add approved missing capabilities with matching APIs | S2, S3 |
| S6 | Administration and commercial ERP | Align admin/team and finish the commercial ERP module map, including form-modal candidates and financial exceptions | S3; per-resource API readiness |
| S7 | Company operations and reports | Review sales/purchase/stock/cash/finance/accounting/report tables individually; preserve posting, reconciliation, report basis and recovery | S2, S4 |
| S8 | Acceptance and guidance | Record paired reference comparisons, interaction/regression evidence, global design rules, surface exceptions and examples; remove obsolete wrappers only after consumer migration | Relevant consumer waves |

Do not run all waves in parallel. Shared components, table contracts and modal policy are sequential dependencies. After they are accepted, independent modules may be delegated with isolated worktrees and exclusive file ownership. Keep rollout reviewable and preserve existing dirty work.

## Acceptance and boundaries

For each migrated family, verify visual composition and actual operation: meaningful sorting/filtering across result pages; selected scope/count and eligible leaves; bulk pending/success/partial failure; expansion; modal validation/close/focus; mobile use; loading/empty/error/stale/denied/unknown outcomes.

Use real-length Portuguese text and synthetic but representative data, with long names, many modules, empty results and large exact amounts. Compare reference and product captures under the same theme, viewport, zoom, font readiness and sidebar state. Include the supplied 1046px content-panel case and phone widths. Do not infer fidelity from source inspection alone.

Use focused behavior tests while migrating, matching lint/typecheck/build, and integrated checks appropriate to the affected frontend/API contracts. No tests or runtime acceptance are claimed for this planning pass. Live fiscal/provider, PostgreSQL/concurrency and physical-device acceptance remain separate when applicable.

Planning changes are documentation only. No product code, API, dependency, environment, data, existing worktree draft or production gate is changed. The user's preference is recorded here; implementing new backend capabilities or migrating forms remains future work.
