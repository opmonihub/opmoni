# ERP shared theme and proportion alignment plan

Status: planned, not implemented. Requested extension to the [template consistency audit](report.md). Scope: `frontend/app/pages/erp`, its consumed components, and the shared theme/shell files responsible for their presentation. V07–V09 additionally cover the requested forms, native initial setup and reusable tax-configuration contracts, detailed in the [forms and defaults plan](forms-and-defaults-plan.md). No application styles or behavior were changed while preparing this plan.

## Outcome and visual authority

ERP must use the same visual system as `.dashboard` and established Opmoni surfaces: green/zinc semantic colors, Public Sans, native Nuxt UI proportions, comparable task density, and consistent light/dark states. The work includes formatting and component composition, not only changing an accent color. Sidebar expansion must also match Opmoni's established behavior: one fully expanded group at a time, with the current destination remaining identifiable.

Use `.dashboard/app/app.config.ts` and `assets/css/main.css` for the theme source; `layouts/default.vue` for dashboard chrome; `pages/settings.vue` and `settings/index.vue` for settings; and `pages/customers.vue` for operational list composition. Existing Opmoni `pageShell.ts`, `data-table/panel.ts`, and `data-table/sheet.ts` provide product-local patterns for different page families. Match comparable surfaces, rather than imposing one width or density on every task.

## Confirmed sources of divergence

| Area | Current evidence | Planning consequence |
| --- | --- | --- |
| Base palette/font | `.dashboard/app/app.config.ts` and `frontend/app/app.config.ts` already match: primary green, neutral zinc. Both CSS files define the same green 50–950 scale and Public Sans. | Reuse the existing palette/font; installing another font or creating an ERP palette is unnecessary. |
| ERP-only light theme | `frontend/app/assets/css/main.css:21` changes `--ui-primary` to shade 900, success to 800, dimmed text to neutral 500, and muted text to neutral 600 whenever `[data-erp-workspace]` exists. Reference CSS has no equivalent block. | Compare resolved semantic values and foreground/background pairs across office/ERP. Review this route-dependent override as the direct source of color divergence. |
| Earlier contrast decision | The archived `standardize-erp-dashboard-ui/design.md:36` deliberately retained that light-mode correction. | Reconcile the earlier decision explicitly with the new consistency requirement. Measure contrast before changing it; archive history is evidence, not a file to silently rewrite. |
| Action weight | Reference settings Save uses `color="neutral"` (`.dashboard/app/pages/settings/index.vue:64`); `ErpSettingsSectionForm.vue:119` uses primary. Fiscal/Sales areas use action-style buttons where the reference uses native navigation. | Compare action and navigation variants by task. Fix role/variant selection as well as palette values. |
| Form measure | Reference settings uses `lg:max-w-2xl` and `lg:py-12`; ERP uses `max-w-4xl`, with its own `erpPageBodyClass` (`pages/erp/configuracoes.vue:28`). | Start ordinary single-task forms from the reference measure. Keep wider operational editors only where fields/content justify it. |
| Spacing families | ERP uses `space-y-5 p-4 sm:p-6`; Opmoni scrolling pages use `gap-4 sm:gap-5` and `p-3 sm:p-4 lg:p-5`; panel lists use another intentional composition. | Select a reference per page family and assign one outer gutter owner. Different task families are legitimate; accidental nested insets and local spacing substitutes are the issue. |
| Control/table density | Installed generated themes expose native md controls, 4rem navbar height, and standard card/table slots. `panelTableUi` already implements the reference header/row styling. | Preserve native sizing and reuse installed slots/shared tokens. Do not patch generated files, add global CSS scaling, or replace operational table sizing indiscriminately. |
| Sidebar expansion | `frontend/app/layouts/erp.vue:9` stores an array; lines 21–29 append active modules without closing previous groups. Both menus at lines 60/70 omit `type`, whose installed default is `multiple`. Office `layouts/default.vue:16` controls a single group and resets manual folding only when the owning module changes. | Adopt one controlled expansion policy across both ERP menu regions, matching the office sidebar's active-child preservation and native `type="single"` API. Changing the prop alone does not resolve the accumulating watcher or coordination between two menus. |

