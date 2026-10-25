# Pre-creation UI reference audit

## Provenance and limits

The user requested a specialist review before change creation. The independent `office_erp_ui_specialist` completed the review before `openspec new change add-office-erp-commercial-management` ran. Its isolated checkout was `.worktrees/office-erp-ui-review`, clean at `f8efb50ad69e23a872e5b65ab18fff48b86f3590`. The coordinator then authored this change in `.worktrees/office-erp-commercial-design`.

Configured reviewer: `gpt-6.1-sol`, high reasoning effort. Actual provider/model routing was not independently observable. Read-only was enforced by the review task brief; the reviewer performed no file edits, package installation, browser operation or runtime mutation.

The reviewer applied the local nuxt-ui skill and consulted compact official MCP metadata and usage documentation for the initial 31 components in [component-matrix.md](component-matrix.md), plus 13 complete examples and installed declarations. The coordinator and reviewer subsequently validated UCard and UFileUpload individually, bringing the matrix to 33 components. This proves planning-time API compatibility for the selected APIs; it is not browser, SFC compilation, accessibility, build or application acceptance.

## Version baseline

Template and frontend both declare and locally resolve Nuxt `4.6.0`, Nuxt UI `4.11.3`, Tailwind CSS `4.3.3`, Vue `3.5.43` and Zod `4.6.5`; pnpm is declared as `12.11.2`. Version evidence comes from `.dashboard/package.json`, `frontend/package.json`, their lockfiles and installed package metadata in the main checkout/template. The new worktrees have no installed dependencies; no dependency installation is implied.

## Required visual source

The authoritative reference is `/home/obsidian/dev/opmoni/.dashboard/app`. Treat it as read-only theme/layout evidence, never as a source of demo business behavior. Implementation must inspect the relevant reference before each page/component family and retain the existing frontend's contrast corrections.

| Pattern | Inspected evidence | Decision |
|---|---|---|
| Colors and font | `.dashboard/app/app.config.ts:3`, `app/assets/css/main.css:5` | Public Sans; primary green, neutral zinc; semantic roles |
| Existing contrast fixes | `frontend/app/assets/css/main.css:21`, `:29`; `app/app.config.ts:7` | Preserve stronger light-mode roles, dark dimmed text and global outline-button states |
| Office shell | `.dashboard/app/layouts/default.vue:118`; `frontend/app/layouts/default.vue:148` | Reuse `default` layout, existing sidebar/search and `WorkspaceContextMenu`; no new Company ERP layout |
| Section parent | `.dashboard/app/pages/settings.vue:30`; `frontend/app/pages/admin.vue:15` | One commercial parent Panel/Navbar/Toolbar/NuxtPage composition without inheriting Super Admin guards |
| Lists | `.dashboard/app/pages/customers.vue:303`; `frontend/app/components/data-table/PanelList.vue:40`; `panel.ts:13` | Reuse `DataTablePanelList`, table-header/row/footer rules and existing compact empty component |
| Content dimensions | `frontend/app/utils/pageShell.ts:18`, `:24`, `:27`, `:34` | Reuse page scroll, table and record/detail classes; avoid duplicate gutters |
| Plan editing | `frontend/app/pages/admin/planos.vue:246`, `:255` | Existing Slideover + named Zod form + explicit footer controls and server errors |
| Confirmation | `.dashboard/app/components/customers/DeleteModal.vue:17` | Reuse modal proportions and semantics; replace demonstration handlers with real operations |
| Metrics | `.dashboard/app/components/home/HomeStats.vue:67` | PageGrid/PageCard presentation with actual server totals and explicit failures |
| Locale | `frontend/app/app.vue:2`, `:8` | Retain pt-BR and UApp locale; never copy the template's English business text |

