# Office ERP commercial management: preservation baseline

Recorded on 2026-10-24 for OpenSpec task 1.1. This is a read-only source and ownership baseline, not application acceptance. The proposed change remains untracked in the selected worktree, and no canonical specification was synchronized here.

## Checkout and ownership

| Location | Branch and HEAD | Observed working state | Ownership boundary |
| --- | --- | --- | --- |
| `.worktrees/office-erp-commercial-design` | `codex/office-erp-commercial-design` at `f8efb50ad69e23a872e5b65ab18fff48b86f3590` | Only untracked `GLOSSARY.md` and `openspec/changes/add-office-erp-commercial-management/` before this record | Preserve both; never stage them as part of task 1.1. |
| Repository root | `main` at `f8efb50ad69e23a872e5b65ab18fff48b86f3590` | Uncommitted ERP template/product setup archive and documentation work, plus an untracked `reference/sistema/` tree | Read only. Do not import its archive or overwrite its files. |

The selected change has 11 untracked files: `.openspec.yaml`, `proposal.md`, `design.md`, `tasks.md`, `component-matrix.md`, `ui-reference-audit.md`, and five delta specifications. `GLOSSARY.md` is independently untracked. Their SHA-256 fingerprints were captured in the private task report; the public review record intentionally does not imply ownership of either artifact. No other tracked or staged change existed in the selected worktree before this record.

The root checkout has 27 tracked path changes and 17 untracked files (44 status entries at inspection). Its tracked changes include README, fiscal architecture, system reconciliation, ERP template/product setup and Marketup review records; removal of the active `align-erp-template-and-product-setup` change; and canonical updates to `access-pages`, `erp-company-foundation`, `erp-core-catalogs`, `erp-finance-and-cash`, `erp-fiscal-issuance`, and `erp-sales`. Its untracked additions include the dated archive of that change, two new canonical capabilities (`erp-company-initialization`, `erp-product-tax-profiles`), two review records, and `reference/sistema/`. These are an in-progress archive, not an input to apply wholesale to this branch.

## Canonical capability comparison

SHA-256 values are hashes of the current file bytes in each checkout. The five rows correspond exactly to the proposed change's capability set.

| Capability | Selected worktree canonical spec | Root checkout canonical spec | Baseline relationship and preservation point |
| --- | --- | --- | --- |
| `platform-commercial-management` | `f193bfcec7495f7f2e769a1888ed0f7a362bb5e2fa3bb5ade955541de5d88540` | Same | Current office subscription gate, strict `users/clients/monitorings` limits, and raw Plan API envelopes must survive ERP resale additions. |
| `erp-commercial-access` | `a9f4f9dbd76d2ca80adf66a5afc92c52b48dea8027581d3396031bb27199f9bf` | Same | Current Company subscription is separate from the office subscription; platform-only activation and the direct unlinked subscription path require an explicit, bounded extension. |
| `access-pages` | `4afdd307bb5d2a6be88889556ea88fdd9a5cf5b88240b2b002c50c9969f3313c` | `8aa99683c4f3df685718457c86111e2b250c118490804d099e0ae522c1694f26` | Root has an additional Office Settings Portuguese navigation requirement and two scenarios (11 added lines). Reconcile this concurrent addition before a future canonical sync; retain session, membership, and office/company context behavior. |
| `office-erp-commercial-management` | Absent | Absent | New proposed capability only; no existing canonical text to replace. |
| `office-erp-billing` | Absent | Absent | New proposed capability only; no existing canonical text to replace. |

## Existing contracts relevant to the change

The selected worktree's `README.md`, `docs/architecture/product-decisions.md`, and `docs/architecture/system-spec-reconciliation.md` were read for this baseline. They identify `openspec/specs/` as canonical, `/api/v1` as the application API, session plus selected `X-Account-Id` as the office context, and Company access as a separate membership/commercial boundary. D009 requires real Account membership even for a Super Admin. The existing office Plan editor has three nullable limits and a raw Plan list; an inactive office subscription denies tenant writes but permits authorized reads. Current ERP registration is disabled by default, grants a pending Company subscription, and reserves activation for platform administration. The proposed office sale and maintenance exception must be reconciled with those specific contracts, without opening a general inactive-subscription bypass.

The system reconciliation document records dated ERP and platform acceptance, including local evidence limits. Its ERP template/product setup entry describes that work as an active isolated change in this checkout; root is simultaneously archiving it. Treat those statements as checkpoint documentation until root archive work is complete. No application, browser, PostgreSQL/Redis, fiscal, or live-provider suite was run for task 1.1. Task 1.2 must verify isolated runtime labels, mounts, and test stores before application suites.
