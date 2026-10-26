# Task 1 report — additive schema and trial representations

## Inspected architecture and constraints

- Confirmed the isolated checkout is `/home/obsidian/dev/opmoni/.worktrees/erp-client-onboarding`, branch `codex/erp-client-onboarding`, initial HEAD `f84ba85b36c1a9d3cefdebe4dbe0ab4e0a810998`; the worktree was clean before this task.
- Read repository and backend `AGENTS.md`. `backend/.ai/rules` is absent. Reviewed `docs/architecture/product-decisions.md` (D009 tenant boundaries), canonical ERP commercial-access and company-foundation specs, this change's design and Task 1 delta specs, plus `BillingSchemaTest`, `ErpCommercialAccessTest`, the ERP commercial migrations/models and existing SQLite/PostgreSQL isolation guards.
- Existing companies use one unique ERP subscription per Company. ERP status validity was not previously database constrained. Office subscriptions have a separate `subscriptions_status_valid` constraint; this change does not touch it.
- Existing office contract terms, revisions, invoices, owner memberships and invitation tokens are retained. The new fields default existing rows to `claimed`, `legacy_invitation` and `commercial_acceptance`.
- Read the required TDD and writing-good-tests guidance. Production changes followed an observed RED run. No dependencies, env files, release gates, canonical specs, task checkboxes or unrelated source paths were changed.

## RED evidence

Before production edits:

```text
cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter TrialSchemaTest
```

Result: 3 tests, 3 assertions, all 3 failed for the expected missing behavior: the Company owner state was null, trial tables were absent, and the enum lacked `trialing`. There were no test bootstrap or fixture errors.

## Implementation

- Added `owner_setup_status` with a `claimed` database default; contract provisioning provenance, technical plan and admin attribution with a `legacy_invitation` default; and immutable invitation purpose with a `commercial_acceptance` default.
- Added one office trial-link configuration per Account with unique public UUID, selected plan, enabled state, revision and creator/updater attribution.
- Added durable trial grants with unique CNPJ, Company and subscription, a composite subscription/Company FK, restrictive Account/Company/subscription deletion behavior, valid period checks, and database enforcement against changing provenance, CNPJ or grant timestamps, clearing lifecycle markers, or deleting history. `converted_at` and `expired_at` may be set as lifecycle markers.
- Added `ErpSubscriptionTrial` and `OfficeErpTrialLink` models, trial relationships on Company and subscription, and contract plan/author relationships and casts.
- Added `trialing` / `trial_expired` enum values and platform read filtering. Generic platform status/plan changes are rejected for any subscription with trial history or a trial status. The ERP status constraint permits only existing values plus those two, using PostgreSQL CHECK and SQLite triggers.
- The subscription resource returns trial dates and current situation only to the direct Company owner. Admin list/show/update queries eager-load the trial relation.
- Added `TrialSchemaTest` coverage for legacy owner and invoice/revision snapshots, defaults, invitation-purpose immutability, cross-office CNPJ uniqueness, Company/subscription trial relationships, restrictive FKs, immutable CNPJ/markers and retained history, owner trial metadata, read filters, blocked generic mutations and invalid status rejection.

## GREEN and formatting evidence

SQLite focused checks, after the final source edits:

```text
cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'TrialSchemaTest|ErpCommercialAccessTest'
```

Passed: 12 tests, 68 assertions.

```text
cd backend && vendor/bin/pint --dirty --format agent
```

Passed after formatting one import ordering adjustment; the subsequent focused PHPUnit run above passed.

Guarded PostgreSQL migration and schema check:

```text
.superpowers/sdd/2026-10-25-simplify-office-erp-client-onboarding/runtime/run backend php artisan migrate --force --no-interaction
```

Passed on the isolated PostgreSQL 18 `opmoni_test` store; both new migrations applied. A second invocation returned `Nothing to migrate`.

```text
.superpowers/sdd/2026-10-25-simplify-office-erp-client-onboarding/runtime/run backend vendor/bin/phpunit -c phpunit.pgsql.xml tests/Feature/OfficeErpCommercial/TrialSchemaTest.php
```

Passed: 5 tests, 34 assertions. The focused PostgreSQL test override verifies the pre-migrated schema and opens a transaction; it does not invoke `migrate:fresh`. The runtime wrapper validated the checkout and its private database/Redis mounts before each command.

`git diff --check` passed during final review. No full backend suite was run; the coordinator owns the integrated matrix. Parent-reported pre-task baseline was 22 tests / 91 assertions with one precision skip and was not rerun here.

## Self-review and remaining verification

The schema preserves the one-subscription-per-Company rule and does not add a constraint to office subscriptions. Trial-source FKs are restrictive, and both SQLite and PostgreSQL exercised duplicate CNPJ, immutable fields, marker transitions, delete protection and invalid ERP status behavior. The PostgreSQL test transaction rolled back its fixtures; only additive migrations remain in the isolated test database.

This task establishes representations and database safeguards only. Trial enrollment, admission, conversion, expiry reconciliation, live operating-write eligibility and cross-process races belong to later tasks and remain unverified here. No production migration or release gate was changed.
