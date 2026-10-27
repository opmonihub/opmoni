# System UI integration validation

Current final result: [final.md](final.md). Sections below preserve the actual validation/correction history; older pending statements are superseded only by explicit later results.

Change: `standardize-system-ui-patterns`. Checkout: `.worktrees/erp-management-density`; baseline `dac7293`. Work remains uncommitted and unmerged.

## Independently verified behavior

- PostgreSQL query-family checks: 34 tests / 254 assertions passed for commercial ListOrdering, AdminListFilters and ErpCoreCatalogs. Separate establishment-scoping ordering regression: 1 test / 8 assertions passed. These establish the tested null placement, ties, counts, related-client ordering, defaults and scope; they do not establish the complete PostgreSQL suite or concurrent command acceptance.
- Catalog lifecycle, modal/resource authority and presentation: 57 tests passed. Regression first reproduced two replacement-authority reads; the correction retains explicit URL criteria and issues one new-authority read.
- Settings, onboarding, Office plans and Admin pages: 179 tests passed after updating harness imports/slots to real composition boundaries. Office plan follow-up: 17 passed. Focus-return regression first failed with no restored opener, then passed with the shared programmatic-modal helper.
- Full Nuxt and contracts typecheck exited 0. Host Pint passed earlier; final repeat follows any further PHP edits.

## Browser evidence and limits

The isolated app uses a synthetic API at port 18869 and independent PostgreSQL/Redis test services. No development stores or provider commands are used by the browser fixture.

The confirmed commercial pilot matrix includes 32 paired views in both themes, desktop and phone (canonical 320px), with clients additionally at intermediate widths. Native column visibility corrected reproduced phone clipping; ID-owned expansion keeps details attached to the record after server reordering. Office plan invalid submission focused the first invalid input at 320px and retained the draft through a canceled discard. Confirmed discard returned focus to the Editar plano opener after the overlay transitions. Catalog unit creation received synthetic422: entered sigla/name remained, invalid sigla received focus, discard returned to Adicionar unidade. These are UI interaction results, not live command acceptance.

The first broader consumer capture attempt is **not accepted**: hydration was still pending in some frames, a light phone frame became dark, and the browser session stalled. Its files remain diagnostic evidence; do not label them confirmation. In particular the first 1046px-panel frame displayed an empty result and establishes geometry only, not displaced-record reachability. Repeat after the production build/runtime is stable.

## Full checks in progress

- Clean full frontend: `/tmp/system-ui-clean-frontend-tests.log`. One 30-second commercial fiscal review timeout observed; final counts pending. The isolated rerun also exceeded its time budget while other host workloads ran. No timeout or domain code has been weakened to produce a green result.
- Lint: `/tmp/system-ui-final-lint.log`; build: `/tmp/system-ui-final-build.log`.
- Regular host PHPUnit: `/tmp/system-ui-full-backend-debug.log`, FISCAL_ENVIRONMENT=producao, process-only generated 32-byte test key. Current errors include certificate CSR generation. The same failing certificate case reproduced in the unchanged base checkout (`/tmp/system-ui-baseline-certificate.log`, AccountContractingCertificateApiTest:294); this is preexisting host/test evidence. Final counts pending.

The initial full-frontend failures (route boundary, old slot stubs, previous mobile-list assertions) were real validation failures and were corrected; initial run must not be described as green. A mis-sized temporary PHP key invalidated one intermediate run; only the restarted valid-key run counts.

## Decisions and pending gates

Ruling: keep existing ERP operational, report, dense-sheet and grouped-Work compositions when their shared primitives already satisfy the proposal. Shared styling changes propagate through their existing helpers; no domain rewrite or new query/bulk feature is justified by a common visual language. Cost if wrong: an individual divergent consumer needs a bounded follow-up, recorded in the ledger.

Ruling: require final explicit integration/finalization; applying this change authorizes implementation and verification, while existing project artifacts reserve merge/push/cleanup. Cost if wrong: an additional integration step rather than loss of unrelated drafts.

