# ERP Dashboard UI Refactoring Implementation Plan

> **For agentic workers:** Start with `openspec-apply-change` for this change. Use `superpowers:executing-plans` for inline execution, or `superpowers:subagent-driven-development` if delegated implementation is explicitly selected. This file is the implementation ledger; do not resume the completed 2026-10-20 plan.

**Goal:** make existing ERP pages follow the local dashboard reference while fixing D01-D09 within the compatibility boundary below.

**Architecture:** retain native Nuxt UI components and business composables; centralize presentation tokens, add a controlled footer, and isolate read-only decimal formatting. Existing catalog/state/error components remain responsible for their current lifecycle and permissions.

**Tech Stack:** installed Nuxt UI 4.11.3, Nuxt 4.6.0, Vue 3.5.43, TypeScript, Tailwind, and the repository's Node/Vue test harness; no dependency changes.

**Spec:** [delta](specs/erp-company-foundation/spec.md), [design](design.md), [proposal](proposal.md). Source evidence: [2026-10-23 audit](../../../docs/reviews/2026-10-23-erp-dashboard-consistency/README.md).

## Global Constraints

- Use an isolated project-root `.worktrees/` checkout and a `codex/` branch; reuse a suitable existing worktree after checking its state. Preserve all existing dirty work and instructions.
- Preserve current routes, actions, authority/context keys, Company/Account/CSRF transport, server pagination, drafts, exact decimal payloads, and financial/fiscal recovery. Super Admin never implies tenant access.
- Preserve Public Sans, semantic green/zinc tokens, `pt_br`, the ERP light contrast correction, native 64px navbar, and one page gutter of `p-4 sm:p-6` at the default root size.
- Use actual installed component APIs and the local `.dashboard`; do not modify the reference, global office/admin table tokens, dependencies, schema, environment files, keys, gates, or backend application code.
- D06 remains compatibility work pending for unverified legacy identifiers. Never infer a native record from the same number, clear stored references on failure, disable currently supported editing, or declare full migration complete.
- Keep the audit immutable. Write implementation evidence under `docs/reviews/2026-10-23-erp-dashboard-consistency/implementation/`; never record credentials there.
- No database reset or operational replay. Mutation/invalid-submit browser checks use isolated backend stores. Concurrent database/session verification requires independent PostgreSQL/Redis processes.
- For reversible visual edits, use browser geometry and existing behavior suites; do not add tests that merely assert CSS class strings. Add focused tests for new event, state, validation, and formatting behavior.
- Every checkbox below is initially pending. A passing planning validator is not implementation or release acceptance.

## Review Focus

1. Office-plus-company, company-only, long names, collapsed sidebar, and mobile menu must preserve distinct reachable controls; owned by groups 1 and 9.
2. Initial/pending/error/stale/empty lists must remain distinguishable, with readable mobile empty guidance; owned by groups 2, 4, and 9.
3. Pagination mount, metadata replacement, pending/invalid clicks, and valid page changes must not duplicate loads or lose filters; owned by group 4.
4. Late validation after Company/Account/session/grant changes must not leak errors, fields, or success; owned by group 5.
5. Values beyond safe integer precision, fractions, unavailable references, and unchanged PATCH payloads must survive display improvements; owned by groups 6 and 7.

## 1. Fix sidebar context geometry — D01

**Files:** modify `frontend/app/layouts/erp.vue`; read `frontend/app/components/WorkspaceContextMenu.vue` and installed sidebar theme; use existing `frontend/tests/erpWorkspaceShell.test.ts`, `erpMobileShell.test.ts`, `erpSidebarNavigation.test.ts`, and `erpWorkspaceNav.test.ts`.

**Interfaces:** preserve sidebar/context selection and all navigation helper signatures. Produce only a correctly sized ERP sidebar host; no shared context component API change.

- [x] 1.1 Prepare a suitable isolated worktree and implementation branch, make the planning/audit artifacts available there, and verify ignore rules, dirty files, source revision, and runtime mounts. Run the four existing suites listed above and changed-path lint as the baseline; record failures without resetting data or unrelated work.
- [x] 1.2 Reproduce the office/company overlap in the browser before editing and record header/control/navigation rectangles; verify the reproduction matches audit D01 rather than relying on a stubbed renderer to prove geometry.
- [x] 1.3 Override the ERP sidebar header with `h-auto min-h-(--ui-header-height) py-3`, retaining native horizontal padding, collapse persistence, footer, mobile menu, and selection behavior; verify both controls fit inside the header and do not intersect navigation within a 1px tolerance.
- [x] 1.4 Run the four focused suites and `pnpm --dir frontend exec eslint app/layouts/erp.vue`; verify expanded/collapsed desktop, company-only, office-plus-company, long-name, and mobile navigation plus an unchanged office-shell sample. Retain before/after evidence and checkpoint only the accepted file set.

