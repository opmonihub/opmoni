# Frontend navigation lifecycle implementation evidence

Archive followup: on 2026-10-26 the user authorized synchronization and archival. See the [archive record](archive-record.md) for the canonical merge, preserved artifacts and documentation-only validation. The implementation checkpoints below retain their original scope and evidence boundaries.

Source: `2454fa8`, branch `codex/frontend-navigation-lifecycle`, isolated worktree `.worktrees/frontend-navigation-lifecycle`.

## Final acceptance

Change `refactor-frontend-navigation-lifecycle` is implemented: 24/24 tasks complete. Final product source is `3948e62f7ec7337c8aa6b8d8d60465e07eec2ad5`; subsequent commits record evidence only. Independent implementation/code-quality review and all focused followups are approved with no pending findings.

| Check on final product | Result | Evidence |
| --- | --- | --- |
| `pnpm --dir frontend lint` | Exit 0 | `/tmp/opmoni-navigation-accepted-lint-ready.log` |
| `pnpm --dir frontend typecheck` | Child exit 0, 432.17 s | `/tmp/opmoni-navigation-supervised-typecheck.json` and `.log` |
| `pnpm --dir frontend test` | Child exit 0; 2,245 passed / 267 suites; zero failures, cancellations or skips | `/tmp/opmoni-navigation-supervised-test.json` and `.log` |
| `pnpm --dir frontend build` | Exit 0; 18m06s; 30.1 MB / 7.71 MB gzip | `/tmp/opmoni-navigation-accepted-build-final.log` |
| Browser | Completed, 159 sanitized observations; no pending cases | `browser-acceptance.md` and `browser-evidence.json` |
| OpenSpec strict validation / diff whitespace | Passed | Final scoped closeout |

All 105 page files are accounted for exactly once: 78 have local/source verification and 27 are unchanged/reference. Browser evidence is explicit per page; unexercised routes are not promoted from component tests to browser acceptance. Artifact SHA256 is `8a4a5d610376999016a729524f78b7cb9203387619244c583603af80fdbd14de`; the coordinator independently recomputed the tree hash and verified the running entrypoint/source.

Main development checkout/stores, backend/schema, canonical and onboarding specs, environment files, dependency versions and production gates are unchanged. Changes remain on `codex/frontend-navigation-lifecycle`; this apply does not archive or integrate the branch. No live provider, fiscal/consent/financial UI command, or PostgreSQL/Redis-process concurrency acceptance is claimed.

The following sections retain the implementation and validation history; their pending statements describe their checkpoints, not final acceptance.

## Baseline

- 105 page files are inventoried once in `page-matrix.csv`. Candidate means an audited source trigger, not verified browser behavior. Unavailable means current lifecycle validation is pending.
- Planning artifacts were already present and untracked at entry. Main checkout has unrelated untracked `reference/sistema/`; it remains untouched.
- Locked frontend dependencies installed locally with pnpm 12.11.2 on Node 24.18.0; installation exited 0 without a lockfile change.
- Existing runtime wrapper is bound to a different checkout and branch. No runtime guard was bypassed. User authorized a separate runtime. Private network/volumes `opmoni-navigation-lifecycle-*`, backend loopback 18868, frontend loopback 3354, PostgreSQL 15468, Redis 16368; mounts point to this worktree. Additive migrations ran only in the new `opmoni_test` database. Proxied health returned 200. No original runtime guard or development store was changed.
- Existing onboarding artifacts, canonical specs, backend, configuration and production gates remain outside implementation scope.

## Progress

- Task 1.1: branch/base, locked versions, local dependencies, exact source mount, isolated stores and private ingress health verified.
- Task 1.2: all 105 files accounted for; evidence matrix initialized.
- Task 1.3: local RED regressions and stable pre-conversion Client/Work-template browser duplication captured before their fixes.

## Validation

Implementation and independent review are complete on the product source identified below. Final command exits and browser closure are tracked separately; earlier checkpoints are historical evidence.

## Implementation checkpoint before parallel execution

