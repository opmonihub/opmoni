# Standardize system UI patterns: final implementation

Implemented from baseline `dac7293` and integrated locally into `main` as `1188900` (`refactor(ui): standardize system patterns and neutral actions`). OpenSpec tasks55/55 completed, including the user-reviewed neutral-button refinement. The user selected local integration; no push or OpenSpec archive was performed.

## Local integration acceptance

The merged `main` frontend passed2284/2284 tests in267 suites, with zero failures/cancellations/skips (802776ms). The regular backend passed3829 cases, with6 skipped,35217 assertions and zero failures/errors (3835 total cases,774348ms). Both processes exited0. Logs: `/tmp/opmoni-merged-frontend.log` and `/tmp/opmoni-merged-backend-corrected.log`.

An initial merged-backend run exposed development-environment leakage: the main checkout's existing `.env` enables ERP registration, while the regular test profile expects the closed-registration default. Reproduced that case as failing, then fixed only `backend/phpunit.xml` to force `ERP_REGISTRATION_ENABLED=false` for the regular SQLite suite. Initialization/registration tests passed23/23 with181 assertions before the successful complete rerun. The PostgreSQL profile retains its intentional setting; the development `.env` was not changed. Correction: `e78fa8c`; OpenSpec whitespace cleanup: `fcf32a0`.

Before cleanup, preserved26 overlapping untracked planning/report files under `.superpowers/integration-backups/20261027T201515Z`. Preserved the task's complete `.superpowers` evidence directory and163 additional ignored local files under `.superpowers/archived-worktrees/erp-management-density-20261027T204439Z`; its manifest records the source worktree and paths. The existing unrelated `reference/sistema/` remains untouched. Source-file hashes confirmed that the test-profile fix was the only implementation/configuration change after the merged-suite snapshot. Remaining final documentation changes describe this verified result.

## Final user-reviewed button refinement

Operational buttons now default to Nuxt UI's native neutral palette: dark solid on the light theme and light solid on the dark theme. Replaced 37 explicitly green operational actions across 22 Vue files, including the conditional financial-allocation confirmation. Existing outline/ghost treatments, selected-state accents and success/warning/error colors remain semantic. No handlers, request payloads or permission conditions changed. The demonstration customer modal and two public trial recovery buttons retain explicit primary accents.

Initial follow-up verification: 163 selected tests passed (59 presentation/theme/sales/settings checks plus 104 finance/Work/client checks), full ESLint passed, official Nuxt and contract typecheck passed, and the production build passed in 9m12s. The branch-finishing verification below subsequently reran both complete regular suites on the final color changes.

### Branch-finishing verification and OpenSSL environment correction

The complete final frontend suite passed 2284/2284 tests, with no failures, cancellations or skips (`/tmp/opmoni-finishing-frontend.log`, process exit 0). File hashes confirmed no changes during that validation.

The first backend rerun omitted process-start `OPENSSL_CONF` and reported 56 failures plus 563 errors, all in certificate fixture creation/signing. An isolated CSR/signing probe reproduced the failure without that variable and passed with `/etc/ssl/openssl.cnf`. PHPUnit XML/bootstrap assignment occurs after OpenSSL initialization on this host. No application-code fix or global environment change was needed. Updated the existing README host command to pass `OPENSSL_CONF` before PHP starts.

With the corrected process environment, the previously affected fiscal case and certificate API class passed 18 tests/209 assertions. The complete regular backend rerun then passed 3829 tests, with 6 skipped cases, 35217 assertions, zero failure/error events and process exit 0 (3835 total cases, 712917ms). Evidence: `/tmp/opmoni-openssl-full-backend.log`, `/tmp/opmoni-openssl-full-backend.exit`, `/tmp/opmoni-openssl-focused.log`, `/tmp/opmoni-openssl-certificates.log`. SQLite/array isolation and synthetic process-only application key remained in force. No additional PostgreSQL/live-provider acceptance is inferred.

The final production output was inspected against the isolated synthetic API. Captured Clients, commercial Settings, Work model and company catalog at 1440px and 320px in light/dark: 16/16 cases passed native neutral styling, minimum measured primary-button contrast 17.716:1, and no document-level horizontal overflow. Desktop Clients and dark mobile Settings were visually inspected. Evidence: `.superpowers/system-ui-patterns/captures/button-neutral/evidence.json`; paired PNGs are in the same directory. These are synthetic layout checks, not live provider or financial acceptance.

