---
name: opmoni
description: Dashboard Operate Nuxt UI — carteira fiscal e Work mensal do escritório contábil
colors:
  primary: "#00C16A"
  primary-soft: "#00DC82"
  primary-deep: "#00A155"
  primary-ink: "#007F45"
  primary-tint: "#EFFDF5"
  surface: "#ffffff"
  surface-muted: "#fafafa"
  surface-elevated: "#f4f4f5"
  surface-accented: "#e4e4e7"
  surface-inverted: "#18181b"
  text-highlighted: "#18181b"
  text: "#3f3f46"
  text-muted: "#71717a"
  text-dimmed: "#a1a1aa"
  border: "#e4e4e7"
  border-accented: "#d4d4d8"
  info: "#3b82f6"
  warning: "#eab308"
  error: "#ef4444"
  success: "#00C16A"
  glass-light: "rgb(255 255 255 / 0.42)"
  glass-dark: "rgb(28 28 30 / 0.55)"
  theme-dark: "#1b1718"
typography:
  body:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "normal"
  title:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "normal"
  headline:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "normal"
  label:
    fontFamily: "Public Sans, ui-sans-serif, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 500
    lineHeight: 1.25
    letterSpacing: "normal"
rounded:
  xs: "0.25rem"
  sm: "0.375rem"
  md: "0.5rem"
  lg: "0.75rem"
  xl: "1rem"
  full: "9999px"
spacing:
  table-x: "0.75rem"
  table-th-y: "0.5rem"
  table-td-y: "0.375rem"
  group-indent: "1rem"
  panel-gap: "0"
  header-height: "4rem"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "#ffffff"
    rounded: "{rounded.sm}"
    padding: "0.5rem 0.875rem"
  button-primary-hover:
    backgroundColor: "{colors.primary-deep}"
    textColor: "#ffffff"
  button-neutral-outline:
    backgroundColor: "transparent"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
    padding: "0.5rem 0.875rem"
  button-status-soft:
    backgroundColor: "color-mix(in oklab, {colors.info} 12%, transparent)"
    textColor: "{colors.info}"
    rounded: "{rounded.sm}"
    padding: "0.25rem 0.5rem"
    height: "1.75rem"
  input-default:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text-highlighted}"
    rounded: "{rounded.sm}"
    padding: "0.5rem 0.75rem"
  chip-filter-active:
    backgroundColor: "color-mix(in oklab, {colors.primary} 12%, transparent)"
    textColor: "{colors.primary-ink}"
    rounded: "{rounded.sm}"
    padding: "0.375rem 0.625rem"
  chip-filter-idle:
    backgroundColor: "transparent"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
    padding: "0.375rem 0.625rem"
  card-page-subtle:
    backgroundColor: "{colors.surface-muted}"
    textColor: "{colors.text-highlighted}"
    rounded: "{rounded.lg}"
    padding: "1rem"
  selection-bar:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.text}"
    rounded: "{rounded.xl}"
    padding: "0.5rem 0.75rem"
  nav-sidebar:
    backgroundColor: "color-mix(in oklab, {colors.surface-elevated} 25%, transparent)"
    textColor: "{colors.text}"
    rounded: "{rounded.xs}"
  work-item-cell:
    backgroundColor: "transparent"
    textColor: "{colors.text}"
    rounded: "{rounded.sm}"
    padding: "0.375rem 0.75rem"
---

# Design System: opmoni

## Overview

**Creative North Star: "The Fiscal Workbench"**

opmoni is an Operate dashboard: dense, scannable, and deliberately quiet. The visual system comes from the Nuxt UI dashboard shell (primary green on zinc neutrals, Public Sans, tonal surfaces) with product-specific density for Carteira and Work. Brand expression lives in precise Portuguese labels, status color mapping, and table rhythm — not in marketing heroism or decorative chrome.

