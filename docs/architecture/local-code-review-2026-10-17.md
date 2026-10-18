# Local code review and remediation — 2026-10-17

This report covers the local checkout at baseline `ec7ab813e628b3f1c9895c3fc4d7438f07c9ddf9`, including untracked application sources. Three specialist reviewers examined ERP, fiscal/accounting, and identity/integrations; the coordinator examined frontend/runtime and cross-checked the findings. The user subsequently requested local implementation.

The original inventory contained 136 changed tracked files and 1,097 untracked files, including 666 read-only reference files. This is a review of the application changes and their contracts, not an exhaustive audit of every legacy reference file.

## Evidence and scope

- Original backend baseline: 2,089 tests, 2,085 passed, one failed, three optional live-provider probes skipped; 11,881 assertions. The failure was reproduced individually: the old 90-day test conflicts with the new ten-day authorization deadline.
- Original frontend baseline: 685 tests passed; lint and typecheck passed.
- Strict OpenSpec validation: 28 items passed. Schema validation is not functional acceptance.
- Runtime bugs were reproduced with SQLite in memory, array sessions/cache, fake storage/transports or direct execution of the actual frontend loaders. Findings identified only by reading are identified below.
- No real fiscal, SERPRO or WhatsApp calls were made by this review. No historical messages were replayed and development data was not reset.
- PostgreSQL/Redis race acceptance and full browser workflows are separate from regular test evidence.

## Findings inventory

P1: correct before relying on the affected operation. P2: important correctness, resilience or acceptance defect. `Execution` means a local isolated reproduction; it does not mean live-provider acceptance.

