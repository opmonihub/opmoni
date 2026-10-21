# Opmoni UI/UX: complete source map and template audit

Date: 2026-10-20. Application source: `810506f` in `.worktrees/erp-finalization`. Reference: actual repository-root `.dashboard`.

## Result

The existing Nuxt UI shell, Public Sans typography, zinc/green identity and native components are the foundation to retain. Consistency work should restore the reference's deliberate page hierarchy and focused interactions while preserving Opmoni's real APIs, permissions, context isolation and recovery behavior. A new visual direction is unnecessary.

The source map covers **all 90 application Vue page files: 40 ERP and 50 access/office/platform**, plus **all 8 template page files yielding 7 routes**. This includes parent shells and dynamic route families; page-file counts are not counts of distinct runtime URLs. Source coverage is complete for this inventory. Browser coverage is a bounded sample, not end-to-end acceptance of every route.

## Complete reports

| Report | Coverage |
|---|---|
| [01 — Actual template patterns](01-template-patterns.md) | Shell, navbar/toolbar, table styling, forms, overlays, navigation, theme, mobile source patterns and demo-only behavior. |
| [02 — ERP end-to-end map](02-erp-end-to-end.md) | Every ERP page, API/action wiring, Company/office permissions, loading/empty/error/recovery states, delivered versus unfinished workflows, template differences and prioritized findings. |
| [03 — Access, office and platform](03-access-office-platform.md) | Public access, Clients, Fiscal capture, Monitoring, Work, Team, Settings, Super Admin and office ERP approval; full route/flow matrix and 17 findings. |
| [04 — Current browser comparison](04-browser-comparison.md) | Nine opened screenshots, eight DOM measurements, light/dark desktop/mobile comparison, four axe audits, a provisional five-dimension score and scoped evidence limits. |

## Consolidated priorities

P0 means established immediate severe exposure/data loss; P1 means major operational trust, identity or required-workflow gap; P2 means recovery, usability or consistency gap; P3 means polish. Planned modules are not newly discovered outages. No P0 was established by this limited audit, which is not a security certification.

| Priority | Finding | Evidence / work boundary |
|---|---|---|
| P1 | Inbox falsely confirms email delivery | O01, report 03. Simulated timeout success; no actual send contract. Gate/label the demo or separately implement honest sending. |
| P1 | Security settings display password-update/delete controls without handlers | O02, report 03. Define supported behavior; never infer account deletion semantics from a template. |
| P1 | Client detail can retain the wrong record after route failures/response inversion | O03, report 03. Source-supported request-ownership defect; focused runtime reproduction remains pending. |
| P1 | Cash and five ERP operating modules lack their required workflows | Report 02. Cash Task 12 and purchases/stock/sales/fiscal/accounting Tasks 13–18 remain pending. Route/tab/API existence is not delivered workflow acceptance. |
| P2 | Finance's default inline entry pushes records below the viewport | V01, report 04. First record y802 desktop / y1386 mobile; reference y194 / y216. Use an explicit create action and focused editor. |
| P2 | Generic settings recovery/permissions and narrow cross-tab stale-cache path | Report 02. Ordinary native Company switching clears state correctly. The cross-tab race is source-derived and unverified in browser. |
| P2 | Purchase XML promises a larger file limit than the parser accepts | Report 02. UI 5 MiB; parser 2,000,000 bytes; proxy total request 4 MiB. Preview errors clear the file, and confirmation is absent. |
| P2 | Failed dependent reads look empty; platform ERP list has weak request/action guards | O05/O06/O08/O11, report 03. Reuse established retry/latest-request patterns. |
| P2 | Monitoring grants Super Admin write affordances without local write role | O16, report 03. Backend still requires membership role; UI inconsistency, no backend bypass established. |
| P2 | Mobile Inbox mounts duplicate detail editors | O17, report 03. Source-supported; layout/focus impact needs runtime confirmation. |
| P2/P3 | Demo navigation/settings, member-flow wording, structured errors, copy/density consistency | O04/O07/O09/O10/O12–O15, report 03; V02/V03, report 04. Preserve honest product capability and required authority boundaries. |

