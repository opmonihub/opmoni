# ERP dashboard consistency audit

Date: 2026-10-23. Application source: `5ce7d51`. Reference: the local ignored `.dashboard/` directory. Scope: exploration and verification; no application code changes.

The ERP has the correct Nuxt UI dashboard foundation, but several compositions diverge from the reference. The most serious observed defect is the expanded sidebar header: two workspace selectors do not fit its fixed height. Settings accumulate padding, catalog filters have an additional inset, and operational tables do not consume the existing template table styling.

## Coverage and evidence

- [Complete inventory, CSV](inventory.csv): all 40 page files and 57 ERP components, their direct component dependencies, table/form/overlay declaration counts, and explicit sizes.
- [Complete inventory, JSON](inventory.json): the same files with class locations, overlay declarations, and usage totals.
- [Browser evidence](evidence/): local screenshots and computed DOM measurements at 1366 × 900 and 390 × 844. These are current captures, not the earlier review's screenshots.
- Three read-only explorers inspected shell/navigation, tables/lists, and forms/settings in separate project-root worktrees. The coordinator checked their findings against source, installed themes, and the browser. Requested worker routing was Luna/medium; execution-model and cost telemetry were unavailable.

Counts describe **static Vue template declaration sites**, not simultaneously rendered widgets or distinct business operations. They exclude `h()` render functions and runtime expansion of repeated/dynamic components. There are 12 `UTable` declaration sites in nine ERP files, of which one belongs to the currently unreferenced `ErpModuleHub`. The 11 remaining sites have application consumers. Accounting deliveries also contain one native HTML table. There are 37 `UForm`, 209 `UFormField`, 26 `UModal`, and one `USlideover` declaration sites.

| Page family | Files | Composition |
| --- | ---: | --- |
| Public registration, `/erp/cadastro` | 1 | Access flow with `layout: false`; intentionally outside the dashboard. |
| Overview, `/erp` | 1 | ERP layout supplies panel/navbar; page supplies introduction and status alert. |
| Contract, `/erp/contrato` | 1 | ERP layout supplies panel/navbar; centered contract card. |
| Catalogs, `/erp/cadastros/*` | 12 | `ErpCatalogPageShell`; nine pages use `ErpCatalogResource`; labels/import and the index provide specialized content. |
| Operations | 8 | Cash, purchases, stock, finance, sales, fiscal, accounting, reports. Workspaces or the accounting page compose `ErpCatalogPageShell`. |
| Settings | 17 | One parent panel plus 16 child files, including the index redirect. Children provide forms/content through `NuxtPage`. |

`erp.vue:13-16` determines whether the page owns the panel. Source inspection and the focused shell tests found no missing or duplicated panel in these route families. This does not eliminate the sidebar header defect below.

## Actual baseline: versions, dimensions, configuration

The installed host app, Docker app, and local reference use Nuxt UI **4.11.3**. The running app uses Nuxt **4.6.0** and Vue **3.5.43**. App and reference share Public Sans, green primary, zinc neutral, and the green scale. `frontend/app/app.vue` already supplies `UApp :locale="pt_br"` and `lang="pt-BR"`.

Generated themes were inspected under `frontend/.nuxt/ui/`, together with the installed package source. These generated files exist in the main checkout, not the exploration worktrees. Pixel conversions below use the 16px root size confirmed by browser measurements.