| ID | Priority | Finding and reproduction | Evidence | Correction / regression requirement |
| --- | --- | --- | --- | --- |
| E01 | P1 | Operator with only `erp.read` can create payments; delegated allowlists are not enforced on reads | Execution + code | Enforce action permissions server-side, including downloads, direct memberships and delegations |
| E02 | P1 | Company A purchase accepts a variant owned by company B; sales/stock share missing checks | Execution + code | Resolve variants through company-owned products and reject invalid related IDs before any effect |
| E03 | P1 | A payment of 40 allocates 80 to an obligation of 100 | Execution | Bound total allocations by payment and obligation balances, with compatible status/currency |
| E04 | P1 | Sale quantity -2 posts total -20 and increases stock from 2 to 4; invalid decimals become zero | Execution | Validate decimal syntax, precision, signs and discount limits before posting |
| E05 | P1 | Repeated sale returns each restore all original stock; receivable is unchanged | Execution | Enforce returnable quantities and financial compensation atomically; reject unsupported partial/paid returns |
| E06 | P1 | Browser sends `movements_net=-100` and hides a 100 cash shortage | Execution | Derive net movements from authoritative postings, check cashier authority, preserve reconciliation |
| E07 | P1 | Production invalidation succeeds with production gate disabled; events share omitted guard | Execution + code | Gate all fiscal actions before provider calls, using model/environment/company/actor scope |
| E08 | P1 | Fiscal document linked to a sale of 20 has null commercial snapshot; payload defaults to zero | Execution + code | Freeze eligible posted-sale data and preserve source totals and lines |
| E09 | P2 | Removing modules from an active plan does not prevent operations | Execution | Enforce company module entitlements centrally for operating routes |
| E10 | P2 | Restricted preview allows a call without company/user context | Execution | Pass actual context and fail closed for missing or mismatching allowlist subjects |
| E11 | P1 | Default runtime provider is fake, but its simulated result becomes `authorized` and claims real authorization | Execution + code | Keep fake bindings explicit in tests; runtime without a live adapter must report unavailable |
| E12 | P1 | After 40 of a 100 obligation, a valid remaining payment of 60 is rejected | Execution | Use one balance contract; do not subtract allocation history from an already net open balance |
| E13 | P2 | Replaying allocation key revalidates changed balance or violates unique instead of returning the existing operation | Code | Reuse an idempotent participant; equal replay preserves balance, conflicting replay returns conflict |
| E14 | P2 | Manual operation keys collide across companies because unique source identity omits company | Code | Align company-scoped lookup and database uniqueness with an additive migration |
| E15 | P2 | Last purchase cost replaces cost history; configured FIFO/LIFO is not used | Code | Preserve truthful cost handling; fail explicitly where required valuation layers do not exist |
| E16 | P2 | Purchase conversion factor is stored but never applied to stock quantity | Execution + contract | Convert quantity and per-stock-unit cost from the frozen unit factor |
| E17 | P2 | PDF download contains a header/text fragment that is not a valid PDF | Code | Use a real model renderer or return explicit unavailability, never successful invalid bytes |
| E18 | P2 | Fiscal report filters all statuses by authorization date, excluding drafts and rejections | Code | Use lifecycle dates appropriate to each metric and test mixed-status periods |
| F01 | P1 | Accounting handoff returns delivered, but office reader cannot find XML on its fiscal disk; bytes are not reverified | Execution + code | Verify source SHA/length, publish verified bytes to the fiscal disk and establish readiness before delivered |
| F02 | P1 | Handoff reuses an existing Summary as its delivered full document | Execution | Include complete stage in identity, preserve Summary and create/reconcile the full document |
| F03 | P1 | Science queued before suspension/cancellation/conclusive manifestation does not recheck current eligibility | Code | Revalidate current eligibility at the sending boundary and prevent new transport after revocation |
| F04 | P1 | Post-science recovery accepts a parsed XML for a different recipient with no client relationship | Execution with provider double | Validate inbound recipient for this recovery path without breaking normal authorized-third-party capture |
| F05 | P1 | DeadlineMissed/NotSendable/Failed leaves Summary science_queued and scheduler never resumes it | Execution + code | Map terminal outcomes to attention; recover interrupted leased work safely |
| F06 | P2 | Historical reconciliation command fails 403 when no tenant was preselected | Execution | Run the entire command under explicit CurrentTenant context and restore the previous tenant |
| F07 | P2 | Fiscal summary/client aggregates count summaries/unavailable XML while the document list uses ready full XML | Execution | Share complete-document readiness scope across lists, model/period/client aggregates |
| F08 | P2 | A replayed Summary downgrades Complete to attention/ready; accepted science fails to route XML recovery | Execution | Distinguish complete, accepted-awaiting-XML and blocked conditions instead of one ineligible branch |
| F09 | P2 | Three budget deferrals plus one actual miss exhaust four recovery attempts | Execution | Count actual attempts separately from cooldown/budget/lock deferrals |
| F10 | P2 | A response containing only matching environment passes event correlation without key/type/sequence | Execution of correlator | Require complete event identity before applying definitive acceptance |
| F11 | P2 | Reconciliation verifies an arbitrary first 500; the unverified 501st missing artifact can be shown as available | Execution + code | Cover actual published candidates consistently, including >500 portfolios and relevant aggregates |
| I01 | P1 | ERP signup creates a global user with an email reserved by an active office owner invitation | Code | Share global email locks/reservation checks and verify legitimate activation remains possible |
| I02 | P1 | Delegation mutation commits after losing session ownership while waiting on company lock | Code | Bound lock waits and fence/revalidate owner within the transaction before commit |
| I03 | P2 | Crash between separate sent-status and message-ID writes leaves terminal send without receipt correlation | Code | Persist acceptance and gateway ID atomically while preserving advanced webhook statuses |
| I04 | P2 | Different-email signups race on one CNPJ and return 503 on unique collision | Code | Share CNPJ serialization and map only specific identity collisions to field validation |
| U01 | P1 | Public ERP registration route is redirected to login/onboarding by global middleware | Execution of actual middleware | Allow public signup to reach its own registration gate while protecting operating pages |
| U02 | P2 | Financial/overview report tabs call `/reports/finance` and `/reports/dashboard`, which do not exist | Route inventory + code | Align tabs to `/reports/financial` and `/reports/overview` |
| U03 | P2 | Real nonempty report objects are coerced to empty arrays and displayed as no data | Execution of actual loader | Separate report aggregates from paginated lists and render actual returned metrics |
| U04 | P2 | Late tab/search results replace the currently selected view | Execution of actual loaders | Invalidate responses by request generation, context changes and disposal |
| U05 | P2 | ERP lists fetch page one only and expose no control for subsequent records | Code | Add server pagination to module and catalog lists; reset page on search and preserve errors/retry |
| U06 | P2 | XML preview is fetched with GET despite being POST-only; stock history tabs also lacked GET endpoints | Route inventory + code | Provide explicit XML upload preview and scoped stock history routes, without hidden mutation retries |
| T01 | P2 | Legacy test asserts a 90-day science window against the current ten-day implementation | Full suite + isolated rerun | Update fixtures and assert authorization-based deadline boundaries, including future dates |

## Functional gaps and conservative implementation limits

These are not evidence that a real provider performed an invalid action. They are missing behavior in existing local flows or acceptance prerequisites.

