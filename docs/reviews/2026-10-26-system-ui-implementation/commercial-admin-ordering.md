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

## Implemented and verified in assigned checkout

- Added sort allowlists, default ascending direction, orphan-direction and invalid-value 422s to office commercial list validation; retained the search alias normalization and current filters.
- Added allowlisted sorting to all five office-commercial lists. Related client-name ordering uses a scoped correlated subquery, while the current Office Account remains in the base query. Nullable scalar columns use `CASE ... IS NULL` for nulls last; all non-ID sorts use the requested direction for the ID tie-break.
- Added the same contract to Admin Accounts/global users/subscriptions. Account and subscription relation sorting uses constrained subqueries; global users remain GET-only. No-sort branches retain the original ordering.
- Added feature coverage for validation, sort criteria, directions, tie behavior, nulls last, pagination across pages, current office isolation, existing envelopes, exact invoice totals, default ordering, and ordinary-user platform denial.
- RED evidence: new API tests initially failed because the query key was prohibited/ignored; after implementation the focused tests pass.
- Final PHPUnit evidence with process-only test APP_KEY: `AdminListFiltersTest` 14/14 (86 assertions); `ListOrderingTest` 7/7 (55 assertions); existing commercial regression group (CustomerPortfolio, CommercialRecords, RetailOffer, Invoice, ManualReceipt) 34 tests, 32 passed, 2 skipped (292 assertions). Existing test skips are retained.
- Final `vendor/bin/pint --dirty --format agent`: passed. SQLite in-memory only; isolated PostgreSQL checks from task 5.3 were not part of this worker assignment and remain a separate validation gate.
