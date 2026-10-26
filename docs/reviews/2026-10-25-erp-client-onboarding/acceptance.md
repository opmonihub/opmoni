# ERP client onboarding acceptance

Status: preparation only. No implementation, automated suite, browser flow, database migration rehearsal, or runtime scenario is accepted by this record yet.

The evidence inventory is [scenario-crosswalk.csv](scenario-crosswalk.csv). It preserves the 54 scenario identities from the central design inventory as separate rows. Scenario count is traceability coverage, not proof. Each row must receive an actual test or browser assertion, accepted source commit, observed result, and remaining boundary before it can be marked proven.

## Current checkpoint

- Acceptance checkout base: `449b01022a4b48dfd51585b6f5398d28d29fae61`.
- This checkout has `composer.lock` and `frontend/pnpm-lock.yaml`; `backend/vendor/` and `frontend/node_modules/` are absent.
- No implementation-specific checks were run. The accepted source from Tasks 1–11 is not yet integrated here.
- No browser, PostgreSQL/Redis profile, migration, or restored-store rehearsal was run.

## Required integrated evidence

After the coordinator supplies accepted upstream source and the exclusive private-runtime window, record exact source SHA and runtime identity, command and exit status, test/assertion counts, scenario references, and artifacts. Run the focused suites followed by the complete backend profile, Pint, guarded PostgreSQL/Redis profile, and frontend lint, typecheck, test, and build against the same accepted source. Record documented optional skips separately from passing tests.

The private browser/runtime pass must cover office-created customer enrollment without an offer, optional owner access, public office-link registration and sponsor attribution, exact trial expiry with reads retained and writes denied, explicit paid conversion preserving Company identity/data and issuing exactly one invoice, desktop/mobile/header/settings states, and valid legacy invitation/printing behavior. No production email, payment, fiscal, or provider effect is part of acceptance.

The migration rehearsal must use a private restored copy and an additive migration followed by a compatible application rollback. Compare preserved invoice snapshots, passwords, owner/delegation links, `APP_KEY`, and release flags before and after. Do not use destructive schema rollback, `migrate:fresh`, shared-store resets, or identity recreation.

## Acceptance boundary

The design supersedes mandatory published-offer/invitation onboarding and no-free-trial clauses for the selected capabilities while retaining D009, existing financial history, and legacy invitation compatibility. It does not synchronize canonical specs, archive the active change, enable registration or trial gates, or authorize production rollout. Any scenario lacking direct evidence remains pending.