| ID | Gap | Required treatment |
| --- | --- | --- |
| G01 | Posted purchase creates stock but zero financial obligations (executed) | Join purchase payable creation to the posting transaction and roll back all effects on failure |
| G02 | Posted purchase return only changes status, without stock/finance compensation (executed) | Implement consistent full reversal within existing models; partial and paid returns require supported line/reconciliation models |
| G03 | Fiscal submitting state and provider effect share a rollbackable transaction (code) | Keep live dispatch unavailable until durable intent/reconciliation is implemented; do not disguise the fake as live |
| G04 | Live model/certificate validation and renderer support are incomplete (code) | Fail closed on unsupported runtime dispatch/rendering; no live acceptance claim from fake tests |
| G05 | Operational ERP pages mostly expose lists, without complete creation/posting/export workflows (code/spec) | Correct misleading write claims and broken reads now; record remaining workflow scope explicitly rather than claiming full feature parity |

## Review exclusions and rejected hypotheses

- Ordinary ERP HTTP posting races that assumed no company lock were rejected: ResolveCompany serializes the request on its company row. Separate background/multi-process acceptance still matters.
- Foundation access/settings lacking an operating-release guard was not classified as a defect: the current contracts distinguish foundation access from operating writes.
- Calling a queue job's handle twice by hand does not prove a production worker leaves work permanently queued; worker tries/failure callbacks must be considered.
- The wrong-recipient recovery probe demonstrates missing local validation, not that the real fiscal authority returns unauthorized XML.
- Reference source code was consulted for contracts; it was not modified or treated as the target architecture.

## Remediation and verification

Implementation is tracked in [the local remediation plan](../superpowers/plans/2026-10-17-local-review-remediation.md). Corrections are applied to the current checkout without commits, pull requests, dependency changes or discarding existing work.

### Correction status

| Findings | Result |
| --- | --- |
| E01–E04, E09–E10 | Action/read/download permissions, company-owned variants, strict decimal validation, payment caps, subscription entitlements and real preview context enforced. CT-e/MDF-e model resolution prioritizes the actual route resource, preventing an unrelated body ID from bypassing the entitlement. |
| E05, E12–E14, G01–G02 | Shared idempotent payment allocation, consistent open balance, company-scoped operation keys, purchase payables and atomic full stock/finance reversals. Numeric-string IDs replay consistently. Paid or partial returns explicitly rejected. |
| E06 | Cash close rejects client-supplied aggregate movements and reconciles opening against declared cash. A complete cash movement ledger is still absent. |
| E07–E08, E11, E17, G03–G04 | Frozen commercial snapshots and consistent release/production guards. Runtime uses an unavailable provider rather than simulated authorization. Unsupported live fiscal dispatch and PDF rendering return 503. |
| E15–E16 | Purchase unit conversion applied. Homogeneous-cost stock movements and compatible full reversals preserve cost; mixed-cost receipts, unsupported LIFO and mismatched-cost returns are rejected. Full lot valuation remains absent. |
| E18 | Report date filters follow each fiscal lifecycle metric. |
| F01–F02 | Handoff verifies original bytes/hash/length, publishes to the fiscal disk, preserves complete-document identity and metadata, and leaves Summary separate. |
| F03–F05, F08–F10 | Execution eligibility, recipient validation, terminal and leased processing states, recovery routing, actual-attempt counting and complete event correlation corrected. |
| F06–F07, F11 | Explicit console tenant context with restoration; shared complete-document readiness for lists/aggregates; chunked verification covers portfolios beyond 500 rows. |
| I01–I04 | Shared email/CNPJ serialization and reservations; specific collision validation; bounded delegation locks and session fencing; atomic receipt identity/status persistence preserving advanced webhook status. |
| U01–U06 | Public signup route, registered report endpoints, actual aggregate metrics, stale-response guards, server pagination, explicit POST XML upload, scoped stock history reads and API validation messages corrected. |
| T01 | Legacy deadline fixture updated to the ten-day authorization contract with boundary/future-date regressions. |
| G05 | Read/report/navigation defects corrected and misleading write claims removed. Complete ERP creation/posting/export screens remain outside this correction. |

### Independent review

Specialists reviewed the owned patches. The final independent review found three further P2 issues: transport fiscal entitlement, numeric-string replay identity, and purchase reversal against replenished stock with a different cost. All were reproduced and corrected. A further entitlement bypass through an unrelated body document ID was also reproduced and corrected. The final reviewer reported no remaining P1/P2 in the examined correction scope; this is not an exhaustive audit of the application.

### Local runtime and acceptance limits

