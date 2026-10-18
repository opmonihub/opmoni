## 1. Backend Metadata and Durable Processing

- [x] 1.1 Add failing parser and deadline tests separating NF-e issue and authorization dates, including missing/future authorization, the ten-day boundary, cancellation/denial and historical cases; verify the tests fail for the current ninety-day issue-date behavior without provider traffic.
- [x] 1.2 Add nullable authorization, provider-status, trusted-delivery provenance, storage-state/verification, processing-state, due-time, attempt and bounded-lease fields through additive migrations, casts and typed contracts; verify migration preservation, historical unverified defaults and null behavior in isolated database tests.
- [x] 1.3 Extract provider authorization/status through the existing XML parser and writer without changing issue dates or NSUs, and stamp delivery provenance only from a trusted authenticated capture invocation matching Account/client/source/recipient/environment; verify parser/writer tests and refusal of XML/user-supplied provenance claims.
- [x] 1.4 Implement the shared eligibility decision requiring persisted trusted recipient-delivery evidence, certificate/Account/client state, cancellation/conclusive events and the ten-day authorization window; verify eligible/ineligible reasons, historical unverified exclusion and environment/recipient mismatches with unit/feature tests and assert no automatic `210200`, `210220` or `210240` path exists.
- [x] 1.5 Implement short-transaction processing claims/transitions and after-commit initial dispatch, preserving completed rows and accepted audits; verify rollback, stale-lease recovery and duplicate-discovery feature tests.

## 2. Science and Asynchronous XML Recovery

- [x] 2.1 Correlate event responses with requested key/type/sequence/environment and distinguish linked acceptance, unlinked acceptance, duplicate and definitive rejection; verify fake responses cannot accept another event or claim full XML from lote success.
- [x] 2.2 Refactor the science job to recheck eligibility and scoped gate at execution, fence duplicate sends, persist stable retry/attempt state and dispatch completion only after committed acceptance; verify gate-off, blocked, expired, duplicate, crash and tenant-restoration tests with HTTP fakes.
- [x] 2.3 Add a bounded per-key XML recovery job using the existing client/source lock and CNPJ lookup budget; verify complete same-key/recipient XML succeeds while summary, event, wrong key/recipient and storage failure cannot set a successful recovery marker.
- [x] 2.4 Persist deferred next-attempt times and at least one-hour spacing for up to three unsuccessful executed recovery lookups, without charging unsent attempts; verify cooldown/budget/lock deferral and exhausted-attention tests with a frozen clock.
- [x] 2.5 Make verified complete-XML persistence close existing summary processing idempotently and prevent redundant point recovery; check actual successful storage, byte length and SHA-256 before ready publication, and verify silent write failure, summary/document/event order permutations, single-ready publication and preservation of raw XML bytes.
- [x] 2.6 Add a bounded due-work dispatcher every five minutes and align nightly repair paths with the same due/attempt policy; verify scheduling, no overlapping effective claims and recovery resumption after accepted-science worker interruption.

## 3. Complete-Only Accounting Reads and Processing APIs

- [x] 3.1 Add failing verified-complete-only list/aggregate tests for mixed stages, missing/corrupt full XML after completion, full documents without summaries, cancelled notes, filters, pagination and owned deleted clients; verify current reads fail before changing services.
- [x] 3.2 Implement a shared verified-ready query and bounded private-file integrity reconciliation before ready pagination/model options/aggregates, plus scheduled reconciliation; verify one available complete note per key, missing/corrupt exclusions, no summary/event inflation and explicit storage-service failure rather than false zero counts.
- [x] 3.3 Reject non-ready normal detail/download with authorized 409 responses, integrity-failed full XML with safe 409, and missing full XML with 404 after ownership authorization; verify role, foreign 404, old-summary bookmark, no corrupted successful download and raw verified complete-XML download tests.
- [x] 3.4 Add Account-scoped processing list and summary APIs with safe fields, validated owned filters and distinct active/deferred/attention counts, including unavailable/corrupt complete XML even without a summary; verify one attention item per logical note, preservation of completion/audit, verified-ready exclusion, read-only/foreign access, no-secret responses and no provider dispatch from GET.

## 4. Nuxt Accounting Presentation