- Seven initial regressions failed for audited behavior: stale commercial navigation, three hydration payload callbacks, two Work URL writers, missing abort signal, overwritten dirty field. A further mounted settings-shell regression failed at two editor mounts after query-only navigation.
- Initial fixes: hydration cache callbacks, reactive commercial tabs, native settings child identity, scalar field draft reconciliation in shared section/email editors, shell-owned Work month URL writer, cancellable latest-read runner, lazy Admin subscription plan catalog, pending commercial context coalescing.
- Focused checkpoint: 124 tests passed, 0 failed; changed-path ESLint passed; `git diff --check` passed. Additional prior hydration-related suite: 135 passed. These are local checks, not final browser acceptance.
- Remaining areas include full draft sibling/conflict acceptance, comprehensive authority-owned list retention, safe-read ownership across families, first-capability watcher reconciliation, operational initialization, runtime/browser acceptance and full frontend matrix.
- User explicitly requested parallelization. Workers must use separate project-local worktrees and exclusive file ownership. Shared list-state interface and all integrations will be serialized.

## Integrated implementation and review checkpoint

Product source frozen for the first full build: `36370d2`. All six delegated domains used independent project-local worktrees and exclusive write paths; shared foundation/context changes were integrated sequentially. Workers and independent read-only review used `gpt-6.1-sol` with high reasoning for lifecycle, financial recovery and authority correctness. Tool routing telemetry beyond requested model/effort was unavailable.

- Authority-scoped minimal list state now covers all existing supported real surfaces; effective membership arrays and selected snapshots both invalidate state synchronously. No records, selections, secrets or mutation payloads are retained by the registry.
- Hydration callbacks reuse authorized payloads only while hydrating. Actual installed Nuxt engine tests cover populated, zero, null/404, failure, missing payload, retry and changed visits. Stable pre-fix authenticated browser reads independently confirmed two Client and Work-template primary GETs each; only those demonstrated raw-detail candidates were converted.
- Commercial context confirmation is app-owned and pending-only, atomically publishes capability state, coalesces reentrant consumers and preserves first-confirmation resource ownership. Page safe reads cancel independently; admitted commands/unknown-outcome recovery retain existing contracts.
- List filters/page restoration use existing codecs and native URL contracts; explicit Cliente q/pagina and Work/Fiscal/Calendar URL/history retain precedence. Real VueUse debounce regression verifies one correct history query. Equal primitive-array criteria preserve identity across page-only writes.
- Settings scalar baseline reconciliation preserves dirty fields and named feedback, exposes explicit conflict discard/review, reconciles owned save responses and synchronously clears on authority loss. Native editor identity preserves query/hash drafts.
- Compras/Vendas have one authorized initialization owner, including later authority changes. Real company contract invoice pagination is retained and server-bounded.
- Independent review findings were implemented with RED/GREEN evidence: Work same-scope absent/invalid URL; duplicate Sales authority reentry; effective vs selected membership invalidation; history/debounce stale query; reactive detail key plus duplicate generic owner; late Client/Work command feedback after authority loss. The Fiscal failed-read page clamp now waits for confirmed success.

### Validation attempts and boundaries

- Full frontend lint passed on the first integrated source; final lint is rerunning after final scoped fixes. Strict OpenSpec validation passed.
- First full test attempt was stopped after verified old test fixtures aliased every Nuxt useState key to one ref, causing registry recursion/heap growth. Only that task runner and its children received TERM. Fixture-only per-key maps fix the mismatch; both same bounded cases changed from OOM to passing, and all 61 affected fixture tests passed locally. Final full suite is running.
- First full typecheck reported one real event-handler signature mismatch after a loader became useAsyncData.refresh; the saved event now invokes a no-argument wrapper. Typecheck rerun is in progress, with no dependency/configuration change or resolution workaround applied.
- Final build and authenticated isolated browser acceptance are in progress. Local test counts from different workers overlap and must not be added into a fabricated whole-suite count. Provider delivery and PostgreSQL/Redis-process concurrency are outside frontend acceptance and were not tested.

### Final source verification status

- Independent read-only review of `2454fa8..36370d2`: Implementation Spec PASS / Code Quality APPROVED; no pending findings. Review did not run tests or claim runtime/provider acceptance.
- `pnpm --dir frontend typecheck` rerun exited 0 after the explicit no-argument saved callback correction. No NODE_PATH workaround or dependency change was needed.
- `pnpm --dir frontend test` final run is in progress after fixture corrections; `pnpm --dir frontend lint` final run and `pnpm --dir frontend build` are in progress on frozen product source `36370d2`.

- First full test retry continued past the corrected catalog fixtures and exposed a manual Fiscal draft harness import whitelist missing the real new safe-read helper; all nine affected tests use that harness. This is being corrected without replacing product assertions or altering commands. Test-run completion and any further failures remain pending.
- Scope audit on final product source found no backend/schema, canonical spec, onboarding-change, dependency/lockfile, Nuxt configuration or environment-file edits. All 105 baseline page paths are accounted for with local verification/source-review and separate pending browser columns.