- The additive migration `backend/database/migrations/2026_10_17_230001_scope_erp_operation_keys_to_company.php` is included and exercised by migrated test stores. It remains pending in the development database. No development migration or queue restart was performed.
- Real fiscal, SERPRO and WhatsApp provider acceptance remains untested; no historical effects were replayed.
- Public signup returned HTTP 200 through the running frontend. Authenticated browser workflows were not completed; browser startup timed out under host resource pressure.
- Partial/paid returns, complete cashier ledger, full stock lot valuation, live fiscal issuance/consultation/events/invalidation, real auxiliary PDF and full ERP mutation screens remain explicit limitations. Unsupported unsafe operations fail rather than fabricate success.

### Integrated verification

Final integrated verification completed successfully:

| Check | Result |
| --- | --- |
| Backend Unit + Feature | 2,161 total; 2,158 passed; 3 optional SERPRO probes skipped; 0 failures; 12,277 assertions |
| PostgreSQL/Redis process concurrency | 19 passed; 347 assertions; 0 failures |
| Frontend | 693 passed across 152 suites; 0 skipped/failures |
| Frontend lint and typecheck | Both exit 0 |
| Production Nuxt build | Exit 0; isolated output `/tmp/opmoni-remediation-build` inside the frontend container |
| Required PHP formatting | `vendor/bin/pint --dirty --format agent`: passed |
| Strict OpenSpec | 28 passed; 0 failed |
| Diff/snapshot checks | Clean whitespace; original files retained; environment files unchanged; baseline HEAD retained |

The skipped backend tests are `SerproPgdasHomologationProbeTest::test_probe_real_auto_center_quando_pre_requisitos_existem` and the two optional `SerproTrialContractTest` probes. They do not establish external-provider acceptance.

The PostgreSQL run covers `ErpCommercialConcurrencyTest`, `ErpCompanyConcurrencyTest`, `ErpDelegationConcurrencyTest`, `ErpFiscalAccountingConcurrencyTest` and `WhatsAppConcurrencyTest`, using isolated test stores. Same-session signup contention may return the existing bounded-lock 503: the regression requires the exact `LockTimeoutException`, one successful registration and a subsequent duplicate-email validation response. The distinct-session/different-email CNPJ race requires one success and one field-specific 422.

Local evidence logs: `/tmp/opmoni-remediation-backend-final.log`, `/tmp/opmoni-remediation-pgsql-final.log`, `/tmp/opmoni-remediation-frontend-final.log`, `/tmp/opmoni-remediation-lint.log`, `/tmp/opmoni-remediation-typecheck.log`, `/tmp/opmoni-remediation-build.log`, `/tmp/opmoni-remediation-pint-final.log`, `/tmp/opmoni-remediation-openspec.log`. These temporary logs are evidence for this run, not durable repository artifacts.


### Main implementation paths

- ERP boundaries: `backend/app/Http/Middleware/ResolveCompany.php`, `backend/app/Support/CurrentCompany.php`, `backend/app/Erp/Finance/`, `backend/app/Erp/Stock/`, `backend/app/Erp/Fiscal/` and the existing ERP controllers.
- Fiscal capture/publication: `backend/app/Erp/Accounting/ErpAccountingHandoffService.php`, `backend/app/Services/Fiscal/Processing/`, `backend/app/Services/Fiscal/Read/`, manifestation/recovery jobs and the reconciliation command.
- Identity/integrations: `backend/app/Actions/ErpCommercialRegistration.php`, `backend/app/Erp/ErpCompanyAuthorization.php`, `backend/app/Jobs/SendWhatsAppDocumentJob.php`.
- Frontend: public access middleware, `ErpModuleHub.vue`, `ErpReportMetrics.vue`, `ErpPurchaseXmlPreview.vue`, `ErpCatalogPagination.vue`, catalog loader and eight catalog pages.
- Behavioral regressions: `ErpReviewRegressionTest`, `ErpAccountingPublicationRegressionTest`, `ErpDelegationMutationSafetyTest`, `FiscalWorkflowRegressionTest`, `FiscalPointRecoveryRegressionTest`, existing integration workers/tests and `frontend/tests/erpReviewRegression.test.ts`.

To activate the additive index correction in development, apply only its migration, then restart long-lived local workers after reviewing the running stack:

```sh
docker compose exec -T backend php artisan migrate --path=database/migrations/2026_10_17_230001_scope_erp_operation_keys_to_company.php --no-interaction
docker compose restart queue scheduler
```

The migration preserves rows and widens company-scoped uniqueness. Its rollback deliberately refuses unsafe narrowing once different companies can reuse the same operation key. These activation commands were not run by this remediation.