The detailed reports contain **26 issue groups: 0 P0, 5 P1, 18 P2 and 3 P3** (six ERP groups, seventeen office/platform groups, three browser groups). Related groups can share causes; these are not 26 independent backend defects. Template accessibility findings are grouped under V03 and are not automatically application defects.

## Template fidelity contract for remaining ERP work

1. **Shell:** retain native dashboard group/sidebar/panel geometry, the current Company/Account selectors and grant-filtered navigation. Preserve mobile sidebar behavior and context reset.
2. **Hierarchy:** navbar identifies the page and primary action; toolbar carries tabs/context/filters; body prioritizes records/detail. Opening a page must not imply starting a new transaction.
3. **Creation/editing:** prefer the reference's explicit primary action and native modal/slideover for compact entry; larger editors may use a deliberate dedicated surface. Preserve draft/error/recovery lifecycles and focus return. Do not repeat Finance's always-open entry as the default for Cash and subsequent modules.
4. **Lists:** reuse the existing panel/sheet families, typed domain columns, meaningful statuses, numeric alignment, contextual row-action names, server pagination and clear filtered-empty state. No generic first-six-backend-keys table as a released operating workspace.
5. **Forms:** use native labelled fields, Brazilian Portuguese copy, structured 422 feedback and permission-aware actions. Money stays exact decimal strings; zero is allowed only where the backend contract allows it.
6. **States:** separate initial loading, successful empty, failed initial read with retry and transient failed refresh with visibly stale confirmed data. Definitive auth/context loss clears protected state. Success requires a real acknowledgement; unknown financial outcomes retain exact-operation recovery.
7. **Theme:** preserve Public Sans, zinc/green semantic tokens and dark mode. Keep the documented ERP light contrast correction; the reference's brighter white-on-green button fails measured contrast. Do not import template demo data, unsafe unnamed controls or fake success behavior.
8. **Acceptance:** review the actual template beside representative desktop/mobile and light/dark states before accepting each implementation task; verify forms/menus/focus and exact role/context boundaries. Source map, unit tests and browser screenshots establish different kinds of evidence.

## Execution status and recommended sequence

Task 10 purchases/XML backend and Task 11 Finance remain completed under their existing scoped acceptance. **Task 12 remains held** at the audit checkpoint: only its untracked RED workflow test exists; no cash application implementation was accepted. The original ERP plan is still active. This audit did not edit application source, mark unfinished modules complete or change release gates.

Before resuming cash implementation, carry the hierarchy/form contract above into its existing design checkpoint and brief. Keep the previously accepted exact-decimal, session ownership, server `can_operate`, zero-opening/closing and recovery rules. Apply focused corrections with independent review and bounded direct browser acceptance; do not launch another broad redesign or replay completed work.

Suggested UI follow-up sequence: `$impeccable harden` for identity/error/honest-action issues; `$impeccable layout` for list/editor composition; `$impeccable adapt` for mobile control reachability; `$impeccable clarify` for copy/demo boundaries; `$impeccable polish` only after functional acceptance. These are possible targeted work steps, not actions performed by this audit. A later `$impeccable audit` should measure the corrected states again.

## Verification and orchestration

Three disjoint explorers completed report-only work: template (requested Luna/medium), ERP (Luna/high), and access/office/platform (Sol/high for authority boundaries). Runtime model routing, token usage and monetary cost were not exposed and remain unknown. Workers did not delegate or modify application files. The coordinator read the reports, returned concrete corrections and owned browser evidence and final synthesis.

The current browser sample fits both viewport widths and loads Public Sans. Finance axe reports zero violations in both desktop themes with two incomplete rules each; this does not establish whole-product WCAG conformance. Current source inventory, evidence JSON and local report links were checked. No application lint/test/build rerun was needed for report-only changes; the retained runtime uses the already verified Task 11 immutable build. SERPRO, fiscal canaries, email/WhatsApp delivery, printers and unexercised browser flows remain unverified.
