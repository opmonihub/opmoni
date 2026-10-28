Baseline architecture and default evidence (worktree dac7293): company catalog GETs share ErpCatalogController::index. It builds an erp_company_id-scoped query, applies ErpEstablishmentAccess, then status/search filters, and paginates; ordering is currently a single orderBy('id') before scope application, so default ID ASC is observed in source. ErpCatalogScope::listParams currently validates no sort fields and normalizes status/search/per_page. ErpOperationNatureController serializes explicit cfop plus its configured default; index order cannot assign or change that persisted default. ErpCatalogController envelope is {data, meta{current_page,last_page,per_page,total}}.

Baseline focused suite: backend/vendor/bin/phpunit tests/Feature/ErpCoreCatalogsTest.php initially failed all 8 tests before requests because the isolated worktree has no APP_KEY. Rerun with ephemeral command-only APP_KEY passed: 8 tests, 55 assertions, SQLite memory. No env files or database outside PHPUnit were used.

Implemented in the isolated worktree; no commits or planning-file edits. ErpCatalogScope now validates sort and direction against the controller's allowed fields, rejects direction without sort, and defaults sort direction to ASC. ErpCatalogController keeps company/establishment scopes and status/search filters ahead of ordering/pagination, applies nulls-last ordering for nullable selected columns and a same-direction id tie-break. Unsorted requests remain id ASC. Generic visible-name catalogs permit id/name/status; units explicitly permit id/actual_name/short_name/status; carriers id/legal_name/status; vehicles id/plate/status; operation natures id/cfop/status. All allowlist values are server-owned before SQL use.

Focused RED evidence: before implementation, the three new catalog tests failed (unsupported sort requests were accepted with 200; name and carrier sort requests returned baseline order). After implementation and Pint, checks passed:
- APP_KEY=<ephemeral command-only key> vendor/bin/phpunit tests/Feature/ErpCoreCatalogsTest.php: 12 tests, 107 assertions.
- Same command on ErpEstablishmentScopeTest filtered to test_operation_nature_sorting_applies_selected_establishment_scope_before_pagination: 1 test, 8 assertions.
- Same command on ErpOperationNatureResolutionTest filtered to test_explicit_then_exception_then_default_precedence_has_separate_tax_and_cfop_provenance: 1 test, 44 assertions; the test first sorts a separate higher CFOP nature, then exercises actual explicit/exception/default resolver precedence.
- vendor/bin/pint --dirty --format agent passed; formatting changed app/Erp/ErpCatalogScope.php only.
- git diff --check passed.

Concerns/limits: no PostgreSQL suite, frontend/UI, full backend suite or live acceptance was run. Validation used PHPUnit's isolated in-memory SQLite; it does not establish PostgreSQL behavior. Existing root/worktree APP_KEY files were not copied or changed. Worktree diff is uncommitted by design.

Changed paths:
backend/app/Erp/ErpCatalogScope.php
backend/app/Http/Controllers/Api/V1/Erp/Catalogs/ErpCatalogController.php
backend/app/Http/Controllers/Api/V1/Erp/Catalogs/ErpCarrierController.php
backend/app/Http/Controllers/Api/V1/Erp/Catalogs/ErpOperationNatureController.php
backend/app/Http/Controllers/Api/V1/Erp/Catalogs/ErpUnitController.php
backend/app/Http/Controllers/Api/V1/Erp/Catalogs/ErpVehicleController.php
backend/tests/Feature/ErpCoreCatalogsTest.php
backend/tests/Feature/ErpEstablishmentScopeTest.php
backend/tests/Feature/ErpOperationNatureResolutionTest.php

Scope refinement after caller audit: ErpCatalogScope::listParams also serves operation-nature choice/exception lists. Ordering validation therefore lives in a separate orderingParams method called only by ErpCatalogController::index, preserving those existing list contracts.

Final post-Pint rerun passed:
- ErpCoreCatalogsTest: 12 tests, 107 assertions.
- ErpEstablishmentScopeTest filtered new selected-scope sorting test: 1 test, 8 assertions.
- ErpOperationNatureResolutionTest filtered test covering sorted list followed by actual CFOP resolver precedence: 1 test, 44 assertions.
- Existing ErpOperationNatureRulesTest: 16 tests, 262 assertions.