Official Nuxt UI guidance consulted through MCP: [design system](https://ui.nuxt.com/docs/getting-started/theme/design-system) and [semantic CSS variables](https://ui.nuxt.com/docs/getting-started/theme/css-variables). Current manifests declare Nuxt UI `^4.11.3`; recheck installed versions before implementation.

## Planned tasks

### V01 — Establish the shared visual baseline

Files to inspect: `.dashboard/app/{app.config.ts,assets/css/main.css,layouts/default.vue,pages/settings.vue,pages/settings/index.vue,pages/customers.vue}`, `frontend/app/{app.config.ts,assets/css/main.css,utils/pageShell.ts}`, and installed/generated Nuxt UI themes.

- [ ] Record resolved font family, root size, semantic colors, background/border colors, radius, navbar/sidebar dimensions, and representative control/card/table dimensions in matched light/dark conditions.
- [ ] Compare equivalent Opmoni and ERP surfaces with the same viewport and page state. Record actual loaded font rather than only its declared name.
- [ ] Create a page-family matrix: settings form, catalog/list, operational workspace, detail, and editor. Identify the matching template/Opmoni reference for each.

Acceptance: one agreed baseline and a list of justified domain-specific exceptions. Source defaults and measured runtime values must be distinguished. No arbitrary new palette, font, root scale, or universal row height is introduced.

### V02 — Align semantic theme and component variants

Expected targets: `frontend/app/assets/css/main.css`; `frontend/app/app.config.ts` only if the shared configuration needs a documented change; consumed ERP component `color`, `variant`, and `ui` props.

- [ ] Reconcile the ERP-only light-mode semantic overrides with the shared office/template baseline. Match resolved token values for equivalent roles, not only the `green`/`zinc` palette names.
- [ ] Normalize primary/secondary/destructive actions, active navigation, statuses, muted text, borders, and surfaces using the existing semantic tokens and native component variants.
- [ ] Verify foreground/background contrast in real states before removing prior contrast corrections. Prefer template-compatible action variants and a coherent shared role mapping over a replacement route-specific palette.
- [ ] Check dialogs, popovers, dropdowns, toasts, and drawers teleported outside the ERP shell; navigating office → ERP → office must not leave a different theme behind.

Acceptance: equivalent office/ERP components have the same semantic role and appearance in light/dark mode; status and destructive colors retain their meaning. Any necessary accessibility adjustment is documented and reusable for the same role across the product. Do not blindly delete the existing correction or copy unsuitable foreground/background pairs.

### V03 — Align typography, geometry, and page density

Expected targets: `frontend/app/layouts/erp.vue`, `components/erp/erpPresentation.ts`, `ErpCatalogPageShell.vue`, `ErpSettingsSectionForm.vue`, `pages/erp/configuracoes.vue`, and affected ERP page consumers. Read existing `utils/pageShell.ts` and table tokens before adding anything.

- [ ] Keep Public Sans and the native root scale. Use the native navbar title, a distinct task/section hierarchy, and normal label/help text sizing; remove redundant large headings and introductory paragraphs under audit A09/A10.
- [ ] Match dashboard chrome to the reference: native 4rem navbar, sidebar defaults, one toggle, and existing resizable/collapsed behavior. At a 16px root these correspond to a 64px navbar and 240px default expanded sidebar; verify the actual root and persisted width before comparing.
- [ ] Match outer page gutters, form measure, label/control alignment, card padding, section gaps, and editor width by page family. Settings starts from the reference `lg:max-w-2xl`/`lg:py-12` composition; a wider exception needs actual content justification.
- [ ] Reuse native md controls for ordinary actions/filters; retain intentional sm/xs row actions. Use the reference's card variants, border radius, separators, and surface treatment rather than custom wrappers.
- [ ] Align compatible ERP table headers, row borders, cell type, filters, and counted footers with `panelTableUi`/`erpOperationalTableUi`. Preserve server pagination, variable-width business columns, and purposeful mobile record lists.

Acceptance: one outer gutter, aligned filter/table edges, no duplicate panel padding, no added global scaling, and comparable density to the matching reference. Standard controls keep native dimensions; long fiscal/commercial forms remain usable and complete.

### V04 — Roll out through shared components and active consumers

- [ ] Start with company settings, including toolbar projection, meaningful field groups, save-action weight, and field spacing.
- [ ] Apply the baseline to Cadastros, Financeiro/Caixa, Compras/Estoque, Vendas, Fiscal, Relatórios, and Contabilidade through the shared shells/tokens they actually consume.
- [ ] Check ERP overview, contract, and public company-registration pages separately; preserve their auth/public layout needs and commercial truthfulness.
- [ ] Remove local overrides only when the native/shared pattern demonstrably covers the same task. Keep changes out of inactive demonstration components used by no real route.

Acceptance: the scope covers `pages/erp` and its live components, rather than improving one company form or changing an unused hub. Existing office, admin, calendar, print/label, and public-auth surfaces retain their purposeful behavior. Do not apply calendar glass variables or label-print colors to dashboard screens.

### V05 — Verify consistency and prevent regressions

- [ ] Record matched before/after screenshots and computed token/geometry samples on company settings, one catalog, Financeiro, Vendas, Fiscal, and Relatórios in light/dark mode, at desktop 1366px and mobile 390px. Include an intermediate viewport and 200% zoom check for layout/legibility.
- [ ] Compare the ERP samples with the appropriate `.dashboard`/Opmoni reference using the same viewport, root scale, sidebar state, theme, and comparable content. Check responsive layout, readable fields, scroll ownership, overlay colors, focus visibility, and contrast. Viewport emulation does not certify physical touch behavior.
- [ ] Use existing relevant behavior suites listed in `report.md`, plus targeted tests for changes to navigation, authority, or state. Pure spacing/color edits need meaningful visual evidence rather than tests that just mirror CSS strings.
- [ ] Run frontend lint, typecheck, tests appropriate to affected behavior, and build using the documented scripts when implementation is complete. Check office/ERP navigation and theme persistence as cross-area regressions.
- [ ] Preserve all tenant/permission boundaries, payloads, fiscal/commercial gates, pending-operation identities, error recovery, and focus-return behavior. Keep fiscal issuance/live-provider acceptance separate from UI evidence.

Acceptance: completed baseline comparison, light/dark and responsive evidence, passing relevant checks, and an explicit list of any remaining unverified flows. The present planning update does not claim these checks have run.

### V06 — Match Opmoni's single-group sidebar expansion

Requested addition: stop accumulating expanded ERP sections as users navigate. Reference behavior is `frontend/app/layouts/default.vue:16–24,87–125,174–207`, together with `utils/sidebarNav.ts:43–59` (`foldedSidebarChildren`). Nuxt UI MCP confirms that vertical `type="single"` uses a string model, whereas the default `multiple` uses a string array. The current ERP array/watcher and both menu bindings must be considered together.

Expected targets: `frontend/app/layouts/erp.vue`; reuse `utils/sidebarNav.ts` or extract a focused shared state/projection helper only where it avoids duplicating the existing office behavior. Preserve the ERP permission-aware registry and the two-region template layout. Relevant checks: `tests/erpMobileShell.test.ts`, `erpWorkspaceNav.test.ts`, `erpSidebarNavigation.test.ts`, and `sidebarNav.test.ts`; office behavior needs regression coverage if shared code changes.

- [ ] Replace the accumulating expansion array with a single controlled expansion policy shared across operational modules and the lower Cadastros/Configurações menu. Opening Vendas then Compras leaves only Compras fully expanded; opening Configurações then collapses the previously expanded operational group, and vice versa.
- [ ] Match the office sidebar's distinction between the expanded group and the current destination. When the current module is folded or another group is explored, retain only its active child as the existing Opmoni pattern does; hide its siblings. This pinned current destination is not a second fully expanded menu. Preserve its node identity/highlight and truthful native disclosure state.
- [ ] Allow explicit manual folding. Navigating between children or hashes within the same module must not immediately override that decision. Entering another module through a link, search, reload, or history opens the destination's owning permitted group and closes the previous full group. Routes with no submenu, such as Visão geral and Contabilidade, do not inherit stale expanded groups.
- [ ] Reconcile selected company/Account changes and revoked permissions: clear stale group state and select only a group still present in the authorized registry. Preserve SSR-safe hash handling and distinguish route-active state from a group merely opened for exploration.
- [ ] Preserve native keyboard interaction, chevrons, focus visibility, collapsed-sidebar tooltip/popover access, and the existing mobile close/focus contract. Expanding a parent does not navigate or dismiss the mobile drawer; selecting a destination does. Use the controlled empty value supported by Reka rather than accidentally reverting to uncontrolled state.
- [ ] Update the existing array-oriented shell tests for the new behavior and add meaningful cases covering sequential opens across both menu regions, manual fold plus same-module hash changes, pinned active child, leaf destinations, company/permission changes, and reload/history. Validate desktop/mobile with a short viewport and a deep submenu; verify focus return after actual destination navigation. Check office sidebar regressions if a shared helper is extracted.

Acceptance: at most one sibling list is fully disclosed across the entire ERP sidebar; previously visited groups no longer accumulate. The current destination remains visible/identifiable according to the existing office pattern. Manual fold, active route, exploration, collapsed icon menus, and mobile navigation retain their distinct behaviors. This is planned work; no sidebar code or tests were changed in this planning update.

### V07 — Simplify product creation and correct shared form behavior

- [ ] Implement the [V07 form tasks](forms-and-defaults-plan.md#v07--refactor-forms-around-the-smallest-complete-task) using the independent [form audit](form-findings.md). Keep name/unit as the minimal product contract, advanced fiscal fields optional, primary actions reachable, string codes lossless, and errors/lookup state coherent. Extend the same patterns to the reviewed contact, settings, commercial, finance and fiscal forms.

Acceptance: a basic product can be saved without tax entry, while every existing advanced value and operation-specific validation remains supported. Match V01–V05 for theme and proportions; collapsing fields must not clear them.

### V08 — Supply native initial settings and basic catalogs

- [ ] Implement the [V08 setup tasks](forms-and-defaults-plan.md#v08--initialize-settings-and-basic-catalogs-through-native-provisioning), covering settings, currency contract, a native unit, default customer and supported commercial document numbering/layout. Use an idempotent initialization service and additive existing-company reconciliation, with each default linked to an active native consumer.

Acceptance: a fresh company can use its basics without manual bootstrap. Reruns preserve existing data, preferences, numbering, credentials and history. Do not copy legacy IDs, sample identities or unsupported tax assumptions.

### V09 — Introduce reusable product tax configurations

- [ ] Specify and implement the [V09 profile tasks](forms-and-defaults-plan.md#v09--reuse-product-tax-configurations-with-explicit-inheritance): company-scoped profiles, explicit product overrides/inheritance, applicable company defaults, authoritative effective-value resolution and operation snapshot provenance. Cover ICMS/PIS/COFINS and the other currently supported tax fields without requiring them for basic product creation.

Acceptance: shared profiles remove repeated entry; field source/exceptions remain understandable; null, inherited and explicit zero stay distinct. Profile changes apply only to eligible future/reviewed operations. Preserve posted Sales/fiscal facts and strict model-specific issuance validation. This is a functional contract change requiring an in-flight specification, not a visual-only adjustment.

## Integration with the existing roadmap

V01/V02 establish the visual foundation before broad restyling. V06 belongs to the sidebar/navigation work alongside A02–A07 and can be implemented without waiting for every visual adjustment. Audit A01 (Fiscal hash correctness) remains the highest-priority interaction fix. Use the baseline while implementing A02–A13 so navigation, copy, editor, and density corrections converge on the same template. Work from an isolated project-root worktree during implementation; this plan does not reopen or modify the archived change.

V07 can simplify the current basic form without waiting for V09. V08 must establish the native unit prerequisite and verify default consumers. Specify V08/V09 together where setup selects a tax profile; implement profile resolution and snapshot/readiness behavior before presenting dynamic inheritance as functional. Details, dependencies and verification scenarios are in the linked extension plan.
