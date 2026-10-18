# Local Review Remediation Implementation Plan

> For agentic workers: apply test-driven development and independent review to each owned area.

**Goal:** Correct the confirmed local-review defects without replacing Opmoni architecture or discarding existing work.

**Architecture:** Retain Account/company/session boundaries, existing controllers and participants. Centralize enforcement where existing actions share a boundary. Incomplete external adapters and unsupported financial/stock operations must fail explicitly rather than publish fabricated success.

**Tech stack:** PHP 8.4, Laravel 13.35, Nuxt 4, Vue 3, PHPUnit, Node test runner, PostgreSQL/Redis for isolated integration acceptance.

**Contracts:** Canonical `openspec/specs/erp-*`, `fiscal-documents`, `auth`, `isolation`, `whatsapp-delivery`, and the confirmed specialist findings from this chat.

## Constraints and decisions

- The user requested implementation in this local checkout; reuse it despite the dirty main branch. Do not create PRs, commits or worktrees, reset files, or discard unrelated changes.
- Preserve environment files, credentials, development data, historical external effects and provider release flags.
- No new dependencies or real provider calls. Use isolated test stores and fake transports.
- Additive schema migrations only; do not apply migrations to development automatically.
- User instruction to apply the findings authorizes execution. No repeated scope/permission prompt is required.
- Existing baseline: backend 2,089 total, 2,085 passed, one stale 90-day deadline test failed, three optional probes skipped; frontend 685 passed; lint/typecheck and 28 strict OpenSpec items passed.
- Keep partial/unsupported workflows explicitly unavailable when safe semantics cannot be implemented using existing models. Such restrictions must be reported as limitations, not claimed as feature parity.

## Review focus

- Permission restrictions and module subscriptions apply to reads, mutations and downloads.
- Monetary and stock effects stay atomic, bounded, company scoped and replay safe.
- Fake fiscal outcomes never masquerade as real authorization.
- Session loss or changed eligibility prevents a new side effect at the execution boundary.
- UI requests use registered methods, preserve the current view against stale responses and render real report payloads.

### Task 1: ERP authorization, finance, stock and fiscal boundaries

Owner: ERP specialist. Files: `backend/app/Erp/` except Accounting and company authorization; company context/middleware; ERP business/fiscal controllers, models, additive migrations and focused tests.

- [x] Add and run failing regression tests for granular permissions, foreign variants, payment caps, partial settlements and replay, negative inputs, return over-credit, cash aggregate tampering, missing subscription modules and preview context.
- [x] Fix those boundaries using existing participants and transactions. Align company-scoped idempotency constraints and lookups.
- [x] Correct purchase/return financial effects, conversion and cost handling; reject unsupported partial/valuation semantics explicitly.
- [x] Preserve commercial snapshots; guard events/invalidation; prevent runtime fake authorization, invalid PDF success and unsupported live dispatch.
- [x] Correct fiscal report lifecycle date filters. Run owned tests and report any unsupported scope precisely.

### Task 2: Fiscal capture, processing and accounting publication

Owner: fiscal specialist. Files: Fiscal services/read/processing, manifestation/recovery jobs, reconciliation command, ERP Accounting handoff and related tests.

- [x] Reproduce handoff disk/integrity and Summary collisions, job eligibility drift, wrong-recipient recovery, stuck terminal science, console tenant failure, aggregate/readiness mismatches, reopening complete work, deferred-attempt exhaustion, partial event correlation and >500-row verification.
- [x] Correct those causes with regression tests; preserve tenant restoration and complete-document identity.
- [x] Update the legacy deadline test to the current ten-day authorization contract, including boundary cases.
- [x] Run focused tests without external transport and record red/green evidence.

### Task 3: Identity, delegation and WhatsApp receipt integrity

Owner: integration specialist. Files: ERP registration action, company authorization/access controller, WhatsApp send job and related tests.

- [x] Reproduce reserved-email signup, CNPJ collision, delegation session-loss and terminal send receipt gaps.
- [x] Share global identity/CNPJ serialization, map specific collisions to validation, fence delegation changes inside transactions and persist send ID/status atomically.
- [x] Run focused isolated tests and report actual concurrency coverage.

### Task 4: Frontend route, loading and report contracts

Owner: coordinator. Files: global access middleware, ERP module/navigation hub, catalog loading/pages, report renderer and tests.

- [x] Add failing behavior tests for public ERP signup, report payloads/routes, stale tab/search requests and inaccessible pagination.
- [x] Correct public routing, use supported HTTP workflows, separate report data from paginated lists and provide pagination/filter/error behavior.
- [x] Keep unsupported mutations truthful and explicit; no decorative fake success or fabricated totals.
- [x] Run frontend tests, lint and typecheck.

### Task 5: Independent review and integrated validation

- [x] Review each owned patch against its findings; fix regressions and coordinate shared fixtures.
- [x] Run the full regular backend/frontend suites, required PHP formatting, lint/typecheck, OpenSpec validation and diff checks.
- [x] Run PostgreSQL integration only after ensuring no other process shares its test stores; otherwise report the missing acceptance explicitly.
- [x] Publish the consolidated findings/status report with changed files, evidence and remaining limitations.

## Execution ledger

Initial snapshot: `/tmp/opmoni-review-before-implementation.json`. Specialist implementation records: `/tmp/opmoni-erp-implementation.md`, `/tmp/opmoni-fiscal-implementation.md`, `/tmp/opmoni-integrations-implementation.md`.

Ownership check: Tasks 1 and 2 share ERP accounting/report fixtures; fiscal owns `ErpAccountingAndReportsTest`, and the ERP owner must coordinate changes. Tasks 1 and 3 share company authorization interfaces; integration owns delegation methods while ERP owns CurrentCompany/ResolveCompany. Task 4 consumes those interfaces without editing backend. Final formatting/suites are serialized after implementations settle.

Ruling: Use the current checkout and parallel specialists with explicit file ownership because the user requested local application and specialist work; preserve existing uncommitted sources and do not commit unrelated work.


## Completion evidence

- Implemented Tasks 1–4 with failing behavioral regressions followed by passing owned checks. Independent final review corrections were also reproduced before fixes.
- Full backend: 2,158 passed / 2,161 total, 12,277 assertions, three optional external probes skipped, zero failures.
- Isolated PostgreSQL/Redis process concurrency: 19 passed, 347 assertions.
- Frontend: 693 passed; lint/typecheck and isolated production build passed.
- Required dirty PHP formatter, strict OpenSpec (28 items) and final diff checks passed.
- Final report: `docs/architecture/local-code-review-2026-10-17.md` records every finding, conservative implementation limits and evidence.
- No commit, PR, worktree, dependency/environment change, development migration, queue restart, historical replay or live-provider test was performed. The new additive index migration remains pending in development; activation commands are in the report.
