# ERP UI Standardization Map

Date: 2026-10-26  
Workflow: Impeccable `shape`  
Status: Planning only; brief ready for review. No implementation is authorized by this document.

## Purpose and audience

Office operators need to register portfolio clients, activate ERP, and maintain subscriptions and billing on desktop and mobile. Success means completing those tasks through familiar, consistent controls, with essential information visible and details available on demand.

The user rejected the previous ERP composition and selected the local dashboard Settings pages as the visual reference. The priority is reuse of existing components and compositions, rather than another ERP-specific visual system. The interaction mode is Operate.

Follow-up direction: align as closely as practical with the template's actual composition, spacing, component variants and responsive hierarchy. Record business-specific exceptions rather than silently introducing another visual interpretation. The source-backed [reference divergence map](../../reviews/2026-10-26-erp-settings-reference-divergences.md) identifies D01–D14, affected files, preservation requirements and validation limits.

System-wide follow-up: the [common UI map](2026-10-26-system-ui-standardization-map.md) extends these principles across the application and records the user's preference for modal forms, capable operational tables and appropriate hierarchy/expansion. Apply this ERP map within that common proposal; do not create an ERP-only replacement design system. The [cross-system audit](../../reviews/2026-10-26-system-ui-pattern-crosswalk.md) distinguishes existing resources from frontend/API gaps.

## Confirmed direction and proposals

| Decision | Status |
| --- | --- |
| Use `.dashboard/app/pages/settings` as the composition reference | Confirmed by the user |
| Reuse shared components to keep the interface consistent | Confirmed by the user |
| Keep titles restrained and descriptions limited to useful guidance | Confirmed by the user |
| Keep registration, editing, and operation available on mobile | Confirmed by the user |
| Use the official Nuxt UI expandable-row pattern | Requested by the user |
| Prefer modal over slideover/sheet for bounded record forms, including the plan editor | Requested by the user in the system-wide follow-up |
| Present manageable plans as subtle horizontal cards with module disclosure | Proposed; review with the brief |
| Retain financial tables and the existing compact filters | Proposed composition, consistent with the preceding confirmed direction |
| Implement the redesign now | Not authorized; the user explicitly returned the work to planning |

The earlier operational-center plan and current `DESIGN.md` describe the previous delivered composition. They are historical evidence, not approval of this new design. This map records the proposed changes without rewriting those artifacts during planning.

## Visual authority and reusable inventory

References:

- [Settings shell](../../../.dashboard/app/pages/settings.vue): persistent dashboard header, route navigation, centered content.
- [Profile](../../../.dashboard/app/pages/settings/index.vue): naked heading/action card, subtle form body, aligned labels and controls, separators.
- [Members](../../../.dashboard/app/pages/settings/members.vue): search in the panel header and restrained record presentation.
- [Members list](../../../.dashboard/app/components/settings/MembersList.vue): compact identity and trailing controls.
- [Notifications](../../../.dashboard/app/pages/settings/notifications.vue): grouped preferences and aligned switches.
- [Security](../../../.dashboard/app/pages/settings/security.vue): short, task-specific form sections.

These files supply composition examples. Their sample endpoints, users, copy, placeholder actions, and commercial behavior are not implementation sources.

