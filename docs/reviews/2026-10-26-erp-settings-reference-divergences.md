# ERP / Settings Reference Divergence Map

Date: 2026-10-26  
Baseline: root checkout `main`, commit `dac7293`  
Status: planning evidence; no implementation or visual acceptance

## Objective and method

Bring ERP management as close as practical to the local `.dashboard` Settings template while preserving office operations. Reference fidelity means matching the template's component compositions, hierarchy, spacing, alignment and responsive behavior wherever the task is equivalent. Each business-specific exception must be explicit.

The subsequent [system-wide crosswalk](2026-10-26-system-ui-pattern-crosswalk.md) and [common UI map](../superpowers/plans/2026-10-26-system-ui-standardization-map.md) extend this analysis: modal is preferred for bounded forms, and table capability gaps must be reviewed together with API support. This ERP comparison remains source evidence for that broader proposal.

Two isolated read-only comparisons covered shared structure and domain forms. The coordinator checked their findings against current root sources, theme configuration and existing ERP production-preview captures. This is a scoped reference comparison supporting the planning brief, not a complete Impeccable heuristic critique, detector audit or pixel-diff result.

Both comparison workers were requested as `gpt-6-luna` at medium effort; actual runtime routing telemetry was unavailable.

The existing captures show synthetic ERP data from the previous isolated acceptance run. No paired rendered Settings capture was produced in this task. CSS classes and component usage below are source-confirmed; exact rendered gaps, contrast, clipping and pixel fidelity still require paired browser validation. No fidelity percentage or runtime defect severity is inferred from source differences.

Interrupted worktree drafts were excluded. Their contents are not the baseline and must not silently become the proposed result.

## What already matches

- Both applications use Nuxt UI 4.11.3, Public Sans, the same declared green palette, zinc neutrals and Lucide-based controls.
- Both use the dashboard panel/navbar/toolbar composition. The ERP shell is not a reason to introduce another layout framework.
- `DataTablePanelList` already provides the reference's naked heading plus subtle content card and toolbar slot.
- Invoice and receipt pages already reuse the compact filter panel; their financial criteria do not need a new filter interface.
- ERP client/subscription desktop tables already support native row expansion. The remaining difference is their presentation and the separate mobile implementation.
- Current ERP headings are already restrained in several pages. The broad claim that every heading needs resizing would be incorrect.

## Divergences and alignment decisions

Priority means migration order, not a claim of functional breakage. **A** = align with the reference; **E** = retain an explicit business exception; **V** = decide through paired rendered validation.

