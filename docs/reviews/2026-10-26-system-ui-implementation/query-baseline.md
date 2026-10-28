# Query compatibility baseline

Worker-recorded focused source/test evidence; PostgreSQL acceptance remains pending.

Baseline architecture and default evidence (worktree dac7293): company catalog GETs share ErpCatalogController::index. It builds an erp_company_id-scoped query, applies ErpEstablishmentAccess, then status/search filters, and paginates; ordering is currently a single orderBy('id') before scope application, so default ID ASC is observed in source. ErpCatalogScope::listParams currently validates no sort fields and normalizes status/search/per_page. ErpOperationNatureController serializes explicit cfop plus its configured default; index order cannot assign or change that persisted default. ErpCatalogController envelope is {data, meta{current_page,last_page,per_page,total}}.

Baseline focused suite: backend/vendor/bin/phpunit tests/Feature/ErpCoreCatalogsTest.php initially failed all 8 tests before requests because the isolated worktree has no APP_KEY. Rerun with ephemeral command-only APP_KEY passed: 8 tests, 55 assertions, SQLite memory. No env files or database outside PHPUnit were used.


# Commercial and Admin ordering baseline

Checkout: `/home/obsidian/dev/opmoni/.worktrees/erp-settings-forms`, base `dac7293`, initially clean. Active OpenSpec artifacts were read from the root checkout; this worker does not own their task ledger.

## Existing default ordering and envelopes

- Office clients: `clients.name ASC`; `{data, meta:{current_page,last_page,per_page,total}}`.
- Office offers/plans: `office_erp_sales_plans.name ASC`; same commercial envelope.
- Office contracts: `office_erp_contracts.id DESC`; same commercial envelope.
- Office invoices: `issued_at DESC, id DESC`; same commercial envelope; serialized original totals and balances remain exact decimal strings.
- Office receipts: `received_on DESC, id DESC`; same commercial envelope.
- Admin Accounts: `accounts.id DESC`; `{data, meta:{page,per_page,total,last_page}}`.
- Admin global users: `users.id DESC`; resource collection with `meta.current_page` (and standard resource pagination metadata).
- Admin subscriptions: `subscriptions.id DESC`; resource collection with `meta.current_page` (and standard resource pagination metadata).
- Admin plans remain a raw array, summary audit rows remain `created_at DESC, id DESC`, support logs remain `id DESC`.

## Baseline test evidence

Tests require process-only `APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=`; no `.env` was copied. The first AdminListFilters baseline errored because APP_KEY was absent. With the process key, AdminListFiltersTest passed: 11 tests, 48 assertions. CustomerPortfolioTest passed: 1 test, 48 assertions. RetailOfferTest passed: 10 tests, 9 passed, 1 skipped, 97 assertions. InvoiceTest passed: 4 tests, 37 assertions. A broader combined invocation exceeded the runner's response window without useful output; rerun focused files individually.
