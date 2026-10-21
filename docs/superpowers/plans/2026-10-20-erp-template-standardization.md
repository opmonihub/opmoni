# ERP Template Standardization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Coordinator owns Git, runtime and browser; one source writer at a time, independent preparation may run concurrently.

**Goal:** Restore the actual dashboard template's hierarchy and expandable navigation throughout existing ERP screens.

**Architecture:** Preserve existing Nuxt UI components, API contracts and authorization. Centralize navigation metadata; extend the existing ERP page shell with named action/toolbar slots; use on-demand native editors while retaining workflow state and scoped drafts.

**Tech Stack:** Existing Nuxt 4, Vue 3, Nuxt UI 4, TypeScript and Node test runner; no installs.

**Spec:** `docs/reviews/2026-10-20-nuxt-dashboard-ui-ux/erp-standardization-design.md`

## Global Constraints

- Work only in `/home/obsidian/dev/opmoni/.worktrees/erp-finalization`, starting at `810506f`. Preserve unrelated untracked files, especially `frontend/tests/erpCashWorkflows.test.ts` and runtime outputs.
- Reference `/home/obsidian/dev/opmoni/.dashboard` and existing office Settings composition; no reference edits.
- Preserve current routes, API/CSRF/Account/Company boundaries, backend permissions, exact monetary strings, financial operation recovery, private receipt/file handling and all accepted Task 11 behavior.
- Preserve Public Sans, semantic green/zinc tokens and the scoped light ERP contrast correction. User-facing copy is Brazilian Portuguese; code and reports are English.
- No backend, transport/auth, dependency/lockfile, env, schema, production gate, fixture, service or shared runtime mutation by workers. No subdelegation, Git/index writes, installs, full builds or browser work by workers.
- Use a sole source writer; preparation and review are read-only. Root owns commits and final validation. Do not implement missing ERP operations or unrelated office fixes.
- Run focused actual behavior tests, changed-path ESLint and final Nuxt/contracts typecheck; avoid source-text-only tests that merely mirror markup.

## Review Focus

- Exact granular/family read grants and direct-owner settings visibility must not broaden access or leave empty groups.
- Mobile expansion keeps navigation open; leaf selection closes with focus restored; collapsed desktop popovers and active deep links remain reachable.
- Overlay transitions retain drafts across area/close changes, but authority changes clear them and prevent stale callbacks.
- Failed/ambiguous financial writes remain recoverable without duplicate effects or false close/success.
- Catalog detail/files/privacy and transient list state remain intact while an overlay replaces inline editors.

### Task 1: Expandable navigation and template page shell

**Files:** `frontend/app/layouts/erp.vue`, `frontend/app/utils/erpModulesNav.ts`, new `frontend/app/utils/erpWorkspaceNav.ts`, `frontend/app/pages/erp/configuracoes.vue`, `frontend/app/pages/erp/cadastros/index.vue`, `frontend/app/components/erp/ErpCatalogPageShell.vue`, relevant existing/new navigation tests. Read actual catalog routes/components for grant mapping.

**Interfaces:** Preserve existing exported navigation helper signatures used outside ERP. Produce a typed shared registry/helper in `erpWorkspaceNav.ts` for catalog/settings navigation and sidebar groups, accepting full current Company where owner/delegation gating needs it. Existing module contracts supply hash children. Finance child targets are `/erp/financeiro#accounts`, `#obligations`, `#payments`; default Finance is obligations. Reports/hubs retain their existing hashes. Do not change finance source in this task. `ErpCatalogPageShell` adds optional `#actions` (navbar trailing/right with current mobile context controls retained) and `#toolbar` (inside `UDashboardToolbar`); default content and title/description props remain compatible.

- [ ] Inspect actual Settings/default layout expansion and installed NavigationMenu APIs; write behavioral tests for active descendant/default area, exact grants/empty groups, owner versus manager/viewer/delegated settings and mobile trigger versus leaf selection.
- [ ] Implement native expanding groups with stable values and active-child expansion, text-only children as in Settings, correct collapsed popovers. No duplicate navigation implementations or guessed permission rules; retain module leaf when it has no children.
- [ ] Use shared catalog/settings metadata in page navigation; replace the unwieldy fifteen-item settings strip with compact meaningful groups/selection using native components while preserving every supported destination. Follow the reference settings frame and one navbar; avoid inaccessible hidden tabs. Include desktop collapse/mobile toggle in the shared shell, settings and layout headers without duplicate controls.
- [ ] Run focused nav/shell tests and changed-path lint; report exact commands/results and interfaces. Typecheck is serialized by coordinator when no other worker generation is active.
- [ ] Self-review and write task report. No commit: coordinator accepts and checkpoints.