## 2. Keep finance empty guidance inside the mobile frame — D08

**Files:** modify `frontend/app/components/erp/ErpFinanceWorkspace.vue` and `frontend/tests/erpFinanceWorkflows.test.ts`.

**Interfaces:** preserve the finance composable API, toolbar action, selected tab/hash, current rows/meta, retry/reconciliation, and all mutating handlers.

- [x] 2.1 Add focused mounted-workspace cases for unknown, pending, failed, confirmed-empty, populated, and failed-refresh states; verify they expose the current defect or missing empty-state branch before implementation and never treat unavailable data as zero.
- [x] 2.2 Render native `UEmpty` outside the table's 48rem minimum-width content only for a confirmed empty result; retain populated table scrolling, prior confirmed rows, and failure feedback. Verify all state cases pass without changing finance request/payload behavior.
- [x] 2.3 Run `node --experimental-strip-types --test tests/erpFinanceWorkflows.test.ts` from `frontend/` and changed-file ESLint; at 390px verify the complete empty message fits the visible frame, a populated wide table remains locally scrollable, and page `scrollWidth` does not exceed `clientWidth` by more than 1px. Use isolated synthetic populated data rather than post a real financial operation.

## 3. Centralize gutters and align catalog filters — D02-D03

**Files:** create `frontend/app/components/erp/erpPresentation.ts`; modify `ErpCatalogPageShell.vue`, `catalogs/ErpCatalogResource.vue`, and `frontend/app/pages/erp/configuracoes.vue`. Read `frontend/app/utils/pageShell.ts` without imposing its different office density on ERP.

**Interfaces:** export `erpPageBodyClass = 'space-y-5 p-4 sm:p-6'`; preserve page-shell props and `actions`/`toolbar`/default slots and all catalog resource props/events.

- [x] 3.1 Implement the shared page-body token, make the settings native panel body padding-free, and keep one padded wrapper plus normal inner card padding; verify at 390px settings card/content x-coordinates are 16px/32px within 1px, while preserving `max-w-4xl`.
- [x] 3.2 Replace the nested catalog dashboard toolbar with an aligned responsive body filter row, retaining the labeled search, debounce/search lifecycle, counts, and stale/error/retry announcements; verify catalog search/table frame edges at x=16px mobile and x=264px at 1366px with a 240px sidebar.
- [x] 3.3 Run catalog list presentation/lifecycle, mobile shell, and settings API suites; run changed-file ESLint and record desktop/mobile measurements proving one gutter. Do not add source-string class tests or alter contract/public-registration exceptions.

## 4. Standardize active tables and controlled footers — D04

**Files:** extend `erpPresentation.ts`; create `frontend/app/components/erp/ErpListFooter.vue` and `frontend/tests/erpListFooter.test.ts`; modify `ErpCatalogPagination.vue`, `catalogs/ErpCatalogResource.vue`, and finance/cash/purchase/stock workspaces. Review `ErpReportMetrics.vue`, `ErpAccountingDeliveries.vue`, `pages/erp/cadastros/etiquetas.vue`, and `importacao.vue` for documented exceptions. Extend their existing behavior harnesses to mount or explicitly exercise the real footer adapter.

**Interfaces:** export `erpOperationalTableUi` derived from existing `panelTableUi` with `base: 'min-w-full table-auto border-separate border-spacing-0'`. Footer props are `{ meta: ErpPaginated<unknown>['meta'], pending?: boolean, summary?: string }`, default pending is false, and required model is `page: number`. It emits only valid, changed, idle user transitions and never fetches.

- [ ] 4.1 Add real-footer tests for zero/one/multiple pages, server `total`/`per_page`, mounting and metadata replacement without emissions, one valid page event, and no pending/invalid/unchanged events. Verify failure before the new component exists.
- [ ] 4.2 Implement the presentation-only adapter and native counted footer; verify the tests pass, counts use server totals, and pagination is omitted for a single page without hiding the confirmed count.
- [ ] 4.3 Apply the shared table surface and footer to catalog, finance, purchase, stock, and all three cash lists, wiring each existing page/load handler once. Keep catalog async announcements and retries in `ErpCatalogPagination`. Verify each affected harness retains filters, selected area, authority refusals, and exactly one admitted request per valid page change.
- [ ] 4.4 Record explicit dispositions for report/import/labels/accounting-detail tables: apply compatible tokens or justify the specialized presentation. Preserve selection/printing/import preview/captions and semantic HTML. Verify no active route depends on changes to the unreferenced `ErpModuleHub`; do not edit or delete it as cleanup.
- [ ] 4.5 Run footer, catalog list lifecycle/presentation, finance, cash, purchase, stock, report/export, label, and import-confirmation suites plus changed-path lint; inspect long content, action columns, numeric alignment, local horizontal scrolling, and narrow footer wrapping in the browser. Checkpoint the accepted table/footer change separately.

