# Group 3 — page gutters and catalog filters (D02–D03)

Status: DONE_WITH_CONCERNS (focused checks pass; final broad acceptance remains controller-owned).

## Scope and architecture

Worktree `/home/obsidian/dev/opmoni/.worktrees/erp-ui-audit-shell`, branch `codex/standardize-erp-dashboard-ui`, base `ffd7415`. Existing dirty root AGENTS.md and untracked frontend/AGENTS.md preserved and excluded. Read group3 brief/global constraints, group1/2 reports and runtime handoff, proposal/design/delta spec, frontend instructions, office pageShell tokens, main read-only `.dashboard` customers example, Nuxt UI conventions/dashboard references and installed panel theme. Nuxt UI MCP DashboardPanel usage/API/theme confirms body slot and `ui.body` override; official example demonstrates native header/body composition.

Runtime changes: new `erpPageBodyClass = 'space-y-5 p-4 sm:p-6'`; catalog shell and settings wrapper consume it. Settings native panel body has zero padding; centered `max-w-4xl` wrapper owns the gutter, retaining standard inner card padding. Catalog resource replaces its nested UDashboardToolbar with one responsive body filter row. Labeled search/model, count live region, list debounce/lifecycle, pagination/retry announcements, resource props/events, editor controls and all authority/request/payload contracts remain unchanged.

## Verification

Commands from worktree root:

```bash
pnpm --dir frontend exec node --experimental-strip-types --test tests/erpCatalogListPresentation.test.ts tests/erpCatalogListLifecycle.test.ts tests/erpMobileShell.test.ts tests/erpCompanySettingsApi.test.ts
pnpm --dir frontend exec eslint app/components/erp/erpPresentation.ts app/components/erp/ErpCatalogPageShell.vue app/components/erp/catalogs/ErpCatalogResource.vue app/pages/erp/configuracoes.vue tests/erpMobileShell.test.ts tests/erpCatalogListPresentation.test.ts
git diff --check
```

Baseline focused tests: exit0, 53 tests/7 suites, 0 failures. Final focused tests: exit0, 53 tests/7 suites, 0 failures. Changed-file ESLint: exit0, no output. Diff check: exit0. Existing suites cover independent catalog pending/error/retry/confirmed-empty/stale states, search debounce and paging, company/Account/session authority changes, native mobile shell and settings read/write authority/API payloads.

First after run: 51 pass/2 fail because presentation tests asserted the number of removed nested dashboard toolbars. Replaced those structural expectations with actual labeled search-field presence/count. Mobile shell test evaluator now resolves the new token module import. No CSS string tests or new runtime behavior were added. This is a visual correction verified through actual browser geometry, not a new behavior TDD claim.

## Browser geometry

Sequentially reused normal authenticated `erp-ui-group1` and existing localhost:3107 worktree runtime. Read existing units and company settings only; no mutation, invalid submit, provisioning, grant or backend-store write. Default root size16px; desktop sidebar expanded240px.

| Scenario | Before | After | Result |
| --- | --- | --- | --- |
| Catalog mobile390x844 | Search x32; table x16 | Search/table/frame x16 | Aligned, page width390 |
| Catalog desktop1366x900 | Audit identifies nested toolbar padding | Search/table/frame x264 | Aligned, page width1366 |
| Settings mobile390x844 | Card x32; first field x48 | Card x16; first field x32 | One outer gutter plus standard card padding |
| Settings desktop1366x900 | Centered measure retained by design | Wrapper x355, width896; card x379,width848 | Existing max-w-4xl centering intentionally retained |

Measured alignment booleans true (1px tolerance). Catalog mobile table remains485.20px inside visible358px frame x16–374, preserving local horizontal scrolling without page overflow. Two existing units render; count remains2 registros; labeled search remains accessible. Desktop screenshot shows expanded office/company context, filter, count and row actions. Both catalog screenshots visually inspected.

Settings baseline was remeasured by temporarily serving the exact base settings wrapper file, then restoring the accepted file; final live check confirms restored card x16/content x32. Initial settings metric selector expected native DOM id/data-slot attributes that are absent; replaced that incomplete capture with actual mounted form/card geometry. First screenshot attempt used a relative path against the daemon working directory and failed without writing; final catalog screenshots use absolute paths. No settings values or screenshots were retained; geometry and disabled=false establish that the normal loaded form was present. Desktop first input is right-aligned by its existing responsive form layout; its x1025 in JSON is the control position, not the card's left content edge.

## Evidence, runtime handoff and limits

`group-3-*` evidence contains baseline/final focused runner output, changed-file lint output, catalog baseline/mobile/desktop rectangles and screenshots, settings before-mobile/after-mobile/after-desktop geometry and this report. Original audit unchanged. No credentials/cookies/keys/settings values included.

Existing worktree Nuxt process/environment left running unchanged. Browser remains authenticated,390x844, `/erp/configuracoes/empresa`, accepted wrapper restored. Main backend reads only; no backend/queue/scheduler restart. No dependency/theme/backend/env/gate/schema/instruction edits, merge/push/archive or checkbox update. Full lint/typecheck/build, full suites, detector and final group acceptance belong to controller. Browser search-debounce/error simulations, save operations and production fiscal/SERPRO flows were not run; relevant lifecycle/API tests passed. D06/reference compatibility remains outside group3 and unresolved per design. Main material limitation is representative read-only browser coverage, not full ERP release acceptance.
