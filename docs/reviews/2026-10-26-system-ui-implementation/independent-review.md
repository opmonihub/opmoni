# Independent review and correction

A fresh read-only reviewer inspected an isolated draft snapshot in `.worktrees/system-ui-final-review`, without tests or modifications. No Critical findings. The reviewer found no change adding financial commands, expanding tenant access or changing CFOP resolution, and no material modal/authority defect in reviewed paths.

## Important findings

1. Client sort was labeled Cliente while the visible row may prefer trade_name and the authoritative name contract sorts legal name. Keep the planned name API/default and label both header/menu Razão social. Regression first observed Cliente != Razão social.
2. WorkTarefasBulkBar hid assignment on phones with no alternative. This predates the refactor but fails the mobile acceptance criterion. Add a native mobile menu with the same guarded assignment children and dismissal command; retain status as the visible primary action and desktop controls. Regression first failed because the phone trigger was absent.

The two fixes are in one correction pass. Regression results are recorded in acceptance.md once commands finish. Final full checks must cover the corrected sources.

## Evidence reconciliation

The reviewer read the historical catalog worker report, which correctly said PostgreSQL had not been run by that worker. The coordinator's later dedicated PostgreSQL results (34 tests/254 assertions plus1/8 scoped regression) are in resume.md and acceptance.md; the historical report now points to them. Task5.3 remains backed by actual isolated PostgreSQL evidence, not by SQLite. The task matrix and current reports retain pending browser/full-check gates.

Ruling: preserve sorting by legal name and label it precisely rather than changing the approved query contract to display-name coalescing. Cost if wrong: a separate display-name ordering field would need API/spec work; the current control states the actual existing field.

## Same correction pass: authority close

Cross-host verification found an Office-only case: parent authority closure hid the editor while its already-open discard dialog stayed mounted. A rendered-editor regression first observed the surviving confirmation. The open watcher now clears discard state on parent closure; the same rules were already present in the Admin shared policy. This closes only UI-owned discard state; original pending-operation recovery remains parent-owned.

## Production verification findings

The source review did not establish SSR/browser acceptance. Runtime testing subsequently reproduced equal-criteria SSR recursion and native loadingAuto focus timing, plus a disconnected menu-item opener. Meaningful regressions failed first and then passed in the82-test combined boundary suite. Their final full-source checks remain required; the original source review is not represented as acceptance of these later corrections.
