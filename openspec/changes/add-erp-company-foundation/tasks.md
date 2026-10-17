# Implementation tasks: Company foundation and unified workspace

Prerequisites: None.

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Contracts and migration baseline

- [x] 1.1 Inventory the pinned source core routes, controllers, views, models, SQL and package requirements against the source map below; verify each core behavior has a destination change and each excluded complement is explicit.
- [x] 1.2 Freeze shared route, identity, company-context, action-permission and error contracts; verify consumer type/contract fixtures and preserve original office scenario coverage.
- [x] 1.3 Create additive PostgreSQL company/establishment/membership/delegation migrations and models; verify duplicate membership, foreign references, single active delegation and preservation of existing users/Accounts/clients.

## 2. Laravel foundation

- [x] 2.1 Implement Company context, scoped route binding and policies without global tenant bypass; verify 401/403, missing/stale assertions, foreign resource 404, related-ID/context injection 422, initial selection without a previous company, and invalidation of delegated company selection after office switching.
- [x] 2.2 Implement authenticated company setup, safe identity extension and session-local selection; verify office-first defaults, company-only identities, identity-row preservation, fixed session deadlines and rollback.
- [x] 2.3 Implement owner-controlled delegation acceptance/replacement/revocation and permission intersection; verify one active office, read-only defaults, blocked administration and immediate revoked-access denial.
- [x] 2.4 Add explicit Company background-context execution/restoration; verify consecutive failed/successful jobs cannot share company state.

## 3. Nuxt and verification

- [x] 3.1 Build the ERP layout and Offices/Companies context menu using current components/theme; verify direct SSR entry, company-only login, focus refresh, switch failures and stale-response clearing.
- [x] 3.2 Run independent PostgreSQL/Redis tests for concurrent selection, delegation replacement/revocation, membership loss and suspension; record process evidence separately from feature tests.
- [x] 3.3 Run focused access regressions, frontend checks and architecture/API documentation updates; verify no unrelated work or existing application contracts are overwritten.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpCompanyFoundation|ErpCompanyContext|ErpOfficeDelegation|AccessFoundation'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpCompanyConcurrency|ErpDelegationConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.
