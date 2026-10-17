# Implementation tasks: Company commercial access

Prerequisites: [add-erp-company-foundation](../add-erp-company-foundation/proposal.md)

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Company contracts

- [x] 1.1 Add company plan/subscription/entitlement schema with constrained status and uniqueness; verify office catalogs/subscriptions and existing identities remain unchanged.
- [x] 1.2 Implement insert-only company-plan initialization and pending subscription provisioning; verify repeated execution preserves customizations and introduces no demonstration tenants.
- [x] 1.3 Implement new-owner registration and authenticated company creation atomically; verify existing-email preservation, CNPJ conflicts, disabled bootstrap/signup gates and injected-failure rollback.

## 2. Authorization and interfaces

- [x] 2.1 Implement company commercial guards and audited platform CRUD for plan/subscription editing; verify missing/inactive entitlements, owner-read-only access and unchanged office behavior.
- [x] 2.2 Build company registration/contract and platform ERP commercial pages; verify field errors, duplicate-submit protection, pending status, retry and absence of fabricated paid activation.
- [x] 2.3 Test concurrent company registration and contract activation with independent PostgreSQL/Redis processes; verify one company/subscription and recoverable post-commit session failure.

## 3. Validation

- [x] 3.1 Run focused backend/frontend/ingress checks and update commercial runtime instructions; verify no payment-provider call or public release is performed by provisioning.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpRegistration|ErpCommercialAccess|ErpSubscription'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpCommercialConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.