| Responsibility | Existing component or pattern | Intended treatment |
| --- | --- | --- |
| Dashboard shell | `UDashboardPanel`, `UDashboardNavbar`, `UDashboardToolbar`, `UNavigationMenu` | Retain persistent shell and route behavior |
| List heading, main action, toolbar, and body | `DataTablePanelList` | Preferred shared composition; first inspect its existing slots and consumers |
| Search and applied filters | `DataTableFilterPanel` | Reuse; preserve its mobile drawer and draft/apply behavior |
| Column visibility | `DataTableColumnMenu` | Reuse only where secondary columns are useful |
| Row actions | `DataTableRowActionsMenu` | Reuse for secondary actions; keep the primary task discoverable |
| Record identity | `DataTableIdentity` | One main name; secondary text only when it adds information |
| Expanded facts | `DataTableMetaList` | Labeled values without duplicating the collapsed row |
| Empty table | `DataTablePanelTableEmpty` | Reuse where compatible with existing empty/error distinctions |
| Table expansion | `UTable` and `#expanded` | Use native expansion state and accessible trigger controls |
| Plan presentation | `UPageCard` + `UCollapsible` | Proposed administrative cards with on-demand modules |
| Form groups | `UForm`, `UFormField`, `USeparator`, `UPageCard` | Follow Profile composition and existing validation |
| Short contextual help | `UPopover` | Optional explanations; do not hide required validation or critical feedback |
| Status and feedback | `UBadge`, `UAlert`, `USkeleton`, existing toasts | Same visual treatment for the same state |
| Page navigation | `OfficeErpPagination` over `USelect` and `UPagination` | Retain paging behavior; compare with other pagination consumers before further abstraction |
| Domain operations | `OfficeErpPlanEditor`, `OfficeErpReceiptModal`, `OfficeErpAdjustmentForm`, `OfficeErpReversalModal` | Keep commercial behavior and compose their presentation from shared controls; migrate the bounded plan editor from slideover to modal |

Shared source: [data-table components](../../../frontend/app/components/data-table/). Domain source: [office ERP components](../../../frontend/app/components/office-erp/).

`OfficeErpPanel` overlaps with `DataTablePanelList`. Decide whether to migrate its callers directly or keep a thin compatibility adapter after checking all slots and tests. Do not retain two independent visual implementations. `OfficeErpDataTable` also owns useful behavior; compare it with shared column controls and native table APIs before removing or changing it.

Existing Nuxt UI pricing components remain available for a future customer-facing plan comparison. They are not the proposed administrative plan editor/list composition. No new UI library is needed.