## 5. Reuse settings field metadata and show validation errors — D05, D07

**Files:** modify `frontend/app/utils/erpCompanySettingsFields.ts`, `frontend/app/components/erp/ErpSettingsSectionForm.vue`, settings `email.vue` and affected copy-only pages under `frontend/app/pages/erp/configuracoes/`; extend `frontend/tests/erpCompanySettingsApi.test.ts` and `erpCompanySettingsFields.test.ts`. Preserve the public API of `useErpCompanySettings.ts`.

**Interfaces:** reuse one exported `ErpSettingsField`, including existing key/label/type/required/options and optional description/hint. Bind existing reactive state and named fields; consume `apiFailure(...).errors` with unchanged settings PATCH keys and nested email payload shape.

- [ ] 5.1 Extend the real settings harness to retain field names/errors and add cases for a current 422, a form/network failure, retained edits, duplicate submit suppression, and late errors after Company/Account/session/write-grant changes. Verify the missing field-feedback cases fail before implementation.
- [ ] 5.2 Use the shared metadata type, forward description/hint/error, bind `UForm` to current form state, and show named validation plus form-level feedback. Clear feedback for new attempts or authority changes; verify no stale success/error can cross contexts and existing read-only roles still cannot submit.
- [ ] 5.3 Apply the same error policy to email fields, accepting nested `email_settings.<key>` paths without changing the serialized body; verify nested and form-level failure cases and existing API/authority tests pass.
- [ ] 5.4 Replace all visible Blade filenames, partial paths, and internal change identifiers across settings with accurate business guidance. Verify current functionality/unsupported placeholders remain honest in the browser and source inspection finds no internal migration copy in rendered descriptions.
- [ ] 5.5 Run settings API/field, administration, catalog reference, and catalog form suites plus changed-file ESLint; inspect loaded settings in desktop/mobile and exercise a rejected settings save only against isolated stores. Checkpoint the accepted renderer/copy change.

## 6. Verify legacy reference compatibility without changing IDs — D06 dependency

**Files:** read `backend/app/Erp/ErpCompanyOperatingSettings.php`, `backend/app/Http/Controllers/Api/V1/Erp/ErpCompanySettingsController.php`, settings payload/meta types, catalog routes, and existing reference selector. Extend `frontend/tests/erpCompanySettingsApi.test.ts`; create implementation evidence `implementation/reference-contracts.md` under the audit review directory.

**Interfaces:** retain the existing settings endpoint and all five stored scalar fields; no backend API, mapping, schema, or speculative selector is produced by this task.

- [ ] 6.1 Complete a field-by-field contract register for `default_unit`, `default_sales_tax`, `currency_id`, `purchase_currency_id`, and `cidade_id`: stored type, identifier namespace, label source, validation, permission, current/archived selection handling, and concrete missing dependency. Verify every conclusion points to source/API evidence and a same-number catalog record is never treated as proof.
- [ ] 6.2 Add preservation cases with opaque preexisting values and unavailable lookups; verify unrelated settings saves retain untouched identifiers and existing editing, do not auto-select native options, and do not clear values after failure or context changes. Run the settings suites and verify no backend/application/schema diff was introduced.
- [ ] 6.3 Document the exact scope of a later reference-contract/backend change for each unresolved field and mark D06 compatibility work pending in implementation results. Verify the coverage ledger does not claim removal of manual-ID editing or complete reference migration. Do not implement a guessed adapter to make this checkbox appear resolved.

## 7. Localize read-only decimals without rounding — D09

**Files:** create `frontend/shared/utils/erpDecimalDisplay.ts` and `frontend/tests/erpDecimalDisplay.test.ts`; modify read-only cell renderers in catalog `produtos.vue`, `fiscal.vue`, `unidades.vue`, `precos.vue`, and `grupos-clientes.vue`. Keep `frontend/shared/utils/erpCatalogs.ts` and existing form serializers unchanged.

