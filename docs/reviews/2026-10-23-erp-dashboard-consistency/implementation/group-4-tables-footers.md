# Group 4 — operational tables and controlled list footers

Status: DONE_WITH_CONCERNS. Focused implementation acceptance is complete; broader release checks and the browser limits below remain explicit. Parent owns OpenSpec checkbox acceptance.

## Scope and architecture

Worktree `/home/obsidian/dev/opmoni/.worktrees/erp-ui-audit-shell`, branch `codex/standardize-erp-dashboard-ui`, base `9b08318`. Preserved modified root AGENTS.md and untracked frontend/AGENTS.md. Read the group brief/global constraints, proposal/design/delta spec, project/frontend instructions, previous runtime handoff and current workspace/composable ownership. Applied Nuxt UI conventions/component selection/data-table guidance. Consulted actual Nuxt UI MCP Pagination API/examples and Table usage/examples, plus installed 4.11.3 generated Table/Pagination themes. Official references: https://ui.nuxt.com/docs/components/pagination and https://ui.nuxt.com/docs/components/table. No dependency added.

`erpOperationalTableUi` derives directly from `panelTableUi`, changing only its base to `min-w-full table-auto border-separate border-spacing-0`. Office/admin tokens remain unchanged.

`ErpListFooter` takes required server metadata, optional pending (false) and summary, and required controlled `page` model. Its localized confirmed count remains visible for zero/one-page responses. Native pagination uses server total/per_page and appears only for multiple pages. No watchers, fetching, aggregation, slicing or cached pagination state. It emits only integer, in-range, changed, idle user transitions. A reduced native sibling window plus wrapping keeps controls reachable on narrow frames.

Consumers: real catalog resource/pagination; finance operational list; cash registers/sessions/movement history; purchase and return lists; active stock area. Catalog count moved from filter row to counted footer; loading/search-pending/stale/error/retry announcements remain in ErpCatalogPagination. Pending includes search debounce and blocked catalog authority. Every workspace retains its existing page/load handler and composable query/filter/permission/recovery ownership. Missing metadata never becomes a fabricated zero count. Purchase footer also keeps the selected-area condition; its former length-based unknown-count fallback was removed.

Cash movement history has no public pending ref. A presentation-only `movementPagePending` flag surrounds the existing awaited `cash.loadMovementPage(session.id, page)` handler with try/finally. It suppresses synchronous repeated handler events and further transitions throughout the request. No composable API, query, authority, session-selection or financial state was moved or altered.

## Behavioral evidence

RED: before ErpListFooter existed, `pnpm --dir frontend exec node --experimental-strip-types --test tests/erpListFooter.test.ts` failed with ENOENT for that component (`group-4-footer-red.txt`). GREEN: the same command passes 7/7 (`group-4-footer-green.txt`). Coverage: confirmed 0/1/15 counts with no pagination, grouped server total 1234 and per_page 7, default idle state, one valid transition, no unchanged/invalid/fractional/non-finite/pending events, mounting and metadata replacement without events or forced model changes, custom summary preserving native pagination inputs.

The mounted consumer integrations exercise the real footer, leaving only native UI boundaries represented by harness controls. `group-4-integration-green.txt` has 9/9 focused assertions:

| Consumer | Admitted page request and preserved state |
| --- | --- |
| Catalog Products | One page=2 request; search=café retained; pending/invalid/same-page suppressed; 403 removes pagination and keeps authority feedback. |
| Finance | One page=2 request through existing load; all active server filters retained; obligations tab unchanged; pending blocked; denial clears confirmed footer. |
| Cash registers | One registers page=2 request; page-owned metadata/count retained; pending blocked; revoked authority clears footer and refuses further load. |
| Cash sessions | One sessions page=2 request with register/establishment/currency/status/date filters; selected list unchanged; pending blocked; revoked authority refuses load. |
| Cash movements | One `/erp/cash-sessions/21/movements?per_page=15&page=2`; synchronous repeated events plus later pending event make no duplicate request; busy releases after await; selected session 21 and server count retained; revoked authority clears private footer. |
| Purchases | One purchases page=2 request with status/search/per_page; same selected area, pending blocked, confirmed server count. |
| Purchase returns | One purchase-returns page=2 request with status/per_page; same selected area, pending blocked, confirmed server count. |
| Stock movements | One selected movements page=2 request with establishment/variant/date filters; selected hash unchanged, pending blocked, server count retained; revoked authority clears footer/refuses load. |
| Catalog resource | Search remains available; no initial count; confirmed server count 27 rendered via real footer, independent of two returned rows. |

