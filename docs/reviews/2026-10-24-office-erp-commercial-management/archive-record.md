# Office ERP commercial management — archive record

Archived locally on 2026-10-25 using the `spec-driven` schema. The [complete change](../../../openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/proposal.md), including its metadata, design, component audit, five deltas and 55/55 completed tasks, is preserved in the dated archive. The [69-scenario crosswalk](scenario-crosswalk.csv) retains its identities, order, evidence and accepted statuses; only its delta source paths changed.

The requested re-sync checked all five capabilities: `access-pages`, `erp-commercial-access`, `office-erp-billing`, `office-erp-commercial-management` and `platform-commercial-management`. All 26 requirements and 69 scenarios already matched their canonical counterparts. Re-sync was therefore idempotent: no canonical spec was rewritten, and unrelated contracts and existing scenarios were preserved. Strict validation passed 31 canonical specs and the selected change before the move.

## Final source and verification

Implementation was fast-forward merged into local `main` at `f86172ee9d9fd55a242e66556e3b5bdce73cc9c7`. Source-bound final verification passed:

- Frontend: 1,982 tests across 260 suites, with no failures or skips.
- Regular backend: 3,729 tests, 34,265 assertions, six documented skips and no failures, using the standard PHP 8.4.27 image with networking disabled and in-memory SQLite.
- Full PostgreSQL profile: 243 tests, 27,912 assertions and no failures on the merged source.

[Integrated acceptance](integrated-acceptance.md) retains earlier build, typecheck, browser, PDF, migration and independent race evidence with its original source attribution and limits. Earlier failed profiles remain diagnostic records; they are not relabeled as successful. The host PHP/OpenSSL certificate-fixture limitation does not replace the accepted standard-image result.

## Cleanup and boundaries

The 28 temporary worktrees and their feature branch were removed, 25 task workspaces archived, and eight owned task containers and two networks removed. Worker history and local recovery evidence were preserved. Named volumes, development stores, environment files, APP_KEY and pre-existing unrelated working changes were retained.

No remote push or production rollout was performed. This archive does not enable registration, fiscal/SERPRO transmission, provider dispatch or external payment integrations. Existing lint/build warnings, the inherited sidebar accessibility finding, incomplete targeted accessibility checks, native OS printing and physical-device limits remain as recorded in integrated acceptance. Documentation and archive changes are local working-tree edits until separately committed.
