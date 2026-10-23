# ERP local acceptance — 2026-10-23

The ERP canonical completion plan passed its local acceptance gates in the isolated `erp-finalization` worktree. This covers local application behavior, independent PostgreSQL/Redis processes and native browser workflows. External fiscal-provider acceptance and production rollout remain separate, unverified gates.

Application checkpoint: `8d7acc4e55c7bb18d577120a9d4699610e85de61`, branch `codex/erp-finalization-recovery`. The backend tree is identical to the tested backend checkpoint `0db2bc870e2fc3c96ebaeac8d76ef56edf7e9297`: Git tree `2ae940a0d8bc7fcc047b2a14bd5da1294f09f03b`. Subsequent reconciliation changes are documentation only. The browser used Nuxt build `0eda4cfb-88f0-4569-bd95-da4fb7efd0e4` from that application checkpoint.

## Verification

| Gate | Observed result and basis |
| --- | --- |
| Backend Unit + Feature | 3,136 tests, 26,580 assertions, three skips, zero failures; isolated final backend source |
| Full PostgreSQL/Redis Integration | 187 tests, 14,274 assertions, zero failures; independent processes and barriers, serial execution |
| Frontend tests | 1,438 passed across 209 suites, zero failures or skips |
| Frontend lint | Full application ESLint passed; preserved generated `.output*` directories excluded |
| Frontend typecheck | Global Nuxt typecheck passed on the identical twelve-file frontend candidate; committed bytes and final runtime identity verified |
| Frontend build | Production build passed into its own output directory; source, manifest, server entry and served build verified |
| PHP formatting | Changed backend paths passed Pint, including the final seven test-fixture paths |
| OpenSpec | Strict validation passed all 27 canonical specifications, zero failures |
| Native source/runtime postflight | Full backend/vendor/private-stage/frontend/build/runtime verification passed after the business scenarios; no job executed by this check |

The earlier full-suite fixture failures were corrected in seven test files at `0db2bc8`, preserving production issuer, authorization and financial checks. The final complete backend and Integration runs above supersede those failing runs. Native execution subsequently exposed three frontend gaps: purchase detail-to-edit modal overlap, editable settings for read-only roles, and missing operation-nature selection on commercial sources. Their twelve-file correction at `8d7acc4` passed focused review, the final frontend gates and actual role/desktop/mobile retakes.

The original combined canonical review and its scoped correction review remain the source-review record. The final evidence closure checks the later gate and native results; it does not claim another broad source audit.

## Native workflow evidence

All fifteen local acceptance rows passed: foundation, catalogs, CSV, purchases, inventory, sales, finance, cash, fiscal, accounting, reports, roles, Company context, responsive behavior, and private catalog documents/labels.

- Fresh synthetic actors covered owner, manager, operator, viewer, office administrator, office read-only member and platform-only Super Admin. Tests used two Companies, multiple establishments, explicit office delegation and approved Client mapping. Super Admin received no implicit tenant access.
- Native catalog creation, validation retention, CSV preview/cancel/confirm, private note/attachment restrictions and real downloads passed. Downloaded label SVG bar geometry decoded as the expected Code 128 values; no physical-scanner claim is made.
- Purchase XML preview/cancel/confirm, draft editing, receipt and bounded supplier return passed. FIFO and LIFO transfers between establishments used the expected source layers and were jointly reversed. The corrected purchase modal retained one visible dialog and correct focus on desktop and mobile.
- Quote/order conversion, counter sale, payment/allocation, reversals, receipts and cash closing passed. A paid partial return created an independent opposite credit without refunding or offsetting the original money automatically.
- Actual Company switching, context clearing, denied foreign reads, establishment-scope withdrawal, role permissions, read-only settings, failed-read recovery and desktop/mobile light/dark behavior passed. The delayed-request exercise proved serialized switching; it does not claim forced adoption of a late response from the previous Company.

### Financial reconciliation