### Completed matrix commands before browser acceptance

- Final `pnpm --dir frontend lint`: exit 0.
- Final `pnpm --dir frontend typecheck`: exit 0.
- `pnpm --dir frontend build`: exit 0, completed in 15m34s; output 30.1 MB (7.71 MB gzip). Existing bundler warnings included BigInt/es2019 target annotations in unchanged utilities and comment annotations; no configuration/dependency change was made to suppress them.
- Product source for the artifact is `36370d2`; current source `47535a1` differs only by the actual Fiscal test-harness import correction. No frontend product change occurred after the build started.
- Full suite final retry uses the exact package script after build/lint complete, avoiding same-worktree CPU contention. Logs: `/tmp/opmoni-navigation-full-test-accepted.log`. Earlier known-failing full attempts were terminated only through verified task PID/parent ownership and retained as diagnostic logs. No test timeout was increased.
- Browser QA now uses the built artifact on exclusive loopback 3354 with synthetic isolated stores; fingerprint and final acceptance are recorded in its separate report.

### Built-artifact browser checkpoint (not final acceptance)

QA launched the production artifact on exclusive loopback 3354. The full `.output` SHA256 is `5f0dd750b6332fe049e312984cdc751bc18a52c83b0fd7ed2de580f162cd5299`; entrypoint SHA256 is `ee075118ea0e1d9f857f9160a33bef55992ef6e2beb79bbea3352d7bd37b677f`. Actual server-rendered, completed-hydration checks report one primary GET each for populated Monitoring overview, stored office certificate, Client 1 and Work template 1. The real WhatsApp channel GET returned the configured feature-gate 404 and retained null/disconnected data with one hydration read; a populated synthetic response is a separate labeled acceptance case. The Client contacts and Work option/preview reads are distinct and counted separately. Each commercial tab admitted one context GET and one logical resource GET; settings and trial-link cards shared one context confirmation and each read its own resource once. Stable shell/history, dynamic IDs, failure/retry, Company/delegated drafts and viewport/theme acceptance are still in progress.

### Completed transition and compatibility acceptance

- Tasks 1.3/2.2: local RED regressions preceded their fixes; authenticated pre-conversion Client and Work-template direct entries each made two primary GETs. The built artifact makes one primary GET, reuses the authorized hydration payload, and SPA entity 1→2 transitions retain the document while replacing the native entity-owned heading/input and showing record 2.
- Task 3.1: all seven commercial tabs and Back/Forward retain the same actual non-null dashboard panel, navbar and navigation nodes; exactly one commercial destination is current. Real nested subscription/invoice reads return 200. Print entry returns 200, renders its issued snapshot, and excludes the dashboard/sidebar/navigation. Its SSR and client context checks belong to distinct Nuxt apps; they are not the simultaneous same-app configuration-card coalescing case.
- Task 4.6: the completed 2,225-test attempt passed the admission, auth, Admin lifecycle, CSRF/cookie/serialization/cancellation and commercial/operational recovery checks. Its only eight failures were one Fiscal static handler parser and seven SERPRO harness missing-helper bindings. Both test-only corrections passed their full focused files (75 and 18 tests respectively), without changing product code or timeouts. The complete matrix remains pending until the exact full script exits successfully.
- Final full-suite retry started on `6c26895`, which differs from the built product source only in test harnesses/assertion parsing. Log: `/tmp/opmoni-navigation-full-test-green.log`. Strict OpenSpec validation passed again.

### Browser-discovered selector integration correction

- Actual company settings returned 200 but the adjacent default-tax-profile control rendered an unresolved `ErpCatalogReferenceSelect` tag. Nuxt's generated auto-import name is `ErpCatalogsErpCatalogReferenceSelect`, reflecting its `erp/catalogs` directory. The existing component now has one explicit import in its consumer (`a51f4ee`); no new primitive, dependency, configuration or business action was introduced.
- The meaningful regression removes the test's synthetic global component alias and requires the imported reference control to render. It failed before the explicit import and all seven product-tax-profile tests passed afterward.
- A staged build uses the existing `NITRO_OUTPUT_DIR` configuration to generate a separate ignored artifact under `.superpowers/sdd/frontend-navigation-lifecycle/runtime/final-output`. The running original production artifact and its counters remain intact for concurrent QA. Final typecheck is rerunning on `a51f4ee`. Browser acceptance must repeat the previously blocked adjacent-save flow on this artifact before closure.