Pending: full check outcomes, stable consumer captures and complete keyboard/nested product-modal browser flows, independent review findings, consumer-ledger acceptance reconciliation and strict OpenSpec validation. Production fiscal/SERPRO, provider delivery and concurrent financial command gates remain separate and unverified.

## Review correction checkpoint

Independent review returned two Important findings and no Critical findings. Legal-name sort labels and mobile task assignment/discard access were corrected in one pass; both regressions first failed, then6/6 focused tests passed. No new query or mutation capability was introduced. The current-source build is /tmp/system-ui-reviewed-build.log; the earlier build was stopped after the review changed client-bundled presentation, so it is not accepted as final-source evidence.

Full frontend ended2277 tests:2271 pass,1 failed test file parsed before its syntax correction,5 canceled under the two fiscal test budgets. Corrected shared selection regression is green; the two fiscal suites are being repeated serially with unchanged test budgets. First previously timed-out fiscal review case passed in23.9 seconds in that rerun.

- Serial rerun of the two fiscal suites completed31/31 passed with original30s case/120s suite limits. Full run plus corrected-test/fiscal reruns are composed evidence, not a clean single whole-suite green run.
- Process-start OPENSSL_CONF corrected the independently reproduced certificate failure:1 test,22 assertions passed. The broad run lacking process-start configuration is not claimed green.

- Post-review formatter finished with exit0 on all review-touched components/composables/tests. Full lint was rerun on the formatted final sources; /tmp/system-ui-reviewed-lint.log. No formatter runs concurrently with source edits.
- The regular host run without process-start OpenSSL config was stopped after1058 completed cases because its certificate failures were already reproduced and diagnosed. Corrected complete-suite attempt uses process-only32-byte APP_KEY, FISCAL_ENVIRONMENT=producao and OPENSSL_CONF=/etc/ssl/openssl.cnf with stop-on-first-error/failure; /tmp/system-ui-corrected-backend-suite.log. This is an explicit validation boundary, not a passing claim for the interrupted run.

- Full `pnpm lint` on the reviewed/formatted sources exited0 (/tmp/system-ui-reviewed-lint.log). The official Nuxt+contracts typecheck passed before the final presentation corrections; the exact vue-tsc/contract-tsc stages are being repeated against the current generated Nuxt configuration without regenerating it concurrently with Nitro build.

## Clean frontend suite

The final reviewed-source `pnpm test` completed2280 tests across267 suites:2280 passed,0 failed,0 canceled,0 skipped. This is a clean complete run, distinct from the earlier composed/timeout evidence. Log: /tmp/system-ui-reviewed-full-tests.log. Full `pnpm lint` also exited0 on these reviewed sources.

- Final reviewed-source `pnpm build` completed successfully in20m43s, producing30.4MB Nitro output. The runtime capture phase uses this built output at3368, with synthetic backend18869. Raw log contains a prior-parent termination segment; actual final session63547 exited0 and the final complete bundle output is decisive. Existing dependency annotation/es2019 BigInt warnings were emitted; no dependency/target configuration was changed.

## Native product-modal browser flows

At320px on isolated production output: native nested variant archive dialog opened; Escape closed that dialog only and returned focus to its archive trigger. Fourteen real Tab presses all kept focus inside the editor. A synthetic8-second PATCH kept the editor mounted on Escape and disabled cancel/save. The422 response preserved the entered product name and focused the corresponding invalid input. Continue-editing retained the draft; backdrop click requested explicit discard; confirmed discard unmounted all dialogs and returned focus to Ações de Produto sintético. Document width stayed320px. Evidence: product-modal-keyboard.json and product-modal-interactions.json in the task capture directory.

## Complete regular backend result

With process-start OPENSSL_CONF, synthetic32-byte process-only APP_KEY and FISCAL_ENVIRONMENT=producao, host regular PHPUnit finished3835 cases:3829 passed,6 skipped,35217 assertions. The log ends with PHPUnit Shell Exit Code0 and the complete JSON passed report. No environment files or development stores were changed. The long-running tool carrier returned143 after completion; its cause is unestablished and is recorded separately. PostgreSQL ordering checks remain the separate34/254 plus1/8 results.

## Final browser correction gate