The workbench favors continuous surfaces over cards-as-decoration. Sidebar, navbar, and toolbars frame full-bleed panels; tables and boards carry the content. Grouped Work rows use a subtle elevated strip and progressive indent so hierarchy reads without heavy borders. Carteira deadline state and Work task status share the same Nuxt semantic palette (info / warning / success / error / neutral) so operators learn one color language across both fronts.

Light and dark modes are first-class via Nuxt color mode. Calendar chrome alone adds frosted glass (`glass-material`); everywhere else depth is tonal (`bg-elevated`, rings) with lift reserved for floating selection bars.

**Key Characteristics:**
- Operate density: compact table padding, `table-fixed`, merged **Item** column
- Single accent: Nuxt green primary on zinc neutrals
- Semantic status colors for tasks, deadlines, and support banner
- Portuguese UI vocabulary baked into component labels (A fazer, Cascata, Sem cadastro)
- Floating selection bars (`rounded-xl` + `shadow-lg` + ring) as the shared bulk-action pattern

## Colors

A utilitarian Operate palette: one green accent, zinc surfaces, and Nuxt semantic roles for state.

### Primary
- **Ledger Green** (#00C16A / `green-500`): Default primary actions, active filter chips, process-ratio badges, chart strokes, MetricCard `brand` icon wells (`bg-primary/10` + `ring-primary/25`). Soft hover/emphasis neighbors: #00DC82 (`green-400`), #00A155 (`green-600`), #007F45 (`green-700`). Tint well: #EFFDF5 (`green-50`).

### Neutral
- **Paper White** (#ffffff): Page and panel background (`--ui-bg` light).
- **Zinc Mist** (#f4f4f5 / `zinc-100`): Elevated surfaces, group-row strips (`bg-elevated/50`), count pills on selection bars.
- **Zinc Hairline** (#e4e4e7 / `zinc-200`): Default borders and rings (`border-default`, `ring-default`).
- **Zinc Body** (#3f3f46 / `zinc-700`): Default body text (`text` / `--ui-text`).
- **Zinc Ink** (#18181b / `zinc-900`): Highlighted labels, group names (`text-highlighted`); dark-mode canvas.
- **Zinc Mute** (#71717a / `zinc-500`): Secondary copy, empty leaves, muted dashes (`text-muted`).

### Semantic (Nuxt UI roles)
- **Info Blue** (#3b82f6): Task status **A fazer** (`todo`).
- **Warning Yellow** (#eab308): **Em progresso**, cascade badge, support-mode banner, deadline “a vencer”.
- **Success Green** (#00C16A): **Concluída**, deadline **Válido** (aliases the primary green ramp).
- **Error Red** (#ef4444): **Urgente** priority, expired documents, destructive/alert affordances.
- **Neutral soft**: **Dispensada**, missing cadastro, idle outline controls.

### Named Rules
**The One Accent Rule.** Primary green is the only brand accent. Do not introduce a second brand hue; use Nuxt semantic colors only for state.

**The Status Is Semantic Rule.** Task and deadline meaning always rides Nuxt `color` props (`info` / `warning` / `success` / `error` / `neutral`) — never ad-hoc hex in leaf components.

## Typography

**Display Font:** none (Operate shell; no marketing display face)
**Body Font:** Public Sans (with ui-sans-serif, system-ui, sans-serif)
**Label/Mono Font:** Public Sans; numeric order/dates use `tabular-nums`

**Character:** A single geometric sans for the whole product. Hierarchy is weight and size, not a second family. Uppercase appears only in MetricCard `brand` titles (`text-xs uppercase`), not as a global pattern.

### Hierarchy
- **Headline** (600, ~1.5rem / `text-2xl` on KPI values): Large tabular figures on MetricCard and home stats.
- **Title** (600, ~1.125rem): Dashboard navbar titles, group row names (`font-semibold text-highlighted`).
- **Body** (400, 1rem): Default copy, table leaf titles, form fields.
- **Label** (500–600, 0.75rem / `text-xs`): Table headers (`th` in Work), filter chips, soft status buttons (`size="xs"`).

### Named Rules
**The Tabular Numbers Rule.** Order prefixes (`N.`), counts, dates, and KPI figures use `tabular-nums` so columns align while scanning.

**The A fazer Rule.** Status label for `todo` is always **A fazer** (never “Aberto” or English). Color is `info`.

## Layout

Shell: `UDashboardGroup` + collapsible/resizable `UDashboardSidebar` (`bg-elevated/25`) + `UDashboardPanel` bodies with zero padding (`p-0`) so tables and boards go edge-to-edge under navbar + toolbar.

Work and Carteira share the panel chrome pattern: `UDashboardNavbar` + `UDashboardToolbar` with `UNavigationMenu` tabs (`highlight`). Work month-scoped views keep `WorkReferenceMonthPicker` on the toolbar right; page actions teleport into `#work-toolbar-actions`.

**Work grouped tables (Clientes / Processos):**
- Shared chrome in `workTableUi`: `min-w-full table-fixed`, compact `th` (`px-3 py-2 text-xs`) and `td` (`px-3 py-1.5`).
- Single primary column **Item**: groups show client or process name; leaves show `N. Título` with progressive indent (`1rem` × depth).
- Group rows: `bg-elevated/50` strip via `workGroupedTableMeta`; dense ± expand control (`size-6`); expand state preserved (`autoResetExpanded: false`).
- Process groups may show subtle ratio + optional **Cascata** badges (`variant="subtle"`).

**Carteira:** Status chip rows (`StatusChips`) for document situation filters; document cells use `DocumentStatus` (badge or outline action). Selection uses the same floating bar language as Work.

**Admin / Equipe:** the panel list page — `DataTablePanelList` over `panel.ts` chrome. The header card is a naked horizontal `UPageCard` at `mb-4` with its one action pushed right by `w-fit lg:ms-auto`; the panel body is unpadded by default and the page applies `panelBodyClass`, so a panel that swaps a list for a table (Admin › Suporte) owns its own padding.

**Spacing rhythm:** Tailwind scale; signature densities are the Work table paddings and `gap-1` / `gap-1.5` inside Item cells. Header height token: `--ui-header-height: 4rem`.

### Named Rules
**The Full-Bleed Panel Rule.** Dashboard panel bodies stay `p-0`; padding belongs inside toolbars, modals, and card interiors — not as a second frame around tables.

**The Merged Item Column Rule.** Grouped Work lists keep one **Item** column for group labels and leaf titles; do not split name/title into separate visual columns.

## Elevation & Depth

Default depth is tonal, not shadowed. Sidebar wash, elevated group strips, muted backgrounds, and `ring ring-default` define layers. Shadows appear for floating chrome that must clear the table plane.

### Shadow Vocabulary
- **Selection lift** (`box-shadow: var(--shadow-lg)` via Tailwind `shadow-lg` + `ring ring-default`): Work bulk bars and Carteira `SelectionBar` — fixed bottom, centered, `rounded-xl bg-default`.
- **Glass calendar** (`backdrop-filter: blur(24–28px) saturate(150–180%)`): `/work/calendario` header/chrome only (`glass-material`, `--glass-bg`). Respects `prefers-reduced-transparency`.

### Named Rules
**The Flat-By-Default Rule.** Surfaces stay flat at rest. Lift (`shadow-lg`) is reserved for floating selection/action chrome, not for ordinary cards or table rows.

## Shapes

Base radius token is Nuxt’s `--ui-radius: 0.25rem` (4px). Product UI commonly steps up: `rounded-md` on controls and count pills, `rounded-lg` on segmented toggles and list shells, `rounded-xl` on selection bars and Work task/board cards, `rounded-full` on calendar chips, MetricCard icon wells, and avatar dots.

Borders are hairline zinc (`border-default` / `ring-default`). Group hierarchy is indent + elevated strip, not left accent bars or heavy dividers.

### Named Rules
**The Soft Chip Rule.** Status and deadline meaning uses soft/subtle badges and soft buttons — not solid primary blocks — so color stays scannable at density.

## Components

### Buttons
- **Shape:** gently rounded (`rounded-md` / Nuxt default on `UButton`)
- **Primary:** solid Ledger Green for rare confirmatory actions
- **Neutral outline / ghost:** default Operate chrome (toolbar, selection bar, expand ±, back)
- **Status soft (`WorkTaskStatusSelect`):** `size="xs"` `variant="soft"` colored by status; menu omits the current value; trailing chevron; min width ~7rem. Loading spinner is not the busy affordance for per-row status (busy lives on bulk bar / dismiss modal)
- **Hover / Focus:** Nuxt UI defaults; calendar detail links use `focus-visible:outline-primary`

### Chips / Badges
- **Filter chips (`StatusChips`):** active = `primary` + `soft`; idle = `neutral` + `outline`; trailing `UKbd` count
- **Deadline (`DocumentStatus`):** subtle badge with semantic color + icon; missing/expired actionable states become outline buttons (Cadastrar / Atualizar)
- **Cascade:** subtle `warning` badge, only when cascade is enabled

### Cards / Containers
- **MetricCard:** `UPageCard` `variant="subtle"`; strip joins with `lg:rounded-none first:rounded-l-lg last:rounded-r-lg`; `quiet` (neutral icon well) vs `brand` (primary tint well)
- **Work task card / board tile:** `rounded-xl bg-default p-3 ring ring-default`
- **Lists in modelos editor:** `rounded-lg ring ring-default` with `divide-y`

### Inputs / Fields
- Nuxt UI field defaults on zinc surfaces; modal footers use compact end-aligned actions (`gap-1.5 p-4 sm:px-6`)
- Dense tables prefer soft status dropdowns over per-row `USelect` for performance

### Navigation
- Vertical `UNavigationMenu` in sidebar with Lucide icons; Work/Clientes/Equipe/Admin as expandable triggers
- Section toolbars: horizontal menus with `highlight`
- Collapse control + Teams menu in sidebar header; UserMenu in footer

### Selection bar (signature)
Floating bottom bar shared by Carteira and Work: count pill (`bg-elevated`, tabular), muted noun in Portuguese, outline/ghost actions, optional warning **Dispensar**. Do not reinvent per-view chrome.

### Work grouped Item cell (signature)
Indent spacer → ± expand (groups) → **semibold** group label or `N. Título` leaf → optional subtle badges. Empty process leaf: muted “Nenhuma tarefa neste processo”.

### Support banner
Fixed top `UBanner` `color="warning"` while support mode is active; pushes shell with `pt-12`.

### Page shell (roots)
A dashboard page body has three shapes and no shared name for any of them, so `app/utils/pageShell.ts` names them:

| Token | Shape | Used by |
|---|---|---|
| `pageScrollClass` | one scrolling column | Monitoramento, Work › Modelos, Work › Tarefas, Clientes › Painel |
| `pageTableClass` | scrolling column hosting a table that owns its own scroll; `relative` anchors the floating selection bar | Work › Clientes, Work › Processos |
| `pageRecordScrollClass` + `pageDetailClass` | centred record page, capped at `max-w-6xl` | Termo de autorização, Execução, Cliente |

`sheetBodyClass` in `components/data-table/sheet.ts` is a fourth, separate token: the windowed data-table sheet.

### Panel list page (signature)
`DataTablePanelList` + `app/components/data-table/panel.ts`. The bordered-card list page: a naked horizontal header card (title, description, one `#action`) above a `UPageCard` whose header slot is the `#toolbar`. Shared by the six Admin lists and the two Equipe lists.

Chrome lives in `panel.ts` and is imported, never retyped: `panelTableUi`, `panelCardUi`, `panelToolbarClass`, `panelBodyClass`, `panelFooterClass`, `panelFooterCountClass`, `panelPaginationClass`, `panelSelectUi`. `DataTablePanelTableEmpty` is the `#empty` body of a `panelTableUi` table.

`sheetTableUi` and `panelTableUi` are different tokens on purpose: the sheet is a windowed table with `text-sm` cells and its own scroll root, the panel is a bordered card table with hairline row rules.

### ErrorRetryAlert
`app/components/ErrorRetryAlert.vue`. The one fatal-load surface: `subtle` error alert, what could not be loaded, the recovery sentence, a solid retry action. `v-if` stays with the caller — whether a failure is fatal to the page is a page decision. The recovery wording and the retry label live only here; they had already drifted per page.

`useRetryableLoad` (composable) owns the failure contract around it: the load toast, the refresh toast, `showError`, and `retry` (which clears the failure before re-running). `ignoreStatus: 404` marks "not shipped yet" as inert on the monitoring screens; `sticky: true` keeps a fatal alert up until the operator presses its own retry, which `useAsyncData`'s `error` ref would otherwise clear out from under them.

### MetaList (read-only facts)
`app/components/data-table/MetaList.vue` + `app/utils/metaList.ts`. Key → value `dl` for recorded facts (vencimento, ciência, série, situação). `layout="grid"` puts the label above the value; `layout="stack"` puts them on one line, label left and value right. Per item: `mono` (tabular figures — dates, counts, ids), `truncate`, `tone`, `when: false` (drop the row instead of an `v-if` at the call site).

### RowActionsMenu
`app/components/data-table/RowActionsMenu.vue`. The trailing row-overflow menu: end-aligned `UDropdownMenu` behind a neutral ghost ellipsis button. `flush` adds the table-cell wrapper (`div.text-right` + `ml-auto`). `label` is required and must name the row — the trigger was unlabelled at three call sites before this.

### Work facet filters
`app/utils/workFacetFilters.ts` backs the Clientes and Processos filter bars. A facet is a column: `workFixedFacetColumn` (status, cascata, status do processo — a fixed list narrowed to what the leaves hold) or `workValueFacetColumn` (cliente, departamento, processo — the distinct values in the leaves). `workFacetChoices` never drops a column an operator has already picked from. `workClientesFilters.ts` / `workProcessosFilters.ts` decide *which* facets a table offers and what its search box promises to find; they do not re-derive the plumbing.

## Do's and Don'ts

### Do:
- **Do** reuse `workTableUi`, `workGroupedTableMeta`, `workExpandedOptions`, and `workDepthIndentStyle` for any new grouped Work table.
- **Do** map task status through `statusPresentation` / `WorkTaskStatusSelect` so labels and colors stay consistent (**A fazer** = info).
- **Do** keep selection bulk actions on the floating bar pattern (`rounded-xl bg-default shadow-lg ring ring-default`).
- **Do** use Nuxt semantic `color` props for deadlines and priorities (`portfolioLabels`, `priorityPresentation`).
- **Do** prefer tonal elevation and rings; reserve `shadow-lg` for floating chrome.
- **Do** import panel/page/meta chrome from `panel.ts`, `pageShell.ts`, `metaList.ts`; a retyped copy is a second thing to keep in sync.
- **Do** use `DataTableMetaList` for read-only facts and `DataTableRowActionsMenu` for row overflow, so both stay consistent across surfaces.

### Don't:
- **Don't** invent a second brand accent or replace Public Sans with a display serif for dashboard surfaces.
- **Don't** label `todo` as “Aberto”, “Open”, or “Todo” in the UI.
- **Don't** add decorative cards around full-bleed Operate tables or reintroduce phantom horizontal scroll (`min-w-max`) on Work tables.
- **Don't** reset group expand state on every data refresh (`autoResetExpanded` must stay false).
- **Don't** extend frosted glass beyond calendar chrome without an explicit product decision.
- **Don't** treat `.ref/` TaskHub screenshots as copy-paste authority — match incumbent opmoni tokens and patterns first.
- **Don't** hand-roll a `UAlert` retry block, a `dl` fact grid, a page root, or a panel table's `:ui` — those are the extracted components above.
- **Don't** leave a `DataTableRowActionsMenu` without a `label`; an unlabelled icon button is announced as "button".
