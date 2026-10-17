# Implementation tasks: Finance and cash operations

Prerequisites: [add-erp-commercial-access](../add-erp-commercial-access/proposal.md), [add-erp-core-catalogs](../add-erp-core-catalogs/proposal.md)

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Financial persistence and services

- [x] 1.1 Add ERP financial-account, obligation, allocation and movement schema with actor/source references; verify decimal constraints, uniqueness and immutable history.
- [x] 1.2 Implement receivable/payable, expense/revenue posting and linked reversals; verify exact balances, company permissions and full rollback.
- [x] 1.3 Implement payment allocations and reversals with source-operation keys; verify overpayment rejection, equal replay and different-payload conflicts.
- [x] 1.4 Add constrained company/establishment/register cash-session persistence and opening; verify active-session ownership and foreign-establishment denial.
- [x] 1.5 Implement cash movements, withdrawals/deposits, closing and reconciliation; verify explicit discrepancies, closed-session denial and repeated-close idempotency.

## 2. Interfaces and consumers

- [x] 2.1 Expose policy-protected financial/cash APIs and receipt/document downloads; verify cross-company denial, validation and safe non-JSON failures.
- [x] 2.2 Build finance and cashier screens using current Nuxt components; verify pending/error/retry states, immutable history, action permissions and protected outputs.
- [x] 2.3 Freeze purchase/sale posting and reversal consumer contracts; verify financial operations join the caller transaction without hidden commits or circular source callbacks.

## 3. Concurrency and validation

- [x] 3.1 Run independent PostgreSQL tests for concurrent allocation, source posting and cash close; verify single effects, correct balances and deterministic lock order.
- [x] 3.2 Run focused regressions, frontend checks and browser workflows; record fake versus live financial-provider results separately.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpFinance|ErpCash'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpFinanceConcurrency|ErpCashConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.

### Evidence (2026-10-17)

- `FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpOperationsApi|ErpModulesSchema'` → finance/cash paths in `test_finance_list_create_allocate_and_cash_session_close`, domain checks in `ErpModulesSchemaAndDomainTest` (49 ERP filter tests green in integration pass)
- `pnpm --dir frontend lint`, `typecheck`, `test` → pass (`erpModulesNav.test.ts`, module hub pages)
- Browser desktop/mobile flows: not run this session
- PostgreSQL: `docker compose exec -T backend vendor/bin/phpunit -c phpunit.pgsql.xml --filter ErpModulesConcurrencyTest` → 10/10 (baseline 5/5 + novos casos de alocação `insert-payment-allocation`, fechamento de caixa `close-cash-session`, ordem finance/stock/fiscal); `ErpPostingParticipantContractTest` 4/4 (rollback conjunto estoque+financeiro)