Production SSR revealed equal normalized criteria writes could recurse when a synchronous consumer reset the current page. Real SSR reproduction first failed with stack overflow; registry setters now publish only changed public criteria. The Admin native form error is emitted while loadingAuto still disables inputs; delayed focus after Vue updates now waits for release. Menu-opened Admin editors capture the native menu's labelled persistent row trigger rather than the disappearing menu item. Combined tests for these boundaries, dirty-modal policy and Admin callers:82/82 passed. These findings require final full frontend/build/typecheck validation beyond the earlier2280 green snapshot.

- Final PHP Pint --dirty --format agent returned passed. Scoped lint for SSR/form/menu-focus fixes passed after test formatting. Production consumer matrix currently contains74 frames across19 consumers; all captured geometry reports match requested light/dark theme and viewport document width. Team SSR500 is a diagnosed product-boundary issue fixed in source and remains pending rebuilt confirmation, rather than being counted as a successful capture.

- Production1046px actual main panel (1286px viewport minus240px sidebar) now confirmed with populated client rows and native first-record expansion. Legal name,CNPJ,next billing and portfolio selection remain reachable; viewport/document1286px. Paired expanded light/dark captures and geometry are recorded separately from the earlier invalid empty frame.
- Admin subscription mobile editor retained changed Inadimplente status across canceled discard; native select Escape closed only the select first, then editor Escape requested dirty confirmation. The final rebuilt focus behavior is still a separate confirmation gate.

- Sales mobile create flow retained an entered item description and canonical9007199254740993.0100 unit price after a synthetic422; viewport/document320px and existing editor/footer stayed reachable. This checks input preservation and exact text, not a successful financial command or calculated live total. Existing domain workflow tests cover posting/recovery separately.

## Final browser-corrected frontend suite

After the SSR no-op setter and native form/menu focus corrections, complete `pnpm test` passed2283/2283 tests in267 suites,0 failures/cancellations/skips,665496.8ms. Log /tmp/system-ui-final-corrected-tests.log. Final corrected build and full lint are /tmp/system-ui-final-corrected-build.log and /tmp/system-ui-final-corrected-lint.log. No whole-suite repeat is needed unless source behavior changes again.

## Final corrected build and SSR confirmation

Final corrected build completed successfully in4m50s; full lint exited0. A fresh browser session was required after replacement of built assets. Team and portfolio certificate-list direct SSR entries both returned200; Team rendered actual synthetic member rows and controls, and the new runtime log contains no RangeError. Paired final screenshots are /tmp/system-ui-final-confirmation-captures.log and production-consumer-matrix.json. These supersede the earlier Team500 and old-session stale-chunk redirect; no source workaround to permissions was used.

- Final Team phone capture is populated and visually inspected: stacked search/department controls, permitted invite and row actions remain reachable; no page overflow. Direct SSR Team and portfolio status200 are confirmed independently of captures.

- Final native Admin-plan validation focused the invalid input v-0-41 with aria-invalid=true and disabled=false. Confirmed dirty discard unmounted every dialog and returned focus to the persistent Ações de Plano sintético trigger. At320px the document width remained320px. Evidence final-admin-focus.json. This verifies the actual corrected native loading/menu timing rather than only the unit stubs.

- Final official `pnpm typecheck` (Nuxt plus contract tsc) exited0 after the build and all browser corrections. Log /tmp/system-ui-final-corrected-typecheck.log. Final complete gates now include2283 frontend tests, lint, typecheck, build, host regular PHPUnit and Pint, plus isolated PostgreSQL focused ordering checks.

- Final commercial phone navigation: focused native section selector, Space opened all7 route choices, ArrowDown+Enter navigated Clients to Contracts. Expanded contract retained plan/price/cycle, licence,Company,agreement,owner-setup,next billing,balance and record action at320px without document overflow. No duplicate page identity or alternate phone data list was introduced.

- Final Work phone table mode allowed eligible task selection, announced1 tarefa selecionada, exposed the new Mais ações da seleção trigger at x238px within320px, and opened native Atribuir responsável/Dispensar choices. Clearing selection used the existing owner; no synthetic group ID or mutation was sent. Document width remained320px.