The template's green scale is `50 #EFFDF5`, `100 #D9FBE8`, `200 #B3F5D1`, `300 #75EDAE`, `400 #00DC82`, `500 #00C16A`, `600 #00A155`, `700 #007F45`, `800 #016538`, `900 #0A5331`, `950 #052E16`. Existing semantic mapping takes precedence over route-specific raw colors.

### Template fingerprints

| Path under `.dashboard/` | SHA256 |
|---|---|
| `app/app.config.ts` | `f06e9b2f85689ecc9a7979c903e7919abce26a59865ddd19bbe790fcf6e318ef` |
| `app/assets/css/main.css` | `e355fab5f1f08f067ae25739e4bba07acda1bd8e82a8c28836a30889b097f41d` |
| `app/layouts/default.vue` | `43aff144b51a96b3146a478e73510ba94e58ba4f5efe5fd2f29250973a924a66` |
| `app/pages/customers.vue` | `d3885e88d1c906a0ccb40e0e8e66e6bd43178cb66d21d2106ecaf3dcbce5861f` |
| `app/components/customers/AddModal.vue` | `e912eecbb38e277b2670a7603624b0863610dedb99e40ecda8919266e6ae0dd9` |
| `app/pages/settings.vue` | `4e1493d3bd6ecd262cd65820847f3044c0153e447e8b41d77db68cbd579d2e16` |

## Findings carried into the design

1. The identity contract does not yet expose resale/maintenance capabilities. Add typed server capabilities instead of inferring them from `is_super_admin` or office ID.
2. Existing membership predicates in `frontend/shared/utils/business.ts` already require real active office membership. Extend their use; do not create a role shortcut.
3. An entitlement-only guard on `/gestao-erp` would violate the confirmed continuity rule. Separate read/maintenance permission from new-sale permission.
4. Existing admin ERP subscription loading reports errors only through a toast and can resemble an empty list. Use persistent retryable errors and authoritative empty states instead.
5. `UInput type=number` and `UInputNumber` coerce to JavaScript numbers. Prices and monetary receipts require text inputs and exact decimal strings; number inputs are for integer days/capacities only.
6. Current MCP metadata can expose APIs absent from installed 4.11.3 declarations, such as extra InputNumber options. Use only the subset recorded in the matrix.
7. Background billing must not use client Company finance to obtain a table/form for free. It needs its own Account authorization and operation history.

## Required visual acceptance

- Compare implementation against the named template at desktop `1366x768`, mobile `390x844`, and `320px` reflow, in light and dark themes.
- Exercise expanded/collapsed/mobile sidebar, section links, global search, active state, close-on-navigation and focus return.
- Exercise keyboard-only forms and overlays, accessible names, associated field errors, focus on first error, visible footers and saving/dismiss behavior.
- Check table captions/header semantics, named row actions, contained horizontal scroll, readable empty states and server-counted pagination.
- Check initial load/error, refresh with stale data, filtered/unfiltered empty, denial, unknown capability and new-sales-blocked maintenance states.
- Test office switching during read/save, out-of-order results, duplicate submits, 422/403/conflict recovery and exact money payloads.
- Run browser/axe acceptance after implementation. None of those implementation checks has been executed by this planning review.

## Final planning review

The same independent specialist reviewed the completed artifacts on 2026-10-24 and confirmed resolution of all three material findings: recurring generation starts only after explicit activation; invitation acceptance after resale-right loss has no Company/membership/link/allocation effects; immutable debit/credit adjustments have exact, locked balances and explicit reporting/delinquency effects. The follow-up review identified no remaining material planning blockers and made no file or runtime changes.

The final plan contains five delta capabilities, 26 requirements, 69 scenarios and 55 unchecked implementation tasks. Strict OpenSpec validation passes. Local documentation checks cover file links, scenario structure, task identities/verification, whitespace, all 33 installed component declarations and the six template fingerprints. The main checkout's prior dirty status is preserved and the specialist checkout remains clean. These are documentation checks; application, concurrency, migration and browser acceptance remain implementation tasks.
