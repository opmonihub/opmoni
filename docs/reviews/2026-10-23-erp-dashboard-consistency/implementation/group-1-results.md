# Group 1 — sidebar context geometry

Status: DONE_WITH_CONCERNS (synthetic display coverage explicitly bounded below).

## Scope and setup

Worktree: `/home/obsidian/dev/opmoni/.worktrees/erp-ui-audit-shell`.
Branch: `codex/standardize-erp-dashboard-ui`; source `5ce7d51`; planning checkpoint `5b752b1`.
Main `.worktrees/` ignore verified. Existing modified root AGENTS.md and untracked frontend/AGENTS.md were preserved and excluded from the commit. Existing Docker frontend mount points to main frontend; implementation measurements instead use this worktree's independent host Nuxt process and `.nuxt` output. Dependency install/prepare completed with offline frozen lockfile; no dependency, environment or instruction edits made by this group.

Read proposal/design/delta spec, group brief/global constraints, project instructions, Nuxt UI dashboard/conventions references, local generated sidebar theme and main `.dashboard` layout, and Impeccable layout/craft floor. Nuxt UI MCP DashboardSidebar usage/theme confirms header slot override. Impeccable context found no PRODUCT.md/DESIGN.md; this scoped correction preserves incumbent presentation.

## Change

Only runtime change: ERP `UDashboardSidebar` ui.header = `h-auto min-h-(--ui-header-height) py-3`. Native horizontal padding, navigation, context component, footer and collapse persistence remain supplied by existing components. No new tests asserting class strings were introduced.

## Baseline / after verification

Commands run from worktree root, both before and after:

```bash
pnpm --dir frontend exec node --experimental-strip-types --test tests/erpWorkspaceShell.test.ts tests/erpMobileShell.test.ts tests/erpSidebarNavigation.test.ts tests/erpWorkspaceNav.test.ts
pnpm --dir frontend exec eslint app/layouts/erp.vue
```

Both test runs: exit 0, 32 tests / 4 suites, 0 failures. Both ESLint runs: exit 0, no output. Retained raw baseline/after outputs in this implementation directory. `git diff --check` exit 0. Impeccable detector exit 0, findings `[]`. Full lint/typecheck/build and broader suites belong to final group and were not run here.

## Browser evidence and RED/GREEN

Normal seed login succeeded on `http://localhost:3000` after page hydration; normal company selector chose existing audit fixture. Seed existence/hash match was checked read-only, printing booleans only. No password reset, seed, membership grant or business-data mutation was used. Port 127.0.0.1 auth origin was rejected; localhost origin works. Cookies naturally reused on localhost:3107; no session or authority fabrication.

All measurements use actual Nuxt components/CSS from worktree source at localhost:3107, default 16px root size. Baseline desktop reproduction exactly matches audit D01: header 0–64px; office control -6–26px; company control 60–92px; first navigation 72–104px; 20px interactive overlap (RED).

| Scenario | Header | Office control | Company control | First navigation | Result |
| --- | --- | --- | --- | --- | --- |
| Real office + company, desktop 1366x900 | 0–144 | 34–66 | 100–132 | 152–184 | GREEN, 0px overlap |
| Collapsed desktop | 0–64 | hidden | accessible factory expand button | native icon navigation | GREEN |
| Synthetic company-only client display | 0–78 | absent | 34–66 | 86–118 | GREEN, 0px overlap |
| Synthetic long names, desktop | 0–144 | 34–66 | 100–132 | 152–184 | GREEN, 0px overlap, names truncate within controls |
| Real office + company mobile 390x844 | 0–145 | 34–66 | 100–132 | 153–185 | GREEN, 0px overlap |
| Real office sample `/` | native 0–64 | native original context | native original context | unchanged shell | ERP layout absent; original header classes retained |

Programmatic geometry assertions verify controls inside headers and <=1px navigation intersection for all four expanded scenarios. Mobile screenshot visually inspected alongside desktop. Mobile native menu opened, retained both selectors, navigated to `/erp/cadastros/unidades`, and closed (office control no longer visible; ERP toggle remains). Desktop collapse persisted after settled state and reload: header64px, context combobox absent, factory expand button present. Immediate reload during state update was inconclusive; settled reload confirmed native persistence.

Synthetic scenarios changed only existing identity presentation in browser memory, without requests to provision/grant/edit backend records; original identity was restored immediately. They establish actual component geometry, not independent company-only authentication or SSR acceptance. Full real-identity company-only coverage remains for broader acceptance. Office shell sample intentionally retains native header geometry; this group does not repair office-shell pre-existing issues.

## Retained evidence

Files prefixed `group-1-` include baseline/after tests and ESLint, before/after desktop screenshots and rectangles, collapsed screenshot/rectangles and persistence, synthetic scenario rectangles/long-name screenshot, mobile screenshot/rectangles/navigation, office sample screenshot/geometry, and detector output. Baseline audit files outside implementation were not changed. Credentials, session cookies and ingress keys are not included.

## Runtime handoff

Leave owned frontend running: pnpm launcher PID 1083142; listener PID 1083209; loopback port 3107. Logs `/tmp/opmoni-erp-ui-group1-nuxt.log` (private runtime only, not committed). Runtime cwd is worktree `frontend/`; server-only ingress environment was read programmatically from existing Docker frontend and never written to any environment file. It points to existing local backend port8000. No backend, queue or scheduler was restarted.

Browser named session: `erp-ui-group1`, normal authenticated fixture. Reuse sequentially via `agent-browser --session erp-ui-group1 ...`; navigate `http://localhost:3107/...`. Browser currently desktop and collapsed after persistence check; factory button expands normally. Never run uncoordinated browser actions on this session. For a separate browser session use normal localhost:3000 login; localhost:3107 mutating auth Origin is not configured. Do not expose cookies in command output or evidence. No saved credential/state file was created. Stop only these owned runtime processes after all groups finish, never the main Docker runtime.

## Limits / risks

No known geometry regression in covered states. Company-only and longer names use explicitly synthetic client display; real identity SSR and broader auth lifecycle were not tested here. No business operation, fiscal/SERPRO canary, build or complete suite acceptance is claimed. OpenSpec checkboxes remain untouched for controller review.