Native day-filtered BRL reports and actual JSON downloads were checked against the private stored export hash/length and parsed contents. Pretty-printed browser downloads intentionally have different raw hashes from compact stored JSON.

| Final main-establishment measure | Observed result |
| --- | --- |
| Original posted sales / sale-return credit | BRL 90 / BRL 15 |
| Original posted purchase / supplier-return credit | BRL 200 / BRL 20 |
| Stock quantity | 19.5 units |
| Known stock cost / quantity without proven cost | BRL 180 / 1.5 units; aggregate inventory value remains unknown |
| Gross obligations / open obligations / signed net exposure | BRL 350 / BRL 260 / BRL -170 |
| Net payments / net allocations / cash movements | BRL 90 / BRL 90 / BRL 100 |
| Preserved closed cash sessions | BRL 120 and BRL 30, both zero difference |
| Fiscal states | One authorized, one canceled and one unissued queued document |

The branch establishment had zero final stock, sales, purchases, finance and fiscal totals. Final post-cancellation reads passed 33 checks, including twenty complete endpoint comparisons against both earlier financial captures. All original sale, payment, allocation, return-credit and closed-cash DTOs were unchanged. Report comparison also preserved all nonfiscal rows and totals; only declared observation timestamps changed between snapshots.

### Fiscal and accounting boundaries

The browser uploaded a synthetic self-signed certificate and entered the typed NF-e configuration and source fields. Explicit, reviewed, exact-ID CLI fixtures then exercised the ordinary fiscal jobs: preparation, indeterminate transmission, consultation with verified authorization, and cancellation with processed-event evidence. Normal HTTP runtime binding remained `UnavailableErpFiscalProvider`; no fixture was installed into the ordinary web application. These are local backend contract proofs, not SEFAZ authorization or real-provider UI transmission evidence.

An unmapped accounting delivery was actually blocked with `client_mapping_missing`. An office administrator approved a new mapping revision, preserving the revoked revision. Native owner retry followed by a neutral ordinary accounting job delivered the original XML once. A subsequent fresh document's original and accepted cancellation event were delivered through separate durable accounting projections. Exact current leases, source/signature/schema/correlation, scope and private artifact bytes were checked by the reviewed workers.

The office UI searched the authorized document, displayed its detail and timeline, and downloaded the original XML before and after cancellation with identical bytes. For the final document, original XML was 4,924 bytes, SHA-256 `98780627b615d1b7a57519c6bef24971929e0591fb09962716a4dbd317ec426e`. Its DANFE was generated and checked for document key, product and homologation notice. The processed cancellation evidence was 3,360 bytes, SHA-256 `a344f75a4d6fe8a3e8364ba50b0427b6f5fb5bf69d3b8b1e0f91df991e8830c1`; its byte verification is backend evidence.

The cancellation appeared exactly once in the original note's timeline. Standalone events are excluded from ready accounting-document rows and return 409 from normal document detail/XML endpoints, as required by [fiscal-documents](../../openspec/specs/fiscal-documents/spec.md). No separate event XML download through that UI is claimed. The original document download and the non-conclusive science/XML notice remain available; XML availability does not establish recipient manifestation.

## Migration and retained state

The additive upgrade of a separate native database clone advanced the migration count from 105 to 106 and preserved all 121 pre-existing row-bearing tables. Guarded rollback behavior is covered by [ErpVerifiedEventProjectionMigrationTest.php](../../backend/tests/Feature/ErpVerifiedEventProjectionMigrationTest.php); rollback refuses to discard accepted event evidence. Operational rollback uses a compatible application version, without resetting data or rotating `APP_KEY`.

Failed synthetic fixture invocations remain visible rather than being rewritten or replayed. One unissued document retains pending attempts 10/11. An earlier authorized document retains cancellation attempt 17 in `attention_required`, correctly rejected because the fixture response used an unsupported UTC offset. Its original office XML remains intact. A fresh document with a schema-compatible `-03:00` response completed the positive cancellation scenario. The isolated unique queue retains fifteen unconsumed messages and zero claimed messages; no queue consumer, cleanup or historical retry was run.