- Independent read-only follow-up reviewed the exact `36370d2..a51f4ee` delta, confirmed the one-import product correction and retained semantic assertions/helper bindings, and maintained Spec PASS / Quality APPROVED with no new findings. This review did not execute tests or browser flows.

### Actual Work transition correction and final source

- On production artifact `36370d2`, direct Tarefas month 2026-08 read one grouped dataset, but SPA Clientes month 2026-09 admitted two grouped reads plus the departed Tarefas unscoped read; Back repeated the duplicate pattern. The outgoing component remains alive inside Nuxt Suspense while the shared month changes. Correct URL state alone did not establish read ownership. Task 3.6 was reopened on this evidence.
- `aaafa97` adds a 17-line Work read owner around the existing authority lifecycle. The actual Vue Router destination enables only its monthly page; Nuxt's native `enabled` cancels pending setup and prevents obsolete executions, and synchronous route-loss clearing removes retained results/selection before disposal. The one shell URL writer and native entity identity remain unchanged.
- Four new regressions execute actual page read declarations and the installed Nuxt async-data engine, retaining both page scopes across navigation/Back, exercising same-page month changes, initial server handler, pending cancellation and late ownership registration. They passed, alongside 21 existing focused Work tests and changed-path lint. Independent read-only review approved with no new findings; actual SSR router and final browser counts remain required.
- The preceding exact full suite exited 0: 2,226 tests / 267 suites, no failures, cancellations or skips. After the Work correction a new exact complete script runs on final product `aaafa97` (`/tmp/opmoni-navigation-final-source-tests.log`).
- A concurrent typecheck/build attempt failed only on missing generated Nitro globals. Both write Nuxt-generated type paths; final verification will serialize these commands, without product/dependency/configuration changes or a resolution workaround. The first independent typecheck had passed before the one-import and Work corrections.

- Complete frontend suite on `aaafa97`: exit 0, 2,230 tests / 267 suites, zero failures, cancellations, skips or todos; duration 846,918 ms. Log `/tmp/opmoni-navigation-final-source-tests.log`.
- Browser injected read-401 revealed queued Admin watchers could read again after actor invalidation while the page remained mounted. The test deliberately retained a valid backend session; subsequent 200s did not demonstrate a backend authorization bypass. The Admin owner is adding a current actor/Super Admin read-admission guard, preserving synchronous cancellation/clear and authorized reentry. Actual mounted Usuarios → real API plugin → real `useAuth.invalidateSession` regression passes with one initial GET, no post-loss GET, actor null and one login navigation. Independent source review approved the bounded delta; final focused integration and full matrix remain pending.

### Final frozen product and isolated checks

- Final product source: `3948e62f7ec7337c8aa6b8d8d60465e07eec2ad5`, including the reviewed Admin gate. Its final focused check passed 135 tests with zero failures/cancellations/skips; changed-path ESLint and diff checks passed. Commands, transport admission and backend authorization were preserved.
- A dedicated clean detached worktree `.worktrees/navigation-final-checks` contains the exact same commit and independent dependency files (distinct resolved paths/inodes). Typecheck, lint and the complete frontend suite run there while the build runs in the implementation worktree; no two processes generate the same Nuxt type directory. This honors parallel execution without repeating the earlier generated-file collision.
- Final command logs: `/tmp/opmoni-navigation-accepted-{build,typecheck,tests,lint}-final.log`. Build uses the existing private output override and `.superpowers/sdd/frontend-navigation-lifecycle/runtime/accepted-output`; the browser's preceding artifact stays available during compilation.
- Actual company browser acceptance on the one-import artifact confirmed a real adjacent profile PATCH 200 preserving the exact unsaved IE draft; a real revision-conflict PATCH 409 preserved the draft and offered explicit review; a remote-field conflict preserved dirty input and exposed explicit discard. Six actual editable Company viewport/theme combinations (320/390/1440, light/dark) had resolved controls and no document overflow. The final Work/Admin delta still requires targeted artifact verification.

- In the new check worktree, lint was first launched before Nuxt generated `eslint.config.mjs`; it exited 2 with a missing generated module. The exact lint script was restarted after that file existed; no tracked configuration was changed.
- The final isolated typecheck attempt exited 143 without TypeScript diagnostics while build/lint/test were active. No specific killer or memory cause was independently established. It will rerun after build completion; no success is inferred from its partial output.

