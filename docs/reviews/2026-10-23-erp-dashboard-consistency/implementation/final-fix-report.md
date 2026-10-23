# Final review combined correction

Base checkpoint: `4f8646d`; branch `codex/standardize-erp-dashboard-ui`, isolated worktree `.worktrees/erp-ui-audit-shell`. Parent owns scoped rereview, OpenSpec checkboxes and final acceptance; no subagents or integration operations.

## Important — last-confirmed Finance/Cash guidance

Verified the reviewer reproduction against the existing real composables: transient failures retain previous rows/meta, whereas definitive authority loss clears private state. Added only native `UAlert.description` bindings to the Finance list, Cash registers, Cash sessions and Cash movement-history alerts. Existing non-null metadata selects explicit last-confirmed wording, including a previously confirmed zero result; absent metadata selects never-loaded-successfully wording. Cash tables suppress their ordinary successful-empty message while that read has an error. Existing loaders, query/filter state, guards, actions, permissions, decimals and mutation/recovery state are unchanged. Nuxt UI skill/reference guidance, actual official MCP Alert usage/API and installed4.11.3 component/theme were checked (`final-fix-ui-reference.json`).

RED before runtime edits:12 actual mounted regressions,0 pass/12 fail,exit1, each fails on missing previously-confirmed/never-confirmed guidance. Finance and all three Cash families cover prior none/rows/empty. GREEN after the minimal runtime change:104 tests/10 suites,104 pass,0 fail/skips,exit0. Exact focused commands are recorded in `final-fix-focused-results.json`. The real composables perform synthetic read failures/recoveries; assertions require retained data/table bindings, metadata-based distinction, failed-empty suppression, successful recovery removing stale feedback and restoring ordinary empty copy, read-only calls and the unchanged query. Finance retry invokes the actual retained alert action. Existing suites also retain query filters, authority, idempotency/uncertain operation and draft/recovery coverage. No business/provider requests occur.

Cash's existing native-UI boundary now forwards description text and empty messages rather than swallowing them; no application logic is simulated away. Finance checks the actual compiled consumer's native alert props and native empty branch. The first changed-file lint found two separators in an added TypeScript test type; formatting was corrected, with no assertion changes. Final changed-file lint exits0. One detector pass on the final two edited UI files exits0 with`[]`; the earlier group9 detector remains its previous-source checkpoint.

## Minor — whole-branch log whitespace

Preserved all five exact prior readable log byte streams in matching `.txt.raw.gz`, verified round-trip equality and original SHA256 before normalizing trailing whitespace/extra blank EOF only. No distinct raw history was overwritten. `final-fix-log-normalization.json` records original/normalized hashes. The original audit/inventory/evidence outside implementation remain immutable.

Earlier group9 unstaged/staged exit0 checks described only those differences, and did **not** prove a clean accumulated branch. Reviewer reproduced `git diff --check 5b752b1 4f8646d` exit2/29 diagnostics. This correction records explicit implementation-range verification as well as current unstaged/index checks. Historical group4 pass/failure content is unchanged beyond readable whitespace; gzip preserves the exact old files.

## Verification

| Command | Exit | Result | Evidence |
| --- | --- | --- | --- |
| `pnpm --dir frontend test` | 0 | 1559 tests /218 suites;1559 pass;0 failures/skips; 240.46s | `final-fix-test.txt` |
| `pnpm --dir frontend lint` | 0 | passed; 46.42s | `final-fix-lint.txt` |
| `pnpm --dir frontend typecheck` | 0 | passed; 62.02s | `final-fix-typecheck.txt` |
| `pnpm --dir frontend build` | 0 | passed; 144.94s | `final-fix-build.txt` |
| `openspec validate standardize-erp-dashboard-ui --strict --no-interactive` | 0 | valid | `final-fix-openspec.txt` |
| `git diff --check 5b752b1`, staged range and final HEAD range | 0 | explicit full implementation range (postcommit record follows) | `final-fix-integrity.json` |


Fresh logs are separate `final-fix-*` artifacts and preserve untrimmed captured streams in `.txt.raw.gz`. All heavy commands run in one serial pipeline, with no duplicate tests/typecheck/build or browser/server workload. Previous group9 successful results are retained as checkpoint4f8646d rather than presented as validation of this runtime revision.

## Evidence and acceptance limits

No browser/server/interceptor was reopened for this description-only correction; no full matrix was repeated. Current evidence is mounted real component/composable failure/empty/recovery behavior plus the verified native Alert API. Earlier actual healthy geometry, light/dark/sidebar/table/settings/selection/overlay evidence belongs to checkpoint4f8646d; the new failed-refresh descriptions were **not** separately browser-measured. ERP automated axe remains unverified, and earlier office-only axe findings remain out of scope. No global WCAG, physical-touch, real-auth company-only SSR, independent concurrent-store or live provider acceptance is claimed.

Coverage retains exact97 inventory tuples (40 pages/57 components) and dispositions; four Finance/Cash direct/host rows reference this correction. Group8 source manifest remains historical; `final-fix-source-hashes.json` records final changed-source hashes. Protected paths, baseline immutability and configured-secret scans are rechecked without printing values. D06 remains pending independently for all five opaque settings references; no selector, migration or same-number native mapping was introduced. No dependency/backend/env/theme/permission/query/serializer/gate change, main-store write, reset, grant, replay, merge/push/archive or checkbox edit.

No owned resources remain; prior cleanup stays valid and no main Docker service was touched. The branch, worktree and ignored SDD workspace remain available. Parent receives one combined fix checkpoint for one scoped rereview.