| ID | Priority / treatment | Reference versus current ERP | Planned alignment and preserved business intent |
| --- | --- | --- | --- |
| D01 | First / A + E | Reference shell uses `lg:max-w-2xl`; ERP shell allows `max-w-[100rem]`, while enrollment, subscription details, invoice details and settings separately use `max-w-5xl` | Match the centered Settings measure for equivalent forms/preferences. Define one deliberate wider operational-list measure for records and financial comparisons; avoid unrelated widths per page. Do not impose a Profile-sized table on invoices. |
| D02 | First / A + V | Reference body uses `lg:py-12` and a shared `gap-4 sm:gap-6 lg:gap-12` container; ERP shell sets `p-4 sm:p-6 lg:p-8`, with page-level `gap-5`, `gap-6` and additional padded panel bodies | Select spacing through the shared composition and reference section hierarchy. Measure heading-to-card, toolbar-to-record and section gaps together; remove accidental double padding, without assuming every reference gap belongs inside a dense table. |
| D03 | First / A | Settings and Members use `UPageCard variant="naked" orientation="horizontal"` for title/action. ERP mixes `OfficeErpPanel`'s manual header, raw page headers and section-specific title markup | Use `DataTablePanelList` for equivalent lists and the native naked PageCard pattern for forms. Inspect the existing ERP adapter's slots before migrating or reducing it; do not maintain two independent header systems. |
| D04 | First / A | Reference form/list bodies use `UPageCard variant="subtle"`. ERP lists manually draw a rounded border/default background; settings and invoice details use `UCard` headers/body/footer | Adopt the reference's subtle PageCard composition where the task matches. Keep intentional financial and policy groups; translate their presentation rather than delete the groups or change form submission. |
| D05 | Next / A + V | Members places a full-width search directly in the card header and records directly below. ERP client/subscription toolbars add a visible search label, then a separately padded table frame; other tables add a second column-control toolbar | Consolidate the toolbar through the shared panel and filter components. Search may rely on a visible placeholder plus an accessible label when unambiguous. Keep needed filters, counts and column controls, but group them in the existing toolbar instead of adding another visual band. |
| D06 | First / A + E | Profile aligns label/description on the left and control on the right, stacks below `sm`, and inserts `USeparator`. ERP enrollment, plan editor and invoice draft forms predominantly use vertical fields inside two/three-column grids; settings mixes vertical fields with one horizontal switch | Follow Profile/Notifications field rows for scalar preferences and commercial terms. Keep a deliberate repeated-item layout for invoice lines and any paired data that must be compared together. Preserve current field names, errors, parsing and submit behavior. |
| D07 | Next / A | Reference Notifications repeats a naked section heading followed by one subtle grouped body. Subscription details use many hand-authored `border-t` sections, local grids and individually bordered history entries | Recompose terms, owner access, trial conversion, licence actions and history with the same section rhythm and reusable fact presentation. Keep each operation distinct and available only in its authorized state. |
| D08 | Next / A + E | Members has one main identity, optional secondary text and compact trailing controls. ERP defines names, metadata grids and action groups separately in client, contract, plan and document cells | Reuse `DataTableIdentity`, `DataTableMetaList` and `DataTableRowActionsMenu` where their interfaces fit. Keep essential status/amounts visible, and move legal name/CNPJ/dates/revision details into expansion. Do not remove distinguishing company information merely because the demo has fewer fields. |
| D09 | Next / A + E | Reference contextual action belongs to the page heading. ERP also offers a global registration shortcut on most routes, local update/create actions and an overview activation entry | Keep one clear action hierarchy: page-specific primary action in its heading, secondary actions neutral/menu-based, registration easy to find. Assess the global shortcut separately from local invoice/receipt actions; they perform different tasks. Do not remove necessary actions just to match the demo's button count. |
| D10 | First / A + E + V | Members uses one responsive record composition. ERP compact tables are hidden below `md` and replaced with separately authored `#mobile` collapsibles, duplicating fields/actions; normal tables use horizontal scrolling | Use the requested official `UTable #expanded` interaction consistently for clients/subscriptions, with essential narrow-screen columns and displaced facts in expansion. Preserve record-owned state and all mobile actions. Financial comparison may retain a labeled horizontal scroll region. Test this at the supplied panel width and phone widths before accepting it. |
| D11 | Next / proposal + E | Settings has no administrative plan-table equivalent. Current plans use a manual outer section, a multi-column table, module popover, revision/publication labels and edit/archive controls | Review the proposed subtle horizontal PageCard plus Collapsible composition. It should show name, exact price/cycle and status, with modules/revision details on demand. This is a business-specific extension of the Settings vocabulary, not an exact template reproduction. Preserve status filtering, pagination, revisions, edit/archive permissions, confirmation and recovery. |
| D12 | Preserve / E | Members has no counted server pagination, financial filter set, document balances, receipt allocation or private proof controls. ERP contains all of these | Keep the financial tables, `DataTableFilterPanel`, counted paging and domain dialogs. Standardize their surrounding header/card/control treatment. Do not substitute the template's demo member list or local filtering for current server criteria. |
| D13 | Review separately / V | Declared palette and font match, but ERP globally overrides light primary/success/warning roles to shade 800, muted/dimmed text roles, and primary/success button states. Those overrides are absent from the reference config/CSS | Record this as actual theme divergence, not a new palette. Compare light/dark rendered states and contrast before any change. Global removal would affect other modules and overlays and is outside an ERP-only layout change; retain justified accessibility behavior. |
| D14 | Next / A + E | Reference examples use persistent descriptions and mock success handling. ERP contains both removable explanatory copy and necessary permission, recovery, stale-result, trial, owner-access and financial-effect messages | Follow the user's short-copy preference rather than copy every reference description. Keep factual field help, validation, unknown-outcome recovery and consequential action explanations visible at the right point. Optional help may use Popover. Do not add decorative subtitles or hide business warnings for visual similarity. |

