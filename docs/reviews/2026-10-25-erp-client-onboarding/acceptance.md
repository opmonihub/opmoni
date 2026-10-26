# ERP client onboarding acceptance

Status: partial database-only evidence. The private PostgreSQL forward-migration preservation rehearsal is recorded below. Integrated implementation suites, browser flows, trial lifecycle behavior, compatible-app recovery, and the 54 requirement scenarios remain pending.

The evidence inventory is [scenario-crosswalk.csv](scenario-crosswalk.csv). It preserves the 54 scenario identities from the central design inventory as separate rows. Scenario count is traceability coverage, not proof. Each row must receive an actual test or browser assertion, accepted source commit, observed result, and remaining boundary before it can be marked proven.

## Source-bound PostgreSQL migration preservation checkpoint

On 2026-10-25, a private PostgreSQL 18/Redis 8 runtime with unique `opmoni-erp-onboarding-rehearsal` project, network, and volumes was used for the additive schema-preservation portion. The exact legacy source was `babea5de1c285ed4a2e703103219290c11cb0aba`; the accepted additive migration source was `449b01022a4b48dfd51585b6f5398d28d29fae61`. The synthetic legacy fixture was created by migrating the legacy source, then the accepted source migrations were applied. Runtime configuration was private, all registration/trial/release/provider gates remained disabled, and no production integrations were configured.

The source-bound probe passed: all 117 pre-existing migration records were preserved; row counts and content hashes matched across the legacy tables apart from explicitly checked additive columns; and the APP_KEY digest, user password hashes, Company identity/claimed-owner status, owner membership, operator delegation, contract terms/provenance, legacy invitation purpose, immutable invoice snapshot, and receipt history remained unchanged. The new trial tables remained empty. PostgreSQL reported eight expected validated constraints and both trial immutability/retention triggers. The private fixture used synthetic identities and is not a production restore.

This proves additive forward-schema preservation only. It does not prove that a compatible prior application can recover on the migrated store, that expiry blocks writes at the deadline, or that any API, UI, browser, frontend, concurrency, or complete backend behavior passes. The compatible-app recovery probe awaits Task 7 acceptance. Private runtime files and snapshots are retained only in ignored task scratch and are not part of the committed record. No destructive migration, rollback, reset, or shared-store operation was used.

## Current checkpoint

- Acceptance checkout base: `449b01022a4b48dfd51585b6f5398d28d29fae61`.
- This checkout has `composer.lock` and `frontend/pnpm-lock.yaml`; its locked backend dependencies are ready. The integrated accepted implementation is not yet present in this checkout.
- The source-bound migration preservation rehearsal above is complete. No complete backend/frontend suite or browser flow was run.

## Required integrated evidence

After the coordinator supplies accepted integrated source and the exclusive private-runtime window, record exact source SHA and runtime identity, command and exit status, test/assertion counts, scenario references, and artifacts. Run the focused suites followed by the complete backend profile, Pint, guarded PostgreSQL/Redis profile, and frontend lint, typecheck, test, and build against the same accepted source. Record documented optional skips separately from passing tests.

The private browser/runtime pass must cover office-created customer enrollment without an offer, optional owner access, public office-link registration and sponsor attribution, exact trial expiry with reads retained and writes denied, explicit paid conversion preserving Company identity/data and issuing exactly one invoice, desktop/mobile/header/settings states, and valid legacy invitation/printing behavior. No production email, payment, fiscal, or provider effect is part of acceptance.

The migration rehearsal must use a private restored copy and an additive migration followed by a compatible application rollback. Compare preserved invoice snapshots, passwords, owner/delegation links, `APP_KEY`, and release flags before and after. Do not use destructive schema rollback, `migrate:fresh`, shared-store resets, or identity recreation.

## Acceptance boundary

The design supersedes mandatory published-offer/invitation onboarding and no-free-trial clauses for the selected capabilities while retaining D009, existing financial history, and legacy invitation compatibility. It does not synchronize canonical specs, archive the active change, enable registration or trial gates, or authorize production rollout. Any scenario lacking direct evidence remains pending.