Logs: `/tmp/opmoni-neutral-focused.log`, `/tmp/opmoni-neutral-extra-tests.log`, `/tmp/opmoni-neutral-lint.log`, `/tmp/opmoni-neutral-typecheck.log`, `/tmp/opmoni-neutral-build.log`, `/tmp/opmoni-neutral-captures.log`. Updated shared design guidance records the approved neutral operational-action hierarchy.

## Result

- Reused the existing Nuxt UI panel, table, Settings-row and selection compositions across commercial, catalog, Admin and retained operational families. Preserved task-specific dense Work/monitoring, fiscal inspection, reports and financial scrolling.
- Added authoritative resource-allowlisted ordering before pagination for catalogs, office-commercial and platform lists, preserving defaults, envelopes, current authorization, nulls-last and stable ties. Admin plans retain bounded complete-array local ordering.
- Wired criteria/URL/history and stable row identities; same normalized writes are no-ops so synchronous SSR consumers cannot recurse. No navigation memory contains records, selections or drafts.
- Consolidated compact client/contract expansion across viewports; plans use subtle horizontal cards and native module disclosure. Added mobile Work assignment access using existing domain actions.
- Migrated the four bounded editor hosts to native modals, preserving command recovery, dirty/busy dismissal and read-only catalog inspection. Confirmed invalid focus after native loading release and persistent row-trigger return after nested/menu closure.

## Baseline verification before the final color refinement

| Check | Result |
| --- | --- |
| Complete final frontend tests |2283/2283 passed;267 suites;0 failures/cancellations/skips |
| Complete frontend lint | Passed |
| Official Nuxt + contract typecheck | Passed |
| Final production build | Passed;4m50s;30.4MB output |
| Complete host regular PHPUnit |3829 passed,6 skipped;35217 assertions |
| Host Pint --dirty | Passed |
| Isolated PostgreSQL query families |34 tests/254 assertions passed |
| Selected establishment ordering |1 test/8 assertions passed |
| Independent review | No Critical; two Important findings corrected RED→GREEN; production verification findings also covered by meaningful regressions |
| OpenSpec strict validation / diff whitespace | Passed |

Final logs: `/tmp/system-ui-final-corrected-tests.log`, `-lint.log`, `-typecheck.log`, `-build.log`; backend `/tmp/system-ui-corrected-backend-suite.log`, Pint `/tmp/system-ui-final-pint.log`; PostgreSQL evidence in acceptance.md/resume.md. The complete PHPUnit log reports Shell Exit Code0 and its passed JSON report; its long-running tool carrier later returned143, recorded as a separate carrier anomaly of unestablished cause.

## Browser evidence

Paired local dashboard reference/commercial pilot, desktop/intermediate/phone themes and canonical320px; populated1046px main-panel native expansion; production consumer matrix and final rebuilt Team/portfolio SSR200. Native product nested-dialog Escape,14 Tab containment checks, pending-save guard,422 focus/draft retention, backdrop/dirty/discard and focus return were exercised. Final Admin native invalid input was focused after loadingAuto release and dirty discard returned to the persistent row action. Mobile commercial section navigation worked with Space/ArrowDown/Enter. Mobile Work selection announced its count, exposed assignment/dismissal and cleared through the existing owner.

All browser API responses were isolated synthetic data. Live SERPRO/fiscal providers, actual financial delivery and concurrent financial commands were not accepted by these captures. Existing production gates remain unchanged. Broader historical PostgreSQL establishment rollback/stream-identity failures are preserved as non-green broad-suite evidence; the focused PostgreSQL ordering checks above are independently green.

## Decisions

1. Preserve existing dense operational/report/Work compositions where their shared primitives already satisfy the specification. Cost if wrong: a bounded divergent consumer follow-up; no undocumented domain rewrite.
2. Retain legal-name ordering and label its control Razão social. Cost if wrong: a separate display-name query field would need API/spec work.
3. Preserve explicit integration/finalization as a separate step. Cost if wrong: one additional integration step; unrelated drafts and branches remain safe.

No deferred Minor review findings. Existing dependency annotation/BigInt target warnings during build are recorded without changing dependency or target configuration.

## Preservation

No new dependencies/lockfile changes, migrations, environment file/key changes, production rollout toggles, merge/push, automatic worktree cleanup or development-data reset. Main product checkout is unchanged; planning/evidence copies are synchronized. Existing interrupted drafts, root `reference/sistema/` and the read-only review snapshot remain preserved.