### Task 2: List-first Finance with native editors

**Files:** `frontend/app/components/erp/ErpFinanceWorkspace.vue`, finance form SFCs if draft interface is required, focused `frontend/tests/erpFinanceWorkflows.test.ts` and optional focused presentation test. Minimal consumed Finance composable edit only if required for success acknowledgement/draft lifecycle; no auth/transport changes.

**Interfaces:** Consume shell `#actions` and `#toolbar`. Synchronize selected Finance area with validated route hash `#accounts|#obligations|#payments`, default obligations; handle deep links/history without redundant requests or self-triggering loops. Preserve existing finance composable public behavior. Existing callers with no explicit hash keep current default.

- [ ] Read accepted finance workflow tests and source. Add meaningful component regression for no initial entry editor, explicit open/cancel/focus, area deep link/history, retained field values after close/area switch, failed submit remaining open and context invalidation clearing editor/drafts.
- [ ] Replace persistent creation sections with native modal/slideover entry, using actual installed APIs for lifecycle/presence. Safe drafts must not disappear through overlay unmount; context generation must still clear them. Keep lists/filtering visible at rest; primary New account/obligation/payment action in navbar, native area navigation in toolbar.
- [ ] Preserve allocation/reversal/receipt/details/operation recovery and exact permissions. Keep permission-denied/unknown/read-failure states honest; do not imply zero records before any successful read. Translate visible raw Finance enum labels when exact domain mappings exist, with safe unknown fallback.
- [ ] Run the complete focused Finance and relevant auth lifecycle suites, changed-path lint, then report. Typecheck only when coordinator grants generation lease. No build/browser/commit.

### Task 3: Catalog editors and remaining ERP composition

**Files:** `frontend/app/components/erp/catalogs/ErpCatalogResource.vue`, `frontend/app/components/erp/ErpModuleHub.vue`, `frontend/app/components/erp/ErpReportsWorkspace.vue`, focused catalog presentation/editor/authority/list tests and relevant hub/report tests. Existing catalog form SFCs only if necessary for retained draft interface. Do not edit the Task 1 shell or navigation registries without coordinator ruling.

**Interfaces:** Consume Task 1 shell slots. Catalog public APIs/props, row-action grants, real forms/notes/files and transport remain stable. Native overlay lifecycle replaces the inline editor; list remains mounted and view state preserved. Hubs/reports retain their route hash and permission/state contracts.

- [ ] Add focused actual component checks for explicit catalog creation/detail/edit open, closed default editor, preserved validation/drafts on failures, cancel/focus and authority clearing; retain existing catalog privacy/action/list regression coverage.
- [ ] Move catalog primary create to navbar and open focused native modal/slideover on demand. Preserve detail tab contents and current source-owner permissions. Keep native reference table/panel styling, typed status labels, list selection/filter/pagination and transient errors.
- [ ] Move existing report/hub navigation into shell toolbar, align page identity/actions/filters with the template and avoid repeated heading/card layers. Keep incomplete module consultation descriptions honest; no invented domain tables/actions or expanded API scope.
- [ ] Run affected catalog/report/hub focused suites and changed-path lint; report exact paths/results. Final types/build/browser remain coordinator-owned.

## Final validation

- [ ] Coordinator reads all diffs and receives independent task spec/quality verdicts; resolve material findings with original writer and scoped re-review.
- [ ] Run combined changed-area suites, frontend lint/typecheck, production build once source is accepted; known unfinished cash RED test is outside this UI scope and must be recorded instead of “fixing” it.
- [ ] Coordinator directly exercises representative list/create/cancel/validation/context paths, expansion/leaf/deep-link/collapsed/mobile navigation, settings group selection and light/dark screenshots; compare against the actual template.
- [ ] One focused final cross-task review; retain user-reviewable artifacts and report completion/remaining acceptance. No merge, push, archive or unrelated ERP task restart.