Official references: [Table expandable rows](https://ui.nuxt.com/docs/components/table#with-expandable-rows), [PageCard](https://ui.nuxt.com/docs/components/page-card), [Collapsible](https://ui.nuxt.com/docs/components/collapsible), [FormField](https://ui.nuxt.com/docs/components/form-field).

## Screen map

| Surface | Essential initial content | Details or secondary controls | Shared composition |
| --- | --- | --- | --- |
| `/gestao-erp` | Registration entry and confirmed commercial summary | Links to operational sections | Settings-style heading and restrained grouped content |
| `/gestao-erp/clientes` | Client name, concise subscription summary, activation/contract action | CNPJ, legal name, relevant dates, portfolio selection | `DataTablePanelList`, native expandable `UTable`, `DataTableIdentity`, `DataTableMetaList` |
| `/gestao-erp/assinaturas` | Client, concise plan/terms, licence situation | Company, agreement state, owner setup, dates, outstanding balance | Same expandable-table composition as clients |
| `/gestao-erp/planos` | Plan name, exact price/cycle, state, edit action | Modules, revision details, description, archive action | Proposed subtle `UPageCard` items with `UCollapsible` |
| `/gestao-erp/clientes/novo` | Client selection, technical plan, commercial terms, confirmation action | Lookup and optional owner access at the appropriate stage | Profile-style form groups; existing enrollment behavior |
| `/gestao-erp/assinaturas/[id]` | Identity and current subscription situation | Terms, owner access, licence operations, billing and history by section | Settings-style sections and `DataTableMetaList` |
| `/gestao-erp/faturas` | Invoice identification, status, due date and balances | Date criteria and secondary record actions | Shared panel, financial `UTable`, existing `DataTableFilterPanel` |
| `/gestao-erp/faturas/[id]` including `nova` | Document identity, editable fields when allowed, amounts and primary action | Adjustments and cancellation where authorized | Profile-style form groups and existing financial controls |
| `/gestao-erp/recebimentos` | Payment identification, date and exact value | Allocations, private proof and authorized reversal | Shared panel, financial table and existing domain dialogs |
| `/gestao-erp/configuracoes` | Trial-link controls and billing-policy fields | Optional explanations and existing policy preview | Profile/Notifications composition with aligned controls |
| `/gestao-erp/faturas/[id]/imprimir` | Existing printable document | None from this redesign | Excluded; preserve print bypass and output |

## Composition and interaction rules

- Use one familiar heading/action/toolbar composition across equivalent pages. Prefer existing slots and semantic Nuxt UI variants over page-specific markup and overrides.
- Keep the current Opmoni typography, semantic colors, Lucide icons, and light/dark preferences. Match the reference's composition and spacing for equivalent tasks; document each necessary operational exception. Keep the user's concise-copy preference instead of copying every demo description. Global theme overrides require a separate contrast/state review before changing them.
- Forms use a centered reading width; operational lists may use a wider existing page measure. Do not force financial tables into the Profile page's exact width. Confirm measures with real content at the target sizes before fixing shared values.
- On wide layouts, align field labels and controls as Profile does. On narrow layouts, stack them, keep values readable, and keep submit/recovery actions reachable.
- Expand a table row through the official `row.toggleExpanded()` / `row.getIsExpanded()` pattern and render its content in `#expanded`. Provide an accessible name and expanded state. Details follow the owning record; changing authority or results must not reveal stale details under another record.
- Keep only useful columns visible on narrow screens and expose displaced information in the same record's expansion. Resolve this once for clients/subscriptions rather than maintain separate desktop/mobile business logic. Financial tables may retain a labeled horizontal scroll region where comparison needs it.
- Preserve complete names in accessible details when compact identification truncates. Avoid repeated legal/trade names and stacked descriptions in the collapsed row.
- Keep the primary registration/activation action visible. Use shared row menus for lower-frequency actions; destructive actions retain their existing confirmation and justification.
- Assess sorting, filters, selection and useful bulk actions through the system-wide capability contract. Current commercial list validation does not accept sort params; backend/query work must precede global-looking sort controls. Do not infer bulk financial or licence operations from a selection checkbox.
- Use `UModal` for bounded record forms; preserve pages for longer enrollment/document workflows. Plan-editor migration must preserve recovery, pending state and focus behavior.
- Extract a new shared component only when at least three consumers share the same intent and existing components do not cover it. Similar appearance alone is not enough. Do not introduce a new generic form engine, table abstraction, or ERP design-token family.

## Ordered change map

All implementation tasks below are pending. This order is a migration roadmap, not a claim that the previous implementation or interrupted drafts completed it.

| ID | Wave | Change | Depends on | Completion evidence |
| --- | --- | --- | --- | --- |
| P0 | Baseline | Review this brief, reference divergences D01–D14 and the proposed plan-card presentation; record business exceptions and remaining decisions | None | Brief confirmed or corrected |
| P1 | Shared composition | Check `DataTablePanelList` / `OfficeErpPanel` interfaces, heading variants, shared spacing, and all affected consumers; resolve D01–D04 | P0 | One composition selected; compatibility and write boundaries documented |
| P2 | Shared interactions | Resolve native table expansion, identity/details, row actions, filter and pagination reuse | P1 | Accessible expansion, stable record ownership, no duplicate implementation |
| P3 | Client pilot | Migrate clients and subscriptions to that shared composition | P2 | Both lists work with long names, full paging and mobile expansion |
| P4 | Plans | Replace the administrative plans table with the reviewed card/disclosure composition | P1, P0 plan decision | Create/edit/archive/recovery preserved; modules readable on demand |
| P5 | Forms | Apply Profile composition to enrollment, subscription details and commercial configuration; migrate bounded plan editor to modal under the system-wide form contract | P1; P3 for enrollment handoff | Full mobile operation and existing validation/recovery/focus preserved |
| P6 | Finance | Apply the shared composition to invoice/receipt lists and invoice details | P2, P5 form pattern | Exact money, filters, private proofs and operation history preserved |
| P7 | Overview | Align summary and operation entry with the approved composition | P3–P6 | Clear primary task; no competing visual system |
| P8 | Acceptance | Run relevant regressions and one batched desktop/mobile review; apply a bounded correction batch if needed | P3–P7 | Evidence recorded against this brief, with remaining limits stated |
| P9 | Documentation | Update `DESIGN.md`, `.impeccable/design.json`, the ERP surface brief and examples to match the accepted result | P8 | Documentation describes the actual shipped composition |

Shared-file decisions happen before parallel page migration. If implementation is later authorized, independent page groups may use isolated worktrees with exclusive ownership; shared components are changed by one owner and integrated before dependent verification. Existing consumers outside ERP require compatibility checks. Other modules are follow-up candidates, not an approved system-wide rewrite.

## State and validation map

| Area | Cases required before acceptance |
| --- | --- |
| Layout | Desktop 1440px; user-reported 1046px content panel; intermediate 768px; mobile 375/390px; light and dark themes |
| Data ranges | No records, one record, several records, last page, 25/50/100 per page, long client/plan names, many modules, large valid monetary strings |
| Read states | Initial loading, refreshed results, failed read, retry, authoritative empty result, stale confirmed results |
| Authority | Selected Account change, session change, capability change, denied access, aborted old requests |
| Interaction | Keyboard expansion/collapse, visible focus, labels, row ownership after filtering/paging, mobile navigation and reachable actions |
| Enrollment | Existing portfolio client selection, search/page/size handoff, lookup failure, field errors, confirmed enrollment, optional owner access |
| Commands | Pending action, definitive failure, unknown outcome and recovery; no duplicate enrollment, invoice or financial mutation |
| Finance | Exact money and date presentation, invoice status transitions, receipt allocations/proofs, adjustments, reversal and print isolation |

Use the narrowest meaningful existing behavior tests for each change, then matching lint/typecheck/build checks. Update tests for changed interactions, not just markup. Run the complete frontend suite when integrating a completed implementation. No checks are run or claimed green for this new design during the planning task.

Browser acceptance must compare Settings references and migrated screens in a declared test session, using paired captures with the same theme, zoom, viewport and sidebar state. Check the D01–D14 reference differences and documented business exceptions. Synthetic API data must be labeled and kept separate from development records. Synthetic layout checks do not establish live backend, PostgreSQL, fiscal, provider, physical-device or Safari acceptance.

Initial API latency, generic hydration warnings and SSR icon-loading timeouts were previously observed; this map does not establish their cause or resolution. Performance diagnosis is a separately measured task if it remains necessary. Do not hide loading or cache authorization to make the visual redesign appear faster.

## Scope and preservation

- Preserve selected-Account/Company boundaries, all current API contracts, exact monetary strings, commercial eligibility, command identity/recovery and security rules.
- No backend, schema, dependency, environment, production-gate or development-data changes are part of this plan.
- The root checkout is `main` at `dac7293` at the time of this map. Untracked `reference/sistema/` is unrelated and must remain untouched.
- Interrupted drafts exist in `erp-settings-redesign` and `erp-settings-shell` worktrees; `erp-settings-forms` also exists. They are not approved results or validation evidence. Preserve them and reconcile their diffs against this map only after implementation is explicitly requested. Do not merge, reset, remove or silently resume them during planning.
- Planning writes are limited to this map and the linked new reference-divergence report. They do not modify `DESIGN.md`, the surface direction contract, components, tests, Git integration or previous review records.

## Review handoff

Review the shared-component choices, the plan-card proposal, and the wave order first. Implementation starts only after a separate explicit request. When it starts, turn the next wave into a bounded change with exact owned paths and relevant checks; use this map to keep the broader migration coherent.
