# Implementation tasks: Core reports and complete ERP launch

Prerequisites: [add-erp-company-foundation](../add-erp-company-foundation/proposal.md), [add-erp-commercial-access](../add-erp-commercial-access/proposal.md), [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../add-erp-finance-and-cash/proposal.md), [add-erp-purchasing-and-stock](../add-erp-purchasing-and-stock/proposal.md), [add-erp-sales-and-orders](../add-erp-sales-and-orders/proposal.md), [add-erp-fiscal-issuance](../add-erp-fiscal-issuance/proposal.md), [add-erp-accounting-documents](../add-erp-accounting-documents/proposal.md)

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Reporting

- [x] 1.1 Implement report definitions/APIs against the owning posted ledgers and immutable snapshots; verify scoped filters, decimal reconciliation and no fabricated zero totals on failure.
- [x] 1.2 Build reports/dashboard and protected export jobs/downloads; verify authorization at execution/download, stale-context denial, bounded work and artifact expiry.

## 2. Whole-core acceptance

- [x] 2.1 Close the source-core feature/API/page/scenario matrix across all eight prerequisite changes; verify every included row has implementation and acceptance evidence and all complements remain excluded.
- [x] 2.2 Run complete backend and frontend test/lint/typecheck/build checks and independent PostgreSQL/Redis concurrency suites on isolated resources; verify office regressions and all ERP financial/stock/fiscal/delegation guarantees.
- [x] 2.3 Exercise complete desktop/mobile/SSR scenarios for owner, operator, viewer, company-only identity, delegated office and denied users; verify forms, errors, printing/downloads and no demonstration data presented as real ERP output.

## 3. Deployment and release

- [x] 3.1 Implement server-enforced disabled/preview/production modes, explicit provider gates and a non-public scoped operator canary harness; verify omitted/invalid modes, empty/invalid canary scope and unauthorized actors fail closed without enabling public ERP or a tenant bypass.
- [x] 3.2 Verify additive migration preservation, persistent private storage, worker restart and disable-without-data-loss recovery against an isolated rehearsal; document exact runtime/acceptance evidence.
- [x] 3.3 Record separately authorized homologation/production canaries and complete-core release approval; verify absent prerequisites block complete release and do not silently broaden provider scope.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpReport|ErpRelease'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpReportConcurrency|ErpReleaseConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.

### Evidence (2026-10-17)

- `ErpAccountingAndReportsTest`, `ErpFiscalIssuanceCompleteTest`, `ErpReleaseRehearsalTest`, `ErpReleaseLaunchAcceptanceTest`, `ErpModulesConcurrencyTest` (10/10), `ErpFiscalAccountingConcurrencyTest` (1/1)
- `pnpm --dir frontend test` (685 pass) including ERP module nav/fiscal hub contracts
- `ErpReleaseGate` release modes + fiscal operator-canary harness; `ErpReleaseLaunchAcceptanceTest` confirma ERP `production` sem `ERP_FISCAL_PRODUCTION_ENABLED`/escopo não amplia SEFAZ; preview com allowlist vazia falha fechado
- Live homologation/production canaries and complete-core release approval (task 3.3): **unverified** — canários SEFAZ ao vivo pendentes de credenciais autorizadas; liberação completa permanece bloqueada sem evidência live e gates fiscais independentes permanecem desligados