**Interfaces:** implement `formatErpDecimalDisplay(value: string | number | null | undefined, options?: { minimumFractionDigits?: number }): string` exactly as design section 7; default minimum is 0, prices pass 2, and absent values return `—`.

- [ ] 7.1 Add failing tests for `12.5000 → 12,50` with minimum 2, `0 → 0,00` with minimum 2, `-10.1250 → -10,125`, `12345678901234567890.1234 → 12.345.678.901.234.567.890,1234`, null/undefined/empty `→ —`, and a non-canonical string retained unchanged; verify the input strings are not modified.
- [ ] 7.2 Implement the string-based display helper without converting decimal strings to floating point; verify every formatting case passes and no rounding or guessed currency symbol appears.
- [ ] 7.3 Use the display helper only in the listed read-only cells and verify actual form submissions still contain the original canonical decimal strings. Run new decimal tests, `erpCoreCatalogs.test.ts`, `erpCatalogForms.test.ts`, and relevant pricing/unit/fiscal presentation coverage plus changed-path lint.

## 8. Complete the route/form/overlay coverage map

**Files:** inspect the 97 files in the audit inventory; modify only demonstrated residual presentation mismatches in active forms, workspace details, and overlays. Create `implementation/coverage.csv` and record any specialized exception. Existing form/operation suites own behavior verification; no new universal editor or wrapper.

**Interfaces:** preserve `ErpCatalogField`, `ErpCatalogReferenceSelect`, contextual catalog slideover, archive confirmations, native dialog titles/descriptions, and every existing draft/error/recovery event. Maintain the catalog editor's 48rem desktop maximum, full-width mobile layout, and justified 64rem purchase dialogs.

- [ ] 8.1 Give all 40 page and 57 component inventory rows a disposition: changed, inherited shared correction, already conforming, or justified exception. Verify the count is exactly 97 and every active form/table family is represented; no bulk edits are required to claim coverage.
- [ ] 8.2 Fix only residual mismatches identified by that comparison using existing native components and chosen group/control/overlay spacing. Verify label/control/error association, read-only/busy behavior, visible first content, close/focus return, and access to final actions on desktop/mobile, with no custom UI primitive or changed business flow.
- [ ] 8.3 Run the affected catalog forms/editor/reference/authority suites and any touched purchase/stock/sales/cash/fiscal/accounting suites, plus changed-path lint. Verify dialogs retain edited values after a rejected submit and protected context changes still clear stale content; checkpoint only demonstrated fixes.

## 9. Validate the integrated refactor and retain acceptance evidence

**Files:** all accepted frontend diffs; create `implementation/result.md`, capture index, desktop/mobile screenshots, and computed geometry beside the baseline audit. No edits to completed historical plans or canonical main specs during implementation.

**Interfaces:** final finding ledger maps D01-D09 to implementation commits/evidence, with D06 explicitly pending wherever contract support is absent. Plan completion does not change ERP/fiscal/production gates.

- [ ] 9.1 Review the complete diff against the delta/design and require a focused independent read-only review before integration, through the available reviewer mechanism when execution is authorized. Verify no changed permission, query contract, serializer, speculative legacy mapping, global theme, generated reference file, dependency, or unrelated cleanup is included.
- [ ] 9.2 Run `pnpm --dir frontend test`, `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, and `pnpm --dir frontend build` against the final isolated checkout; run type generation/build serially. Verify exit codes and record any preexisting/unverified boundary rather than weakening tests or claiming a clean whole suite from the audit's earlier 85-test subset.
- [ ] 9.3 Compare the actual local reference with settled implementation screens at 1366×900 and 390×844 in light/dark mode, plus a 320px sanity check. Verify sidebar containment, one header/toggle, single gutters, aligned filters, table/action/selection reachability, readable empty states, controlled footers, overlay close/focus, field validation, and exact decimal display. Include office-plus-company and company-only identities; preserve denied/viewer/delegated access behavior and an office-shell regression sample.
- [ ] 9.4 Exercise successful, pending, failed, stale, empty, and context-switched paths with isolated synthetic state as needed; verify browser runs serve the implementation worktree and no external business/provider action is triggered. Record keyboard navigation and current component accessibility checks, clearly distinguishing emulated viewport from physical touch and sampled from exhaustive coverage.
- [ ] 9.5 Complete the 97-row coverage/finding ledger and acceptance report with commands/results, measured deltas, remaining D06 contract work, and rollback checkpoints; verify secrets are absent and owned temporary browsers/servers are closed. Run `openspec validate standardize-erp-dashboard-ui --strict --no-interactive` and `git diff --check`. Present the reviewable result without merging, pushing, archiving, or enabling gates unless separately requested.
