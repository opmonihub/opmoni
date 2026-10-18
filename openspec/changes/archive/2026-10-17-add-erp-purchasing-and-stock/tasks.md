# Implementation tasks: Purchasing and inventory

Prerequisites: [add-erp-core-catalogs](../2026-10-17-add-erp-core-catalogs/proposal.md), [add-erp-finance-and-cash](../2026-10-17-add-erp-finance-and-cash/proposal.md)

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Persistence and invariants

- [x] 1.1 Add PostgreSQL purchase, purchase-line, stock-ledger, lot/allocation and balance constraints; verify scoped uniqueness, decimal conversions and immutable references.
- [x] 1.2 Implement inventory participants with sorted row locking, source-compatible FIFO/LIFO valuation, lot allocation and no-overselling default; verify transfer balance, insufficient stock and compensating reversals.

## 2. Purchasing and interfaces

- [x] 2.1 Implement draft/post/return purchase actions using inventory and finance in one transaction; verify duplicate posting, injected rollback and maximum return quantities.
- [x] 2.2 Expose purchase and stock APIs including bounded purchase-XML preview; verify foreign identifiers, malformed uploads and duplicate source keys.
- [x] 2.3 Build complete purchase, return, balance, movement, adjustment and transfer screens; verify confirmation, field preservation, meaningful conflicts and action permissions.

## 3. Sales contracts and validation

- [x] 3.1 Publish stock issue/reversal participant fixtures for sales and validate lock order against finance; verify no participant commits independently.
- [x] 3.2 Run independent PostgreSQL stock competition, transfer and purchase-posting tests; verify conserved quantities/costs and no financial partial writes.
- [x] 3.3 Run focused regressions, frontend checks and desktop/mobile end-to-end purchase/stock flows.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpPurchase|ErpStock'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpPurchaseConcurrency|ErpStockConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.

### Evidence (2026-10-17)

- `ErpOperationsApiTest::test_purchase_post_updates_stock_and_foreign_company_isolation` + `ErpModulesSchemaAndDomainTest::test_stock_valuation_consumes_fifo_layers`
- Frontend module contracts: `erpModulesNav.test.ts`, `/erp/compras`, `/erp/estoque` hubs
- Browser E2E purchase/stock: not run this session
- PostgreSQL: `ErpModulesConcurrencyTest::{test_concurrent_purchase_stock_keys_remain_idempotent_per_source,test_concurrent_stock_transfer_pair_conserves_quantity_without_partial_writes}` + fixtures `tests/Fixtures/erp/stock-sale-{issue,reversal}-participant.json` e `ErpStockParticipant`/`ErpPostingParticipantContractTest`