One minor office-integration badge still says approval is pending after approval; persisted mapping history, current API state, owner retry and delivered office XML correctly show the active revision. This is retained as a nonblocking copy issue.

## Handoff

Private execution evidence is retained under `.superpowers/sdd/2026-10-18-erp-canonical-completion/` in the worktree: `progress.md`, `task-20-final-local-acceptance.json`, `task-20-native-business-acceptance.json`, the final evidence closure review, raw gate outputs, native reports/screenshots/downloads, source/runtime postflight and preserved-state records. These local artifacts are intentionally not published with this document.

The branch, all historical build outputs, private uploads and environment files are preserved. No merge, push, archive, production gate change, identity recreation or development-store reset occurred. `ERP_REGISTRATION_ENABLED=false` remains unchanged. Live fiscal/SERPRO acceptance, real certificates and per-model/UF/provider canaries remain unverified; local completion does not authorize production issuance or broader rollout. CT-e authority and other model-specific release gates retain their existing restrictions.

During this final continuation, the requested subagent model was `gpt-6.1-sol` at high reasoning effort; runtime routing telemetry was unavailable. Astra was excluded from this continuation. Earlier model selections remain in the historical ledger.

## Local integration and demonstration handoff

The user subsequently authorized local integration, a seeded demonstration account and temporary-environment cleanup. The accepted branch was merged into local `main` at `8b14877ee9476a8564e8519b20d62a6fe5ce6649`; no push was performed. Fresh verification against that merged checkout passed 3,136 backend tests with 26,580 assertions and three external SERPRO skips, plus all 1,438 frontend tests across 209 suites. Both runs verified unchanged tracked source and HEAD. The earlier PostgreSQL/Redis acceptance above remains separate evidence; that suite was not rerun during this integration step.

The main Docker Compose application was rebuilt and its additive migrations applied after a private database backup. The existing `APP_KEY`, previous identities and persistent development stores were preserved. Local Docker UID/GID settings were aligned with the workspace owner. Frontend, backend, PostgreSQL and Redis health checks passed; queue and scheduler remained running.

A new, separate demonstration user owns an ERP Company and administers a separate office Account through the canonical memberships. The office contains 20 synthetic clients, 38 processes and 76 tasks. The Company has an active demonstration subscription, the eight existing ERP modules, a main establishment, customer, supplier, three products and 20 ledger-backed opening units per variant. Opening cost is explicitly unknown. The seed did not overwrite an existing user's password or reuse an existing Company. Actual browser login, Company selection, product listing and stock balances passed at `http://localhost:3000`.

Local ERP operation writes are enabled, with report/accounting preview scoped to the new user and Company. Public ERP registration and production release remain disabled. The ordinary fiscal binding is still `UnavailableErpFiscalProvider`; no external issuance or SERPRO acceptance is implied. Test credentials are retained privately outside Git and supplied directly to the user.

OpenSpec now has zero active changes and 13 completed archives; all 27 canonical specifications passed strict validation in `main`. The platform proposal's existing archive and synchronized specifications arrived through the merge, without creating a duplicate archive.

Private execution evidence has been preserved outside the removable worktree under `/home/obsidian/.local/state/opmoni-finalization-20261023/`, including `erp-private-evidence.tar.gz`, all 51 frontend builds, host storage/configuration, native storage and logical PostgreSQL/Redis snapshots. Manifests record integrity checks and the exact temporary-runtime cleanup scope. These private archives supersede the worktree-only evidence location described in the original acceptance handoff above.

After verification and archival, cleanup removed the 15 inventoried temporary containers, five networks, six temporary volumes, two preview servers and the historical Task 11 test processes. The merged `erp-finalization` worktree and recovery branch were removed without force. The temporary verification backend clone and dependency bytes were archived before removal. The main application, its five persistent volumes, unrelated environments and pre-existing local instruction files were preserved.