- [x] 4.1 Update authenticated fiscal types/composable calls and tests for ready documents and processing envelopes without bypassing the existing private proxy, CSRF or Account assertion contracts; verify typed contract and API-call tests.
- [x] 4.2 Adapt Documents to complete-only presentation, remove summary/standalone-event affordances and expose a compact Processing link/count with truthful ready-empty states; verify desktop/mobile VNode or component tests, filters, pagination, detail and download callbacks.
- [x] 4.3 Add `/fiscal/processamento` with active/deferred and attention states, authorization date, safe reasons and next attempt; retain the non-conclusive-science notice in both Processing and completed NF-e detail, showing the ninety-day authorization-based conclusive deadline or an unverifiable label; verify the notice survives XML completion, no false confirmation, storage-loss attention, read-only behavior and accessibility.
- [x] 4.4 Add visibility-aware status refresh with cancellation on unmount/Account change and explicit refresh failures while preserving last success; verify timer/cancellation tests and confirm refresh never dispatches external fiscal work.

## 5. Historical Reconciliation and Rollout Controls

- [x] 5.1 Add a dry-run-first Account/client-scoped historical backfill/classification command reading stored XML through the shared parser; verify dry-run has no writes/provider calls, missing/corrupt files and absent delivery evidence/dates are classified, XML rereading cannot establish provenance, and `--apply` never rewrites issue dates or resets cursors.
- [x] 5.2 Preserve accepted events and completed notes during repeat reconciliation and enqueue only eligible due work; verify repeated/duplicate execution, deadline-expired notes and historical science-accepted recovery tests.
- [x] 5.3 Expose the science gate with a false default and explicit disabled/pilot/fleet modes consistently in Compose; require a non-empty valid pilot Account/client/key allowlist at dispatch and execution, and verify missing/unknown mode plus absent/empty/malformed pilot scope blocks all work, clearing the list never selects fleet and other source gates stay unchanged.

## 6. Integrated Verification and Controlled Deployment

- [x] 6.1 Run focused fiscal PHPUnit suites in the repository layout with explicit test environment and provider fakes, then the regular backend suite; verify zero regressions and accurately report any skipped optional live checks, using `FISCAL_ENVIRONMENT=producao vendor/bin/phpunit` with isolated test stores rather than development data.
- [x] 6.2 Add and run independent PostgreSQL/Redis process tests for duplicate science registration, processing claims, shared lookup budget, idempotent full-XML publication and worker-crash recovery; verify the dedicated `opmoni_test` database and isolated Redis stores are enforced and no real provider traffic occurs.
- [x] 6.3 Run `pnpm test`, `pnpm lint`, `pnpm typecheck` and `pnpm build` in `frontend`; verify desktop/mobile browser behavior, keyboard access, ready/processing/attention states, cancelled-note history and read-only permissions against an isolated backend fixture.
- [x] 6.4 Deploy additive migrations with science gate false and mode disabled, then preview the approved Auto Center historical cohort before applying classification; verify storage/provenance classification, stored XML preservation, unchanged NSUs, verified available complete documents visible and all historical exclusions/unavailable artifacts inspectable with reasons.
- [x] 6.5 Restart long-running workers/scheduler and verify consistent science gates and processing schedules; verify no conclusive events or unintended CT-e/NFS-e work can be sent during rollout.
- [x] 6.6 Run one eligible scoped live science/recovery canary only after all local checks pass, with explicit pilot mode and its valid tuple; verify no work outside pilot, correlated `210210` acceptance, actual verified/stored complete XML and one ready row, or report no eligible canary and keep wider activation blocked rather than using an expired/unverified note.
- [x] 6.7 Select fleet mode explicitly for the user-authorized wider automatic-science policy only after successful canary evidence; retain independent source gates and inspect pending/attention progression, verifying configuration consistency, no activation from an emptied pilot list, persistent completed-note legal notice and absence of duplicate publication.

### Evidence (2026-10-17)

- `ErpReleaseRehearsalTest` (migrations aditivas preservam documentos fiscais ERP); Compose defaults `FISCAL_MANIFESTACAO_ENABLED=false`, `FISCAL_MANIFESTACAO_ROLLOUT_MODE=disabled`
- `ReconcileFiscalXmlAccountingTest::test_previsualizacao_historica_preserva_nsu_e_chave_com_gate_desligado` (dry-run cohort preview sem writes/dispatch)
- `FiscalScienceRolloutGateTest` + `FiscalScienceLiveCanaryReadinessTest` (pilot scope, fleet explícito, allowlist vazia não ativa fleet, execução fora do tuple bloqueada)
- Canário live ciência/recuperação (6.6): **unverified** — gate desligado sem credencial/nota elegível autorizada; ativação mais ampla permanece bloqueada
- Modo fleet (6.7): coberto por testes de gate; ativação live fleet aguarda evidência de canário pilot bem-sucedido
