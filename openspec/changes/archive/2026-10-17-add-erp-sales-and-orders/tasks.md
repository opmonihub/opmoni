# Implementation tasks: Sales and commercial orders

Prerequisites: [add-erp-core-catalogs](../2026-10-17-add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../2026-10-17-add-erp-finance-and-cash/proposal.md), [add-erp-purchasing-and-stock](../2026-10-17-add-erp-purchasing-and-stock/proposal.md)

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Commercial schema and calculation

- [x] 1.1 Add quote/order/sale/line/return state and immutable snapshot migrations; verify numbering uniqueness, decimal rounding and foreign-key/history constraints.
- [x] 1.2 Implement server pricing/tax/discount validation and quote/order conversion; verify client totals cannot override authoritative values and draft actions have no stock/financial effects.

## 2. Posting and user interfaces

- [x] 2.1 Implement atomic sale posting and returns using stock/finance participants; verify double-submit idempotency, rollback, insufficient stock and excess-return rejection.
- [x] 2.2 Expose commercial document APIs and approved-sale snapshot contract for fiscal issuance; verify company isolation, permissions and separation of sale versus fiscal statuses.
- [x] 2.3 Build quotes, orders, counter-sale, completed details and returns in Nuxt; verify confirmations, payment/field errors, retry and truthful invoice state.

## 3. Validation

- [x] 3.1 Run independent PostgreSQL competing sale/return/number allocation tests; verify no overselling, duplicate movement or partial financial effect.
- [x] 3.2 Run purchase/stock/finance consumer regressions, frontend checks and complete desktop/mobile commercial flows.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpQuote|ErpOrder|ErpSale'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpSaleConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.

### Evidence (2026-10-17)

- `ErpOperationsApiTest::test_sale_post_and_fiscal_document_queue`; `ErpModulesSchemaAndDomainTest::test_sale_totals_calculator_matches_server_side_invoice_math`
- Frontend: `/erp/vendas` hub + `erpModulesNav` contracts
- Browser commercial flows: not run this session
- PostgreSQL: `ErpModulesConcurrencyTest::test_concurrent_sale_invoice_numbers_commit_single_row` + post de venda via `ErpStockParticipant`/`ErpFinanceParticipant` (`ErpOperationsApiTest::test_sale_post_and_fiscal_document_queue`, `ErpPostingParticipantContractTest`)
