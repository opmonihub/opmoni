# ERP management density correction

## Problem and behavior

The user's actual 1046px dashboard captures contradicted the earlier visual acceptance: the clients table required horizontal scrolling and subscription rows repeated client/company names and multiple descriptions. The correction keeps essential data in compact desktop rows and moves secondary information into explicit row expansion. Clients and subscriptions use UCollapsible records on mobile. New subscriptions remain directly reachable through Ativar ERP on clients without a contract, and contract/selection actions remain available.

Nuxt UI Table was selected for desktop scanning and its native expanded-row slot; UCollapsible was selected for narrow-screen records. Cards would repeat borders and increase row height without improving the operation. Plans retain their compact module popover. Other ERP tables keep their existing presentation. Compact and expandable behavior are opt-in on OfficeErpDataTable.

Settings omit decorative subtitles and expose trial isolation and suspension-policy explanations through title-adjacent Nuxt UI Popover controls. Important rules remain accessible without occupying each card's initial view.

## Invoice loading investigation

Read-only authenticated GETs in the existing user session measured approximately 3.35s for context and 3.58s for invoices, under high host load. The page confirms authority then requests data; this remains intentional. Search previously repeated both requests per keystroke. A regression reproduced 8 calls versus 2 baseline calls after 3 rapid edits; a 300ms debounce now coalesces the edits into one current context confirmation and one final-query read. No authorization cache, permission bypass, retry policy or fake loading behavior was introduced. This is a request-count improvement, not proof of improved initial API latency.

## Validation boundaries

Browser layout and interaction checks use real frontend code against an isolated synthetic API, preserving development records and user-session tabs. Chromium emulation does not establish physical-device or Safari behavior. No backend changes, database migrations, credentials or dependency additions were made.

The first isolated Nuxt dev cold render took 1m47s after roughly 75s initial server setup; no render loop was established. The production build is used for the decisive browser capture batch.

## Checks

Scoped ESLint, full frontend typecheck and the final production build passed (build exit 0, 7m55s). The previous complete frontend run passed 2,251 tests. A subsequent run after the three visual fixes passed 2,225 cases but failed the onboarding file: the new mobile assertion searched the entire list, including another expanded subscribed client. A concise boolean assertion reproduced the wrong scope, then the query was narrowed to the unsubscribed record; the complete onboarding module passed 27/27. The final full frontend suite passed all 2,251 tests in 267 suites, with zero failures, cancellations or skips (668.8s, exit 0). Scoped ESLint also passed after the test-scope correction.

A new full finish review followed the user screenshot contradiction and requested exactly three corrections: retain the section selector until desktop route labels fit beside the sidebar; place optional settings help next to card titles; keep Ativar ERP as the mobile action for unsubscribed clients. One correction batch was applied. The latest scoped design-detector run returned an empty findings list for those three product paths; this is not a whole-application detector claim.

The final production confirmation batch measured 60px desktop rows at viewport widths 1,440, 1,286 and 1,046px, with collapsible records at 375px. The 1,286px viewport reproduced the supplied panel dimensions (1,046px wide, body about 498px high). No horizontal overflow regions or page exceptions were measured in those eight client/subscription layouts. All eight layouts passed the same overflow measurements. Six browser interactions passed: desktop client details/action, 50-row client26 enrollment handoff, mobile client operations, mobile unsubscribed activation, mobile contract details/action, and contextual policy help. Settings and invoices additionally fit the 1,286px and 375px document widths in light/dark captures.

The final scoped finish verdict was ship: all three requested fixes were resolved, with no visible regressions from that correction batch. This verdict covers those fixes, not an unrestricted whole-surface acceptance.

The browser emitted a generic hydration-mismatch warning on some production SSR loads. The final server log also recorded several SSR icon-loading timeouts; no icon-loading fix is claimed. Its cause was not established; console-clean or hydration-fix acceptance is not claimed. Initial API latency remains outside the layout correction's acceptance.

No backend source changed. A separate regular PHP test attempt was terminated with SIGTERM before a final result and does not establish backend acceptance. PostgreSQL/concurrency, live fiscal/provider operations, physical devices and Safari were not validated.

The implementation worker was requested as Luna Medium and the visual finish reviewer as Sol High; actual routing telemetry was not confirmed. Private command logs, runners and synthetic browser evidence are preserved outside the commit in the root repository's ignored .superpowers/erp-management-density-correction directory. No generated raster assets ship in the product.

The named isolated Playwright session was closed and its absence verified. Only the task-owned fixture and production frontend processes were stopped and verified absent; the user browser tab and development runtime were preserved. Integration into main or a remote remains pending the user choice.