## Source evidence index

Line numbers are from the baseline above. Links point to the actual local source, not interrupted worktree files.

| Findings | Reference evidence | ERP / reusable evidence |
| --- | --- | --- |
| D01–D02 | [Settings shell](../../.dashboard/app/pages/settings.vue), lines 30 and 45 | [ERP shell](../../frontend/app/pages/gestao-erp.vue), lines 50 and 87; [enrollment](../../frontend/app/pages/gestao-erp/clientes/novo.vue), line 568; [settings](../../frontend/app/pages/gestao-erp/configuracoes.vue), line 480 |
| D03–D04 | [Profile](../../.dashboard/app/pages/settings/index.vue), lines 57 and 73; [Members](../../.dashboard/app/pages/settings/members.vue), lines 17 and 31 | [OfficeErpPanel](../../frontend/app/components/office-erp/OfficeErpPanel.vue), lines 6 and 20; [PanelList](../../frontend/app/components/data-table/PanelList.vue), lines 40 and 51; [invoice details](../../frontend/app/pages/gestao-erp/faturas/[id]/index.vue), line 178 |
| D05 | [Members](../../.dashboard/app/pages/settings/members.vue), line 32 | [Clients](../../frontend/app/pages/gestao-erp/clientes/index.vue), line 205; [subscriptions](../../frontend/app/pages/gestao-erp/assinaturas/index.vue), line 145; [table wrapper](../../frontend/app/components/office-erp/OfficeErpDataTable.vue), line 37; [panel tokens](../../frontend/app/components/data-table/panel.ts), line 27 |
| D06 | [Profile](../../.dashboard/app/pages/settings/index.vue), lines 74, 79 and 86; [Notifications](../../.dashboard/app/pages/settings/notifications.vue), line 55 | [Enrollment](../../frontend/app/pages/gestao-erp/clientes/novo.vue), lines 626 and 887; [plan editor](../../frontend/app/components/office-erp/OfficeErpPlanEditor.vue), line 172; [invoice draft](../../frontend/app/pages/gestao-erp/faturas/[id]/index.vue), lines 181 and 192; [settings](../../frontend/app/pages/gestao-erp/configuracoes.vue), lines 695 and 709 |
| D07 | [Notifications](../../.dashboard/app/pages/settings/notifications.vue), lines 48 and 55 | [Subscription details](../../frontend/app/pages/gestao-erp/assinaturas/[id].vue), lines 473, 501, 609 and 695 |
| D08–D09 | [Members list](../../.dashboard/app/components/settings/MembersList.vue), lines 20, 32 and 50; [Profile action](../../.dashboard/app/pages/settings/index.vue), line 64 | [Client details](../../frontend/app/pages/gestao-erp/clientes/index.vue), line 304; [subscription details](../../frontend/app/pages/gestao-erp/assinaturas/index.vue), line 237; [shared identity](../../frontend/app/components/data-table/Identity.vue); [shared metadata](../../frontend/app/components/data-table/MetaList.vue); [row menu](../../frontend/app/components/data-table/RowActionsMenu.vue); [global shortcut](../../frontend/app/pages/gestao-erp.vue), line 55 |
| D10 | [Members list](../../.dashboard/app/components/settings/MembersList.vue), line 24 | [Table wrapper](../../frontend/app/components/office-erp/OfficeErpDataTable.vue), lines 61 and 87; [clients mobile](../../frontend/app/pages/gestao-erp/clientes/index.vue), line 347; [subscriptions mobile](../../frontend/app/pages/gestao-erp/assinaturas/index.vue), line 297 |
| D11–D12 | [Members composition](../../.dashboard/app/pages/settings/members.vue), line 31 | [Plans](../../frontend/app/pages/gestao-erp/planos.vue), lines 377, 403 and 487; [invoice filters](../../frontend/app/pages/gestao-erp/faturas/index.vue), line 185; [receipt filters](../../frontend/app/pages/gestao-erp/recebimentos.vue), line 262; [pagination](../../frontend/app/components/office-erp/OfficeErpPagination.vue), line 8 |
| D13 | [Reference theme config](../../.dashboard/app/app.config.ts); [reference CSS](../../.dashboard/app/assets/css/main.css), line 4 | [Product theme config](../../frontend/app/app.config.ts), line 7; [product CSS](../../frontend/app/assets/css/main.css), lines 20 and 29 |
| D14 | [Profile descriptions](../../.dashboard/app/pages/settings/index.vue), line 77 | [Enrollment success/access](../../frontend/app/pages/gestao-erp/clientes/novo.vue), lines 579 and 617; [invoice recovery](../../frontend/app/pages/gestao-erp/faturas/[id]/index.vue), line 169; [policy preview](../../frontend/app/pages/gestao-erp/configuracoes.vue), line 735 |

