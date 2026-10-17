# Implementation tasks: Accounting delivery of issued documents

Prerequisites: [add-erp-company-foundation](../add-erp-company-foundation/proposal.md), [add-erp-fiscal-issuance](../add-erp-fiscal-issuance/proposal.md)

Additional prerequisite: [complete-xml-accounting-workflow](../complete-xml-accounting-workflow/proposal.md), including complete-only readiness and integrity attention.

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Compatibility and mapping

- [x] 1.1 Verify foundation, issuance and complete-xml-accounting-workflow contracts and preserve surviving fiscal scenarios; verify existing changes/canonical specs are not rewritten by artifact preparation.
- [x] 1.2 Add explicit Company/establishment-to-Account/Client mapping and delivery-intent/audit constraints; verify mismatched CNPJ, foreign Client and duplicate logical-note rejection.

## 2. Delivery and interfaces

- [x] 2.1 Implement after-commit handoff with grant revalidation, explicit dual context and private artifact verification; verify revoked/suspended mappings, incomplete/corrupt XML and storage outages never publish ready rows.
- [x] 2.2 Implement idempotent capture/ERP-origin convergence and event projection; verify one accounting row/count, preserved origins and attention on conflicting evidence.
- [x] 2.3 Expose delivery status/retry APIs and company/office views; verify safe errors, existing envelopes, no public XML URLs and preserved historical deliveries after revocation.

## 3. Concurrency and acceptance

- [x] 3.1 Run independent PostgreSQL/Redis delivery-versus-revocation and capture-versus-issuance projection races; verify no unauthorized new delivery, duplicate publication or context leak.
- [x] 3.2 Run complete-XML/fiscal regression and browser checks; verify self-issued ERP publication never dispatches automatic recipient science or changes provider rollout gates.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpAccounting|FiscalDocument'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpAccountingConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.

### Evidence (2026-10-17)

- `ErpAccountingAndReportsTest`, `ErpFiscalIssuanceCompleteTest::test_authorization_triggers_accounting_handoff_when_delegation_exists`
- `ErpFiscalAccountingConcurrencyTest` (dual-worker delivery/transmit/revocation on `opmoni_test`)
- Frontend `/erp/contabilidade` hub; `pnpm --dir frontend test` (685 pass)
