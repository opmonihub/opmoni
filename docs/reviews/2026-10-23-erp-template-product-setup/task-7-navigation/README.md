# Persistent settings and catalog navigation evidence

Task 2.3, review base `4933153`, captured 2026-10-23 on the isolated worktree runtime at `localhost:3340`. The four PNGs pair with geometry JSON; additional JSON records keyboard/history/reload states. All accepted states are hydrated. [`verification.json`](verification.json) records assertions and SHA-256 identity of the exact production/test source.

| Surface | Desktop 1366×900 | Mobile 390×844 |
| --- | --- | --- |
| Settings | [`settings-desktop.png`](settings-desktop.png) | [`settings-mobile.png`](settings-mobile.png) |
| Catalog | [`catalog-desktop.png`](catalog-desktop.png) | [`catalog-mobile.png`](catalog-mobile.png) |

Each state has one dashboard panel/navbar and a single-row 49px native toolbar, with overflow contained in the toolbar and the active destination visible. Settings keyboard evidence shows ArrowRight from SMS to Pontos, then Enter navigation and Back to SMS. Catalog evidence follows Etiquetas → Produtos → Back → reload; the mobile product create action remains inside x334–374. Local child actions retain their resource ownership rather than being transferred through global state or Teleport. The actual SFC suite verifies permission/busy/trigger wiring and removes former-child actions on transitions.

Installed Nuxt UI 4.11.3 DashboardToolbar already supplies native horizontal overflow. Its NavigationMenu did not reveal an offscreen active or keyboard-focused item in this composition; measured before/after values and the minimal native-scroll correction are recorded in verification.json. No focus takeover or custom navigation primitive was added.

Reproduce after starting the task runtime with the documented private environment: use a dedicated named agent-browser session, import the private synthetic session cookie from the ignored mode0600 file without printing it, set viewport, navigate the listed route, assert hydration/active bounds, then screenshot. All browser commands used explicit10–25second subprocess deadlines. The private capture helper is `.superpowers/sdd/align-erp-template-and-product-setup/task-7-browser.py`; command/evidence report is `task-7-report.md` there. No credentials/cookies are committed. Only navigation was exercised; no business write or release gate changed. Existing visual tokens, widths and placeholder content remain outside this task's restyling scope.
