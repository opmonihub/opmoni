# P2.4 implementation handoff: manual receipts and private proofs

Status: Implementation complete; ready for coordinator review. The source prerequisite `8f57a796` was cherry-picked as `d3f8440`. No OpenSpec task checkboxes were changed.

## Contracts and code evidence

- `backend/app/Erp/Commercial/OfficeErpReceipts.php:32-64` implements idempotent `post` and `reverse` through `OfficeErpOperations`, returning persisted receipt/reversal models. `:124-173` reauthorizes receipt commands and lookup payloads against current scoped resources.
- `OfficeErpReceipts.php:175-275` prelocks Account/subscription/plan, releases expired reservations, then deterministically locks companies, contracts, allocations and invoices by numeric ID before receipt/proof/operation rows. `:278-356` validates exact decimal sum and date shape; `:358-439` applies allocations/reversals, reconciles contracts, and persists authoritative balances in `{data: ...}` outcomes.
- `backend/app/Erp/Commercial/OfficeErpReceiptProofs.php:34-88` validates actual uploaded content, stores once under private account/task namespace, verifies bytes and records a 24-hour actor-bound handle. `:90-114` claims only a locked same-account/same-actor proof inside the receipt transaction. `:116-164` downloads only claimed proofs after seller receipt authorization, with no-store/nosniff headers. `:166-211,288-332` removes only expired, unclaimed, path-bound and integrity-verified objects.
- `backend/database/migrations/2026_10_24_120000_create_office_erp_receipt_proofs.php:9-29` adds UUID proof rows, Account and receipt composite ownership, unique path, claim/expiry metadata, and cleanup index.
- `backend/app/Http/Controllers/Api/V1/OfficeErp/OfficeErpReceiptController.php:18-55` exposes list, record, detail and reversal; mutation responses return the journal's durable outcome. `OfficeErpReceiptProofController.php:16-31` exposes multipart staging and private download.
- `backend/routes/office-erp-commercial.php:15-25` registers the six production endpoints under existing commercial middleware. `OfficeErpOperationController.php:20-64` preserves invitation/invoice/policy/offer handlers and adds action-specific `receipt.record`/`receipt.reverse` reauthorization before operation lookup disclosure.
- `backend/routes/business-console.php:10` registers exactly one daily cleanup job with `withoutOverlapping()`; job contract is `backend/app/Jobs/CleanExpiredOfficeErpProofs.php:10-13`.
- Tests: `ManualReceiptTest.php:25-128` covers partial receipt, replay/conflict, operation lookup, exact balances, one/multi-invoice reversal, sum mismatch, stale over-allocation and foreign invoice rejection. `ReceiptProofTest.php:48-170` covers real PDF/PNG/JPEG, size/disguise rejection, actor/account scope, private download, failed-commit preservation and selective cleanup.

## RED/GREEN and checks

- RED: after fixing the test-only session middleware, 9 named tests failed because the receipt/proof controllers were absent; the route fixture and runtime were valid.
- GREEN focused: 9 tests, 9 passed, 108 assertions using SQLite `:memory:` and the task's isolated environment values.
- Regressions: `InvoiceTest`, `InvoiceAdjustmentTest`, `CommercialOperationsTest`, `OwnerInvitationTest`: 40 tests, 39 passed, 1 skipped, 307 assertions.
- `php artisan route:list --path=erp-commercial/receipt`: all six production routes present.
- Pint formatted all changed PHP files. `git diff --check` passed before report/commit; final checks rerun after staging.
- No PostgreSQL, Redis, Docker, browser, scheduler execution or shared runtime was used. One existing regression test was skipped; no production database/concurrency claim is made.

## Commits and dependency

- Dependency commit: `d3f8440` (cherry-pick of source `8f57a796`), adding invitation route/lookup integration and updating its test. It was required by the coordinator and its changes remain intact.
- P2.4 implementation commit: `75fe4a6` (`feat(office-erp): record and reverse manual receipts with private proofs`).
- Owned producer dependency: invoice/balance/admission/reconciliation producers at accepted base `6b20053`; coordinator reported PostgreSQL acceptance separately. No remaining file ownership dependency after explicit p12 transfer.