| Element | Theme/reference baseline | ERP observation |
| --- | --- | --- |
| Navbar | Header height `4rem`; `px-4 sm:px-6`; semibold inherited title. | 64px high in both viewports; 16px title and 16/24px horizontal padding, matching the reference. |
| Sidebar | `unit="rem"`; default/min/max widths 15/10/20rem. Header height `4rem`; body `px-4 py-2`. | Desktop width 240px. Two context blocks overflow the 64px header; see D01. |
| Toolbar | `min-h-[49px]`, `px-4 sm:px-6`, horizontal overflow. | Native APIs are used. Settings navigation intentionally wraps on mobile; catalog search nests another padded toolbar inside the page body. |
| Panel body | `p-4 sm:p-6`, `gap-4 sm:gap-6`. | Catalog shell removes native padding and adds one `p-4 sm:p-6` wrapper. Settings retain native padding and add another wrapper with the same padding. |
| Input / Select | Default `size="md"`; standard controls have a 32px height. Inputs use 16px type on mobile and 14px from `md`. | Sampled search/settings/editor fields match these control sizes. Widths differ by field/layout; omitted `size` is a valid library default. |
| FormField | Default `md`, `text-sm`, label/control association, help/error/required support. | Catalog wrapper forwards these props. Generic settings omit per-field help/error handling. |
| Semantic colors | Reference light primary/success resolve to `#00C16A`. | ERP light explicitly resolves primary to `#0A5331` and success to `#016538`, with darker muted text, through the scoped rule in `assets/css/main.css:24-30`. The dark sample resolves primary/success to `#00DC82`. Preserve this explicit contrast adaptation unless contrast is revalidated; it is not an accidental palette mismatch. |
| Table headings | Library default `px-4 py-3.5 text-sm`; reference overrides vertical padding to `py-2`, adds elevated header/borders/rounding. | Active ERP table sites generally use the library theme and local frames. Only the unreferenced hub imports `panelTableUi`. |
| Table cells | Default `p-4 text-sm whitespace-nowrap`; reference adds bottom borders. | Default cell padding remains 16px and type 14px. Total row height depends on actual content; it is not a reliable universal density token. |
| Modal | Standard width `calc(100vw - 2rem)`, capped at `max-w-lg` (32rem); body `p-4 sm:p-6`. | Wide purchase dialogs explicitly request `sm:max-w-5xl`. This is supported API, appropriate to review separately from a simple dialog. |
| Slideover | Lateral default `max-w-md` (28rem); body scroll and native header/footer. | Catalog editor explicitly uses `w-full sm:max-w-3xl`: measured 768px on desktop, full viewport width on mobile. This is a valid override. |
| Settings content | Reference uses a body with `lg:py-12`, an unpadded `lg:max-w-2xl` wrapper, and cards. | ERP uses `max-w-4xl` with additional padding. Wider measure and mobile wrapping are choices; duplicated inset is a separate defect. |