- Final full lint on isolated source `3948e62`: exit 0 (`/tmp/opmoni-navigation-accepted-lint-ready.log`).
- Additional actual Company browser checks on `a51f4ee`: named IE validation returned PATCH 422 and marked the field invalid; adjacent profile PATCH 200 preserved both invalid draft and named field feedback. Own Sistema save returned PATCH 200 and reload preserved exact CST `01` and rate `1.6500`. No financial/provider operation was used.

- Final build on frozen `3948e62`: exit 0, 18m06s, 30.1 MB / 7.71 MB gzip; artifact `.superpowers/sdd/frontend-navigation-lifecycle/runtime/accepted-output`. Existing unchanged BigInt/es2019 and annotation warnings remain recorded in `/tmp/opmoni-navigation-accepted-build-final.log`.
- Final complete suite output contained 2,245 tests / 267 suites, all passed, no cancellation/skip/todo, but the outer tool reported exit 143. This is not recorded as a package-script exit-0 result. Both unresolved scripts now run through a private supervisor recording the exact pnpm child return code and source in `/tmp/opmoni-navigation-supervised-{test,typecheck}.json`; no timeout, dependency or configuration adjustment was made.
- Browser completed the missing state/viewport combinations without repeated API admissions: load each state once, then resize/theme all six combinations. Actual empty Monitoring 200, missing certificate 404/null, injected Company 403/409, injected Monitoring 503 and actual retry 200 produced 36 passing state/geometry checks. Final 401 login-state matrix follows on the guarded artifact.

### Final artifact Work/Admin acceptance

- Final source `3948e62f7ec7337c8aa6b8d8d60465e07eec2ad5`, output tree SHA256 `8a4a5d610376999016a729524f78b7cb9203387619244c583603af80fdbd14de`. The QA owner replaced only the task's own production listener after build exit 0.
- Actual Work counts now pass: direct Tarefas 2026-08 grouped 1 + own unscoped 1; SPA Clientes 2026-09 grouped 1 and departed unscoped 0; Back Tarefas grouped 1 + own unscoped 1. Missing/invalid direct month each normalized with one grouped read. Task 3.6 closed on this final browser evidence.
- Actual final Admin injected list 401 clears actor/protected DOM, navigates to login, and admits zero further protected users GETs. Only the public registration-status 200 follows.
- Final source smoke confirms Client 2 and Work model 2 each one entity GET, Monitoring one SSR overview, persistent actual nonnull commercial document/panel/header/navigation, and configuration context/settings/trial-link once each.

- Supervised final `pnpm --dir frontend typecheck`: actual child return code 0 on `3948e62`, elapsed 432.17 seconds. Its independent source/dependencies generated no workaround or tracked change. Exact result: `/tmp/opmoni-navigation-supervised-typecheck.json`.
- Final browser acceptance complete: Company selector resolved, query/hash retained the same IE input and draft without a settings GET; Compras returns and Vendas orders each read only the chosen list/references once. Actual UI logout returned 204, subsequent real `/me` returned 401, actor was null. QA closed only its named browser session and reset injection controls to `{}`; isolated review runtime remains available. Final sanitized report records 159 observations.
- Tasks 5.2/5.3 closed on the final artifact plus explicitly source-bound earlier cases; 36 state/viewport combinations and six final login combinations supplement populated editor/list/theme/focus/print checks. This is not a claim that every one of 105 routes was individually exercised in a browser.

### Completed closeout

- Supervised final exact test script returned 0 after 650.22 seconds: 2,245 passed / 267 suites, zero failed/cancelled/skipped/todo. Its explicit child return record resolves the earlier outer-process 143 ambiguity without changes to tests, timeouts, dependencies or configuration.
- All four frontend matrix commands passed on final product `3948e62`; type/lint/test used an exact-source detached worktree with independent dependencies and generated files while the build used the implementation worktree. Focused counts overlap and are not added to the full-suite count.
- The five capability deltas were reviewed together with the full product diff and the exact 105-page matrix. Backend/schema, canonical specs, unrelated onboarding change, package/lockfile/Nuxt configuration and tracked environment paths have no changes from `2454fa8`. The original checkout remains on `2454fa8` with only its pre-existing untracked `reference/sistema/`.
- Final browser report commit `63cabd8` is integrated; final task/evidence changes are committed separately. Runtime remains task-only on frontend 3354/backend 18868/PostgreSQL 15468/Redis 16368, with separate stores; browser session is closed and injection controls disabled.
