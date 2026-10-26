# Frontend navigation lifecycle archive record

On 2026-10-26, the user selected canonical synchronization followed by archival of `refactor-frontend-navigation-lifecycle` in the isolated `codex/frontend-navigation-lifecycle` worktree. The `spec-driven` change had four complete artifacts and 24/24 completed tasks.

The [archive](../../../openspec/changes/archive/2026-10-26-refactor-frontend-navigation-lifecycle/proposal.md) preserves all nine files, including `.openspec.yaml`, with identical SHA256 hashes before and after the move.

## Canonical synchronization

| Capability | Result |
| --- | --- |
| [frontend-navigation-lifecycle](../../../openspec/specs/frontend-navigation-lifecycle/spec.md) | Created with the delta's Purpose and five requirements |
| [erp-company-foundation](../../../openspec/specs/erp-company-foundation/spec.md) | Updated the settings/draft requirement and added operational initialization ownership |
| [monitoring-serpro](../../../openspec/specs/monitoring-serpro/spec.md) | Added hydration and later-visit freshness |
| [office-erp-commercial-management](../../../openspec/specs/office-erp-commercial-management/spec.md) | Added authority-bound pending context coordination |
| [work-management](../../../openspec/specs/work-management/spec.md) | Added one competence/URL synchronization owner |

Nine requirements were added and one modified. All ten delta requirement blocks match their canonical counterparts with no remaining merge. Existing Purpose sections, unrelated requirements and surviving scenarios were preserved. Main specs contain one Requirements section and no delta operation headers. Strict canonical validation passed all 32 specifications; strict selected-change validation passed before archival.

After archival, `openspec validate --all --strict --no-interactive` passed all 33 active items (32 specs and the separate onboarding change). All 15 scoped documentation links resolved, `git diff --check` passed, and archive file hashes remained unchanged. Application/runtime paths had no diff from the implementation checkpoint.

## Evidence and boundaries

The [implementation evidence](implementation.md), [page matrix](page-matrix.csv) and [browser acceptance](browser-acceptance.md) retain the accepted source and validation results: 2,245 passing frontend tests, lint/typecheck/build exit 0, independent review and 159 sanitized browser observations. These application checks were performed during implementation, not rerun for this documentation-only archive operation.

This operation changes specs, archive paths and documentation only. The branch remains isolated from the original main checkout. Other active changes, including customer onboarding, remain intact. No backend/schema, application/runtime configuration, identities, development stores, keys, registration or production/provider gates changed. Live provider delivery and PostgreSQL/Redis-process concurrency are outside this frontend archive's acceptance.

## Local integration followup

After the user selected local integration, `main` was confirmed current with its upstream and fast-forwarded to `ec602a973bd4ac26fb6aace59e37ec455a3e70e1`. The complete frontend suite then ran in the main checkout and returned 0: 2,245 tests / 267 suites, zero failures, cancellations, skips or todos. Strict OpenSpec validation passed all 33 active items on main. This followup changes documentation only; no application tests are attributed to an untested product revision.

After the successful merged-source run, the implementation worktree and `codex/frontend-navigation-lifecycle` branch were removed. The task-only frontend/proxy processes and three isolated runtime containers were stopped gracefully. Their two data volumes were retained. Private configuration, storage, logs and build evidence were copied into an ignored local backup and verified before worktree removal; the original environment files remained unchanged. Other worktrees and the pre-existing `reference/sistema/` files were preserved. No remote push was performed.

## Support worktree cleanup

The user subsequently requested completion of the remaining task worktrees. All eight support worktrees were clean; their changes had already been integrated by cherry-pick. The original worker histories were retained in main by history-only merge `864121c`, with identical tracked trees before and after. The one patch-equivalence exception was an import already present before its cherry-pick; its helper changes and subsequent authority fix were verified in main. A private Git bundle also preserves the original refs.

A fresh complete frontend run on `864121c` passed 2,245 tests / 267 suites with exit 0 and no failures, cancellations or skips. All eight support worktrees, including the coordinator-created detached verification checkout, and seven named worker branches were then removed without force. Private QA evidence and storage were backed up and verified first; remaining owned QA browser processes were terminated. `git worktree list` now contains only the main checkout. The original environment, retained isolated data volumes and unrelated files remain preserved. This final documentation update does not change the tested product tree.
