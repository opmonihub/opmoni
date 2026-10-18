# Implementation tasks: Core commercial and fiscal catalogs

Prerequisites: [add-erp-company-foundation](../2026-10-17-add-erp-company-foundation/proposal.md)

All tasks below are implementation work and remain unchecked during proposal preparation. Artifact completion is not feature completion; every task must use current evidence before checking.

## 1. Reference mapping and schema

- [x] 1.1 Map source Contact/Product/Variation/Unit/TaxRate/NaturezaOperacao/logistics behaviors to target APIs and fields; verify every required sale/purchase/fiscal reference is represented.
- [x] 1.2 Add PostgreSQL catalog/reference migrations, models and company policies; verify decimal precision, scoped uniqueness, archive behavior and foreign-reference restrictions.

## 2. APIs and Nuxt

- [x] 2.1 Implement company contact/customer/supplier APIs and forms; verify company isolation, validation, search/pagination, archive behavior and no office-Client mutation.
- [x] 2.2 Implement goods/service/variant/unit APIs and forms; verify decimal conversions, source-supported warranty fields, foreign-reference rejection and preserved posted snapshots.
- [x] 2.3 Implement categories, brands, price groups and discount/coupon rules; verify scoped uniqueness, source-compatible pricing inputs, action permissions and failed-input preservation.
- [x] 2.4 Implement establishment fiscal/reference configuration and forms; verify source-required classifications/tax/numbering inputs and safe change history.
- [x] 2.5 Implement carrier/vehicle/driver configuration and forms; verify same-company relationships and the references consumed by CT-e/MDF-e.
- [x] 2.6 Implement bounded product/contact import preview/confirm, label/barcode outputs and printing setup; verify malformed uploads, duplicate detection, explicit confirmation and protected outputs.

## 3. Consumer contracts and verification

- [x] 3.1 Publish typed catalog/decimal/reference fixtures for finance, inventory, sales and issuance; verify snapshots remain valid after catalog edits.
- [x] 3.2 Run focused backend/PostgreSQL/frontend checks and browser desktop/mobile flows; verify migrated MySQL query behavior and untouched office portfolio.

## Verification commands and evidence boundaries

Create the domain suites with the planned filter prefixes below and require a nonzero executed-test count. Use the repository-layout backend environment: `cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit --filter 'ErpContact|ErpProduct|ErpCatalog|ErpFiscalConfiguration'`, expanding to the complete regular suite for cross-cutting final verification. Run `vendor/bin/pint --dirty --format agent` for changed PHP. The standard test bootstrap forces SQLite/array isolation; never substitute the development database.

For required process races, use `vendor/bin/phpunit -c phpunit.pgsql.xml --filter 'ErpCatalogConcurrency'` in the guarded backend test runtime with `opmoni_test`, isolated Redis 13/14/15 and independent processes. Follow README setup and destructive-fixture guards; mock providers and protect development artifacts/data. A SQLite test or sequential transaction is not process-race evidence.

Run `pnpm --dir frontend lint`, `pnpm --dir frontend typecheck`, `pnpm --dir frontend test`, `pnpm --dir frontend build`, and scoped desktop/mobile/SSR browser acceptance. Repeat only after changes or failures justify it. Separate fake/local transport evidence, independent-process evidence, deployed storage/recovery and authorized live-provider canaries. Validate planning changes with `openspec validate --all --strict --no-interactive`.

### Evidence (2026-10-17)

- `vendor/bin/phpunit --filter ErpCoreCatalogs` → 8 passed
- `node --experimental-strip-types --test tests/erpCoreCatalogs.test.ts` → 5 passed
- Browser desktop/mobile flows: not run this session (API + contract tests only)