## Business invariants

| Keep explicit | Why the template cannot replace it |
| --- | --- |
| Office client registration and ERP activation | The reference manages a generic profile/member, not portfolio enrollment and first-invoice creation |
| Account, portfolio Client and ERP Company identities | These have different authority and tenancy meanings |
| Agreement state, licence state and owner access | They answer different operational questions and must not be merged into one decorative status |
| Exact price, cycle, dates, balances and issued snapshots | These are commercial and financial facts, not display-only demo values |
| Unknown-outcome recovery and stale confirmed results | Removing them can encourage duplicate commands or present outdated results as current |
| Trial-link scope, policy preview, manual holds and security suspensions | Their consequences must remain understandable before changing commercial policy |
| Private receipt proofs and justified financial reversals | The reference contains no equivalent access or audit constraints |
| Printable invoice output | It remains outside the dashboard composition |

## Alignment order and acceptance

1. Resolve D01–D04 through the existing shared panel/Settings compositions. Align containers, headings and cards before changing the contents of every page.
2. Resolve D05, D08 and D10 in the clients/subscriptions pilot: shared toolbar, concise identity, accessible native expansion and complete mobile actions.
3. Review D11 and implement the selected plan presentation only after that decision is recorded.
4. Apply D06–D07 to enrollment, commercial configuration and subscription details. Preserve form semantics and financial decision points.
5. Align financial list/detail surroundings under D09, D12 and D14, retaining existing business controls.
6. Treat D13 as a separate shared-theme decision supported by contrast/state evidence. Do not expand a page migration into an unreviewed global theme rewrite.

For paired visual acceptance, capture a reference page and its equivalent ERP page under the same theme, zoom, font readiness, viewport and sidebar state. Compare content measure, heading/action alignment, section gaps, card surfaces, field alignment, dividers and control variants. Also inspect the user's 1046px content-panel case and phone widths. A meaningful ERP exception is accepted only when documented with its business purpose and checked for responsiveness.

Use realistic synthetic office data, long names, several modules, empty/error/loading states and large valid amounts. The reference's list of one generic member does not establish handling of server pagination or financial state. Record resolved/remaining differences; do not claim a percentage match without paired evidence.

## Changes made by this task

Only this report and its linked planning map are written. No components, styles, APIs, tests, environments, worktree drafts, Git integration or prior review evidence are changed. No new runtime/browser/test validation was performed.
