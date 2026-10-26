# Frontend navigation lifecycle implementation evidence

Source: `2454fa8`, branch `codex/frontend-navigation-lifecycle`, isolated worktree `.worktrees/frontend-navigation-lifecycle`.

## Baseline

- 105 page files are inventoried once in `page-matrix.csv`. Candidate means an audited source trigger, not verified browser behavior. Unavailable means current lifecycle validation is pending.
- Planning artifacts were already present and untracked at entry. Main checkout has unrelated untracked `reference/sistema/`; it remains untouched.
- Locked frontend dependencies installed locally with pnpm 12.11.2 on Node 24.18.0; installation exited 0 without a lockfile change.
- Existing runtime wrapper is bound to a different checkout and branch. No runtime guard was bypassed. User authorized a separate runtime. Private network/volumes `opmoni-navigation-lifecycle-*`, backend loopback 18868, frontend loopback 3354, PostgreSQL 15468, Redis 16368; mounts point to this worktree. Additive migrations ran only in the new `opmoni_test` database. Proxied health returned 200. No original runtime guard or development store was changed.
- Existing onboarding artifacts, canonical specs, backend, configuration and production gates remain outside implementation scope.

## Progress

- Task 1.1: branch/base, locked versions, local dependencies, exact source mount, isolated stores and private ingress health verified.
- Task 1.2: all 105 files accounted for; evidence matrix initialized.
- Task 1.3: local failing regressions in progress; browser reproduction pending.

## Validation

No implementation acceptance claimed yet.

## Implementation checkpoint before parallel execution

- Seven initial regressions failed for audited behavior: stale commercial navigation, three hydration payload callbacks, two Work URL writers, missing abort signal, overwritten dirty field. A further mounted settings-shell regression failed at two editor mounts after query-only navigation.
- Initial fixes: hydration cache callbacks, reactive commercial tabs, native settings child identity, scalar field draft reconciliation in shared section/email editors, shell-owned Work month URL writer, cancellable latest-read runner, lazy Admin subscription plan catalog, pending commercial context coalescing.
- Focused checkpoint: 124 tests passed, 0 failed; changed-path ESLint passed; `git diff --check` passed. Additional prior hydration-related suite: 135 passed. These are local checks, not final browser acceptance.
- Remaining areas include full draft sibling/conflict acceptance, comprehensive authority-owned list retention, safe-read ownership across families, first-capability watcher reconciliation, operational initialization, runtime/browser acceptance and full frontend matrix.
- User explicitly requested parallelization. Workers must use separate project-local worktrees and exclusive file ownership. Shared list-state interface and all integrations will be serialized.