Purchase denial preserves the pre-existing authority fallback: a 403 clears private state and marks its list action denied; the component then checks the other still-permitted list once. With both responses denied, existing navigation falls back to XML preview. Tests distinguish the one original page=3 request from that existing authorized fallback request and assert the final allowed hash/privacy rather than changing this business behavior.

Initial integration failures were harness issues: waiting through nested async presentation guard completion; passing the existing purchase find(root, predicate) signature; waiting for stock's existing mount load; asserting pre-existing purchase authority fallback. The prior catalog authority test still asserted a UDashboardToolbar removed by accepted group 3; it now checks actual search and confirmed-count behavior, eliminating those CSS-string assertions. Initial output retained as `group-4-focused-initial.txt` / `group-4-integration-initial.txt`.

## Specialized table dispositions

| Surface | Disposition |
| --- | --- |
| ErpReportMetrics | Applies compatible operational header/row tokens. Keeps caption, server ledger row_count/detail_limit/truncation text, snapshot totals and current/historical basis. No paginated footer: report details are bounded server snapshots, not list pages. |
| importacao.vue | Applies compatible table tokens. Keeps max-height local scrolling, per-record validation and confirmation/recovery controls. No counted page footer: preview rows belong to one validated import snapshot, not a server-paginated list. |
| etiquetas.vue | Applies compatible table tokens while retaining whitespace-normal cells/empty spacing. Keeps product/variant selection, cross-page copy quantities, native compact product pagination, print frame, layout/printer configuration and output readiness. Its product-selection pager and selected-copy summary remain specialized; no operational footer replacement. |
| ErpAccountingDeliveries | Retains semantic HTML table with scoped column headings, minimum 44rem width, local overflow-x-auto, status/attempt/destination/action columns and existing elevated/default tokens. Existing paging/detail/retry/recovery behavior remains specialized. No runtime edit needed. |
| ErpModuleHub | `rg -n 'ErpModuleHub' frontend/app --glob '!ErpModuleHub.vue'` returned no references (exit 1). No active route depends on it; untouched. |

## Focused verification

Command (root):

```bash
pnpm --dir frontend exec node --experimental-strip-types --test tests/erpListFooter.test.ts tests/erpCatalogListLifecycle.test.ts tests/erpCatalogListPresentation.test.ts tests/erpCatalogResourceAuthority.test.ts tests/erpFinanceWorkflows.test.ts tests/erpCashWorkflows.test.ts tests/erpPurchaseWorkflows.test.ts tests/erpStockWorkflows.test.ts tests/erpReportExports.test.ts tests/erpCatalogLabels.test.ts tests/erpCatalogLabelWorkflow.test.ts tests/erpCatalogLabelRenderer.test.ts tests/erpImportConfirmation.test.ts tests/erpMobileShell.test.ts
```

Exit 0: 331 tests / 33 suites, zero failures/skips (`group-4-focused-green.txt`). Final isolated footer rerun passes 7/7 after test formatting. `git diff --check` exit 0. Changed-path ESLint exit 0 (`group-4-lint-green.txt`); command includes all 21 changed frontend source/test paths listed by this group, including the shared helper and mobile-shell harness. Initial lint output records the corrected formatting failures.

Exact changed-path lint command (exit 0, no diagnostics):

```bash
pnpm --dir frontend exec eslint app/components/erp/erpPresentation.ts app/components/erp/ErpListFooter.vue app/components/erp/ErpCatalogPagination.vue app/components/erp/catalogs/ErpCatalogResource.vue app/components/erp/ErpFinanceWorkspace.vue app/components/erp/ErpCashWorkspace.vue app/components/erp/ErpPurchaseWorkspace.vue app/components/erp/ErpStockWorkspace.vue app/components/erp/ErpReportMetrics.vue app/pages/erp/cadastros/etiquetas.vue app/pages/erp/cadastros/importacao.vue tests/erpListFooter.test.ts tests/erpCatalogListPresentation.test.ts tests/erpCatalogResourceAuthority.test.ts tests/erpFinanceWorkflows.test.ts tests/erpCashWorkflows.test.ts tests/erpPurchaseWorkflows.test.ts tests/erpStockWorkflows.test.ts tests/erpReportExports.test.ts tests/erpMobileShell.test.ts tests/helpers/compileVueComponent.ts
```

