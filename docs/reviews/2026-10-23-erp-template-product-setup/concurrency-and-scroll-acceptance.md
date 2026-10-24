# Concurrency and populated scrolling acceptance

Recorded 2026-10-24 for `align-erp-template-and-product-setup`. Task-scoped results below are separate runs, not a full integration-profile total.

## Profile and operation-nature races: tasks 10.2 and 10.4

Four new integration files in `edd159c` were independently reviewed and integrated as `f1d50ec`. Root compared 31 native participant files byte-for-byte with the canonical implementation. The isolated task runtime used PostgreSQL 18 `opmoni_test`, Redis 8 databases 13–15, independent authenticated PHP processes and actual observed Company-lock blocking chains.

- Initial profile-versus-save writer-first selection: **1 test / 428 assertions, exit 0**.
- Separate remaining selection, excluding that initial case: **26 tests / 10,896 assertions, exit 0**, 7m35.528s. All remaining cases retained safe parent/holder/waiter PHP and PostgreSQL identities in the ignored specialist report.
- Actual dirty Pint passed without a formatter diff; the tested four source hashes were unchanged at the final freeze.

The matrix covers selected/default profile changes against save, reviewed refresh and intended posting; nature defaults, choices, exceptions and legacy writers; joint configuration vectors, aggregate revisions, valid default membership, uniqueness, stale pre-effect conflicts and equal-key replay without duplicated stock/finance effects. Save/refresh barriers observe publication **after all classifications have been resolved**. No mid-resolver interleaving is claimed. This matrix does not add authority-revocation or transport-failure cases beyond their separately recorded suites.

All synthetic rows were retained. Development stores, production gates, existing schemas and providers were not changed by these race tests. The complete PostgreSQL profile subsequently passed in a separate user-authorized disposable environment; see [full PostgreSQL acceptance](full-postgresql-acceptance.md). Its older harnesses contain destructive reset calls and were not run against these retained services.

## Populated Office Fiscal scrolling: task 9.6

The isolated fixture contains 26 synthetic summary documents. At desktop 1366×900 and mobile 390×844, the hydrated first/second pages returned 25/1 rows. Before the fix, the constrained panel clipped content and pagination below the viewport. The bounded change `d8e4130`, integrated as `6a9049c`, adds the page-root vertical scroll owner without changing queries or data.

After the fix, desktop/mobile root scroll ranges were 3655/4791px. Keyboard Tab continuation reached visible pagination; page two loaded its single row; neither document gained horizontal overflow. Root inspected the desktop/mobile screenshots. Seventeen lifecycle tests and changed-file lint passed separately.

Native wheel delivery could not be established by the browser automation: accepted mouse commands did not produce DOM wheel events. Keyboard/scroll geometry evidence supports the correction; pointer-wheel behavior remains unverified. The later `536cbfc` advance changed test fixtures only, not this page's UI source. Local screenshots, hydrated response observations and exact source hashes remain in ignored task reports.