Official APIs/examples were consulted through the Nuxt UI MCP: [DashboardSidebar](https://ui.nuxt.com/docs/components/dashboard-sidebar), [DashboardNavbar](https://ui.nuxt.com/docs/components/dashboard-navbar), [DashboardToolbar](https://ui.nuxt.com/docs/components/dashboard-toolbar), [DashboardPanel](https://ui.nuxt.com/docs/components/dashboard-panel), [Table](https://ui.nuxt.com/docs/components/table), [Pagination](https://ui.nuxt.com/docs/components/pagination), [Form](https://ui.nuxt.com/docs/components/form), [FormField](https://ui.nuxt.com/docs/components/form-field), [Modal](https://ui.nuxt.com/docs/components/modal), and [Slideover](https://ui.nuxt.com/docs/components/slideover). Example source inspected included `DashboardToolbarExample`, `FormExampleOnError`, and `TablePaginationExample`. The local `.dashboard` remains the visual authority; the official examples establish supported APIs.

## Prioritized findings

There are **one P1, seven P2, and one P3 findings** below. No P0 was established. This is a consistency audit with sampled browser coverage, not a complete WCAG, performance, or release certification; no numerical score is assigned to unmeasured dimensions.

### D01 — P1: workspace selectors overflow the sidebar header

**Observed in the browser.** `frontend/app/layouts/erp.vue:43-61` puts `WorkspaceContextMenu` into the native sidebar header without changing its 64px height. `frontend/app/components/WorkspaceContextMenu.vue:8-19` stacks office and company blocks, labels, and a gap.

At 1366px, the office selector starts at **y = -6px**. The company selector spans **y = 60–92px**, while the first navigation link spans **y = 72–104px**. The company control and navigation overlap by 20px. The issue also appears with the existing demonstration identity, which has office and company access.

Evidence: [geometry](evidence/erp-sidebar-context-overlap.json), [products desktop](evidence/erp-products-desktop-light.png), [settings desktop](evidence/erp-settings-desktop-light.png).

**Impact:** clipped workspace identification and overlapping interactive targets in a core navigation path.

**Recommendation:** give the expanded context block its actual height, or place the secondary context block in a suitable sidebar section. Preserve the 64px compact state and the existing company/account selection and authorization logic. Native sidebar slot overrides support this; no custom primitive is needed.

### D02 — P2: settings add a second page inset

**Source and browser confirmed.** `frontend/app/pages/erp/configuracoes.vue:12` retains the default panel body padding; its wrapper at line 27 adds `p-4 sm:p-6` again. The form card in `ErpSettingsSectionForm.vue:133` adds its normal internal padding.

At 390px, the ERP card starts at **x = 32px** and its first input at **x = 48px**. In the reference settings page, the card starts at **x = 16px**, with its first input at **x = 32px**. The ERP first input is 294px wide. The additional 16px page inset on each side is independent of the legitimate card padding. Desktop also combines body, wrapper, and card padding, while using a wider maximum content measure than the reference.

Evidence: [ERP mobile](evidence/erp-settings-mobile-light.png), [reference mobile](evidence/template-settings-mobile-light.png), corresponding JSON measurements. Reference source: `.dashboard/app/pages/settings.vue:30-45`.

**Recommendation:** choose one owner for page padding. Preserve the card's internal padding and make the settings width an explicit decision. Do not mechanically replace the ERP shell with the unrelated compact `pageShell.ts` utilities: `.dashboard` itself uses the 16/24px rhythm.

### D03 — P2: catalog search has an extra toolbar inset

**Source and browser confirmed.** `ErpCatalogPageShell.vue:31` pads the page. `catalogs/ErpCatalogResource.vue:377` then mounts a native toolbar in that body without clearing its native `px-4 sm:px-6`.

At 1366px, the body/table edge is x = 264px, while the search input starts at x = 288px. At 390px, the table edge is x = 16px and the search input starts at x = 32px. The reference customer search aligns with the table's outer edge.

Evidence: [ERP products](evidence/erp-products-desktop-light.png), [reference customers](evidence/template-customers-desktop-light.png), mobile equivalents and JSON measurements.

**Recommendation:** use a body-level filter row, as in the reference customer page, or explicitly remove the nested toolbar inset. A toolbar in this slot is supported API; the problem is the accumulated geometry.

### D04 — P2: active table styling and footers do not share the template pattern

**Source and browser confirmed.** `.dashboard/app/pages/customers.vue:303-310` defines the same border/header styling already extracted into `frontend/app/components/data-table/panel.ts:13-19`. Catalog, finance, purchase, cash, stock, label, and report tables choose separate local presentations.

`ErpModuleHub.vue:233` imports `panelTableUi`, but there is **no direct application consumer of `ErpModuleHub`**. Changing that component would not standardize the current pages. This was verified by searching the full application, not only the ERP directory.

Footers also vary: catalog/finance use `UPagination`, while purchase/stock/cash use previous/next buttons and different count placement. These are valid behaviors, but the presentation is inconsistent.

**Recommendation:** apply the existing table token to compatible active list tables and reuse a counted-footer composition. Preserve specialized report/detail tables and Laravel pagination semantics. Avoid a global table-theme change that would unintentionally affect the office/admin UI.

### D05 — P2: migration notes are visible as product descriptions

**Source and browser confirmed.** Settings expose Blade filenames and development references, including:

- `pages/erp/configuracoes/empresa.vue:10` — implementation-path description;
- `pages/erp/configuracoes/compras.vue:8` and `produto.vue:8` — Blade partial filenames;
- `pages/erp/configuracoes/email.vue:62` — template filename;
- `pages/erp/configuracoes/rotulos.vue:7` — internal change identifier and future-editor note.

The existing demonstration company's settings screenshot visibly contains this implementation text.

**Recommendation:** replace these descriptions with short Brazilian Portuguese guidance about the business task. Product help should explain the user's decision; migration provenance belongs in technical documentation.

### D06 — P2: several reference settings require users to enter database IDs

**Source confirmed; labels observed in the browser.** `utils/erpCompanySettingsFields.ts:19,29` defines currency/city as legacy ID inputs. `configuracoes/produto.vue:11`, `compras.vue:11`, and `vendas.vue:11` similarly expose unit, currency, and sales-tax identifiers.

`ErpSettingsSectionForm.vue:7-13,148-164` supports simple inputs/selects, without a catalog-source field type. As a result, users must know record IDs rather than select understandable names.

**Recommendation:** reuse `catalogs/ErpCatalogReferenceSelect.vue` where compatible paginated references exist. Where metadata/options are absent, identify that API dependency explicitly. Preserve existing identifiers and unavailable selections; do not reset them or invent options during a visual refactor.

### D07 — P2: generic settings discard field-level save errors

**Source confirmed; no live invalid save was submitted.** `ErpSettingsSectionForm.vue:48-61` catches save failures and shows a generic toast. The fields at lines 136-165 receive no error messages, and no `UForm.setErrors()` integration handles server validation errors. The email settings page uses a similar generic failure path.

Catalog forms already accept `fieldErrors`, and `catalogs/ErpCatalogField.vue:12-17` forwards `error` to `UFormField`.

**Recommendation:** reuse the existing server-error extraction convention and associate errors with named fields. Keep the toast for form-level/network failures. This is a missing integration, not evidence that `UForm` requires a new validation dependency or a client schema for every server-validated form.

### D08 — P2: the finance empty state is centered outside the mobile viewport

**Observed in the browser.** `ErpFinanceWorkspace.vue:478-484` gives the table root a minimum width of 48rem inside a horizontally scrollable frame. Its empty message at lines 558-561 is centered inside that same wide area.

At 390px the visible frame is about 358px wide, but the table/empty cell is **768px** wide. The beginning and remainder of the empty-state explanation cannot be read together at the initial scroll position. Whole-page width remains 390px; this is local empty-state clipping, not global page overflow.

Evidence: [finance mobile](evidence/erp-finance-mobile-light.png) and [measurements](evidence/erp-finance-mobile-light.json).

**Recommendation:** keep a confirmed empty state within the visible frame, outside the table's minimum-width content. Retain horizontal scrolling for actual columns and distinguish loading, failed, stale, and confirmed-empty responses.

### D09 — P3: catalog price presentation exposes transport decimals

**Source and browser confirmed.** `pages/erp/cadastros/produtos.vue:17` calls `formatErpDecimal`, but `shared/utils/erpCatalogs.ts:35-38` returns the raw string. The product list displays values such as `12.5000` and `125.0000` under “Preço”.

**Recommendation:** define a shared display convention for localized decimals and currency when the currency context is known. Preserve precision and string payloads in forms; do not round stored values or assume every ERP amount is BRL. This is a display refinement, not evidence of incorrect financial calculations.

## List and table map

| Source | Sites | Current pattern and reuse boundary |
| --- | ---: | --- |
| `catalogs/ErpCatalogResource.vue:395` | 1 | Shared by nine catalog page files; server search and pagination; contextual slideover; stale/error/access states remain explicit. |
| `ErpCashWorkspace.vue:291,377,523` | 3 | Cash accounts, sessions, movements; local table frames and previous/next footers. |
| `ErpFinanceWorkspace.vue:479` | 1 | Account/obligation/payment columns; filters, wide scroll frame, `UPagination`, custom empty state. |
| `ErpPurchaseWorkspace.vue:576,669` | 2 | Purchases/returns; bordered frames, status badges, labeled row actions, manual pagination. |
| `ErpStockWorkspace.vue:491` | 1 | Dynamic stock areas/columns; bordered table, labeled actions, manual pagination. |
| `ErpReportMetrics.vue:145` | 1 | Report-specific detail table and caption. Preserve its report semantics. |
| `pages/erp/cadastros/etiquetas.vue:134` | 1 | Product checkboxes, print/selection flow, pagination, horizontal scrolling. |
| `pages/erp/cadastros/importacao.vue:145` | 1 | Import preview and validation messages; `max-h-96`. |
| `ErpModuleHub.vue:229` | 1 | Template table token; currently unreferenced from application code. |
| `ErpAccountingDeliveries.vue:535` | HTML | Five-column semantic HTML table, 44rem minimum width, explicit horizontal scrolling and empty/error states. Different implementation alone is not a defect. |

The default `UTable` root already has `overflow-auto`. Initial suggestions that purchase/stock wrappers with `overflow-hidden` necessarily cut off the data were **withdrawn** after checking the installed theme. Missing TanStack sorting or selection is also not a defect without a product requirement. The reference's local pagination/filtering cannot replace Laravel pagination.

## Forms and overlays map

| Family | Existing reusable components | Assessment |
| --- | --- | --- |
| Catalogs | 13 family forms; `ErpCatalogResource`, `ErpCatalogField`, `ErpCatalogReferenceSelect`, `ErpContactNotesPanel`. | Keep contextual create/edit/detail, paginated reference choices, field errors, read-only mode, and archive confirmation. |
| Finance | `ErpFinancialAccountForm`, `ErpObligationForm`, `ErpPaymentForm`; workspace allocation/reversal dialogs. | Keep business-specific input semantics, busy/disabled states, reconciliation and receipt boundaries. |
| Cash | `ErpCashSessionForm`, `ErpCashMovementForm`, `ErpCashPaymentForm`, `ErpCashCloseForm`. | Standardize spacing/actions without changing close/payment behavior. |
| Purchase | `ErpPurchaseForm`, `ErpPurchaseReturnForm`, `ErpPurchaseXmlPreview`, `ErpPurchaseOperationRecovery`. | Wide dialogs are explicit supported overrides; preserve draft/XML/recovery stages. |
| Stock / sales | Adjustment/transfer forms and details; `ErpCommercialDraftForm`, `ErpSaleDetail`, `ErpSaleReturnForm`. | Preserve operational constraints and create forms on demand. |
| ERP fiscal | Certificate/configuration/document/event/invalidation forms and document detail. | Preserve issuance-specific fields and provider gates. Office fiscal capture is outside this audit. |
| Settings / accounting | `ErpSettingsSectionForm`, email form, `ErpCompanyAdministration`, `ErpAccountingMappingApproval`, `ErpAccountingDeliveries`. | Reuse the existing renderer; address error/help/reference deficiencies and page geometry. |

Native component APIs checked are supported: sidebar slot `collapsed/collapse`; vertical navigation expansion; navbar toggle slot; table `data/columns/loading/empty/ui`; named cell slots with `row.original`; controlled pagination; `UFormField` error/required/help; and modal/slideover sizing. The findings concern composition, missing integrations, and presentation rather than a wholesale use of invalid Nuxt UI props.

## Suggested implementation sequence

1. Fix D01 and D08, retaining navigation, context selection, and loading/empty semantics. Review both office-plus-company and company-only identities.
2. Normalize padding ownership and filter alignment in the existing shell/settings/catalog components (D02-D03).
3. Apply the existing template table styling and a shared footer to compatible active consumers (D04). Keep server pagination, selection scope, and field/row identity intact.
4. Replace migration copy and address settings references/errors in the shared renderer (D05-D07), without inventing missing API capabilities.
5. Refine numeric display (D09) and perform a final desktop/mobile, light/dark pass. Do not expand the task to backend fiscal or commercial rollout.

## Verification and operational record

| Check | Result |
| --- | --- |
| Static SFC inventory | Parsed all 97 scoped Vue files with the installed Vue SFC parser; no parse errors. |
| Focused lint | `pnpm --dir frontend exec eslint app/pages/erp app/components/erp app/layouts/erp.vue` — exit 0. |
| Focused existing tests | `node --experimental-strip-types --test --test-concurrency=2 tests/erpWorkspaceShell.test.ts tests/erpCatalogListPresentation.test.ts tests/erpCatalogForms.test.ts tests/erpMobileShell.test.ts` from `frontend/` — **85 passed, 0 failed**, four suites. |
| Runtime identity | Docker frontend mounts this checkout's `frontend/`; installed Docker versions confirmed separately from the host. |
| Browser samples | Local reference customers/settings, ERP products/list/editor, company settings, finance; desktop/mobile light and one product-list mobile dark sample. |
| Data access | Seeded admin credentials were checked without printing them; then the user-provided demonstration identity was used through normal login and native company selection. |

The existing admin seed had no company membership. Following authorization to use seeds, a separate local company **“Auditoria visual ERP 2026-10-23” (id 2)** was provisioned for that identity using the existing provisioning service. It contains an explicit owner membership, one establishment, a **pending** subscription, 26 synthetic products, two units, one brand, one category, and two contacts. These fixtures remain available for follow-up. The existing demonstration company and its memberships were not modified. No accounting, finance, stock, fiscal, or provider operation was posted; no global gates, environment values, passwords, or application keys were changed.

The reference dev server was started only for the comparison and was stopped afterwards. Owned browser sessions were closed and temporary audit credential profiles removed. Application code, tests, dependencies, and existing instruction-file edits were preserved. Only this review directory was added to the main working tree; no commit, merge, or push was made. Read-only exploration worktrees remain under `.worktrees/erp-ui-audit-{shell,tables,forms}` for follow-up; their only instruction-file changes are the main checkout's existing instructions copied in before dispatch.

Limits: browser coverage is sampled, not all 40 files or every form. Finance was inspected with an empty result; its filled-row operations were not exercised. Settings screenshots were recaptured after the data loaded. The product editor was opened and closed without saving. No live 422 settings submission, keyboard/axe audit, physical-touch test, broad typecheck/build, backend suite, or external fiscal/SERPRO acceptance was run. The tests use the repository's component harness and do not certify computed layout. Initial navigation timing interruptions were resolved before recording the settled samples; a globally error-free runtime session is not claimed. English default pagination accessibility strings were observed despite the app's existing locale configuration; their upstream labeling path needs a separate check before attributing that to app configuration.
