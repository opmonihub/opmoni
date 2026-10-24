# Backend integration validation

Implementation source: canonical `293f623`, including typed settings, initialization, native numbering, CFOP reference and revisioned product-tax profiles. Documentation checkpoint `965c659` does not change that backend tree.

## Isolated runtime

The task runtime ownership verifier passed before mutation: task-specific mounts, network and volumes; PostgreSQL 18 database `opmoni_test`; Redis 8 databases 13–15; disabled production/registration/provider gates. Existing development stores and keys were preserved. The canonical Nuxt process was verified at the expected working directory and loopback port 3340.

The additive migration command completed with exit 0:

```sh
scripts/erp-template-runtime/run backend php artisan migrate --force --no-interaction
```

It applied `2026_10_23_230000` setup state, `2026_10_24_010000` native sale-number adoption and `2026_10_24_080000` product-tax profile/revision/default storage. This proves PostgreSQL migration execution, including trigger creation; it does not prove independent-process races or trigger rejection behavior on PostgreSQL.

## Joint contract checks

A single disposable `opmoni-backend:dev` process used PHP 8.4.27, the complete canonical checkout at the same absolute path, current UID 1001, an independent installed vendor tree and `--network none`. Test bootstrap used in-memory SQLite and array stores, not the task PostgreSQL or Redis stores. Environment selected `FISCAL_ENVIRONMENT=producao` and the test-only expected frontend origin. No cached configuration, secret values or changed environment files were included in this report.

The process ran `vendor/bin/phpunit` on:

- `tests/Feature/ErpCompanySettingsTest.php`
- `tests/Feature/ErpProductTaxDefaultsTest.php`
- `tests/Feature/ErpCompanyInitializationTest.php`
- `tests/Feature/ErpProductTaxProfileTest.php`

Result: **exit 0; 44 tests, 478 assertions; approximately 2 minutes 4 seconds; 66.5 MB.** The selection verifies the merged typed-settings validator and profile-default settings hooks together with initialization/profile contracts. No source changed during the run. Earlier specialist checks remain attributed to their respective commits rather than being added into a fictitious full-suite total.

## Review and limits

The profile backend's original Important delegated-grant finding was corrected in `6bbe39f`, integrated as `293f623`; independent scoped review returned I1 ADDRESSED, SPEC PASS and QUALITY APPROVED. The controller inspected the implementation and fix in context before accepting tasks 7.1–7.4. Profile-specific focused tests (33/347), relevant existing regressions (129/1619), delegated fix coverage (23/499) and actual-worktree Pint passed on the corresponding frozen specialist sources.

Independent-process PostgreSQL/Redis races, the full regular/backend PostgreSQL suites, operational snapshots, profile-selection UI, browser mutation flows and live providers remain pending under their separate tasks. No merge, push, archive or production gate change occurred.