Small test-only shared helper change: `compileVueComponent` resolves its existing `~/` TypeScript-import branch through existing `loadAppComposable` rather than raw require, enabling adapter -> panel alias resolution. Direct module mocks and auto-import bindings still take precedence; SFC/shared/package branches are unchanged. Existing mobile-shell harness similarly loads the presentation module through this loader instead of raw Node ESM. Relevant consumers pass the focused suites; parent must run the final full suite to cover unrelated helper consumers.

## Browser evidence

Used the existing worktree frontend at localhost:3107 and sequential named session erp-ui-group1; normal authentication/company selection preserved. No server process/environment was changed. Only read-only navigation and local UI interaction occurred. Long/populated catalog, movement-history and label rows were synthetic browser-memory presentation samples, not inserted records or API responses. Original state restored after each sample; label was selected then deselected without preparing/printing output. No backend mutation, fiscal request, financial replay, database/session/cache changes or permission grants.

Actual 16px root font, 390px mobile / 1366px desktop:

| Sample | Measurements |
| --- | --- |
| Long catalog row, mobile | Document width390; frame x16 width358; table local width1313. Local scrollLeft955 exposes row-action trigger; opened native Ver/Editar/Arquivar menu without selecting any action. Footer x16 width358, all 32px native controls inside frame. |
| Long catalog row, desktop | Document width1366; frame/footer x264 width1078; local horizontal scrolling preserved with wide content. |
| Cash history, mobile | Document width390; frame x16 width358, local table width1052. Footer height81: count row then native controls 12px below; all controls within x16..374. |
| Label selection, mobile | Document width390; table x17 width356, long name wraps; checkbox x33..49 remains reachable; clicking updates `Seleção: 1 etiqueta`; deselection restored. |

`group-4-browser-assertions.txt` programmatically validates no global horizontal overflow, frame gutters, all footer controls within frame, local scrolling for catalog/history, cash wrapping and label selection. JSON rectangles and four screenshots are retained. Screenshots were visually inspected. Numeric cell alignment was inspected and preserves existing native start alignment; this task changes the shared surface, not numeric field formatting or precision (D09 belongs to a later group).

## Limits and runtime handoff

Fullsuite/typecheck/build/detector belong to parent final acceptance and were not run here. Accounting delivery browser/retry behavior, report/export browser downloads, import confirmations against live stores, actual printing, all individual finance/purchase/stock populated browser pages, live fiscal/SERPRO and real financial mutations were not exercised here. Existing focused suites cover their unchanged behavior but do not constitute live-provider acceptance. Concrete localization follow-up for groups 8/9: browser native pagination ARIA labels remain English (`First Page`, `Previous Page`, `Page 1`, `Next Page`, `Last Page`), also observed in the audit baseline despite the preserved global pt_br provider. Installed Nuxt UI 4.11.3 Pagination uses only `dir` from useLocale, and pt_br messages have no pagination section; its public props expose no label/locale override. Installed Reka 2.10.5 hardcodes these labels. Official supported first/prev/next/last/item slots can provide scoped native UButton children with Portuguese aria-labels; installed PrimitiveSlot merges child props after inherited attributes, so this scoped path can override those defaults without touching global locale/theme. This group records the observed gap and supported remediation path, and does not claim full pager localization or implement a global i18n change.

Frontend localhost:3107 remains running unchanged. Browser erp-ui-group1 remains authenticated at `/erp/cadastros/produtos`, desktop1366x900, original catalog state restored, no menu/modal open. Private runtime env/logs remain out of evidence. No OpenSpec checkbox, instruction, backend, environment, dependency, .dashboard, global panel token, theme or gate edited. No merge/push/archive performed.
