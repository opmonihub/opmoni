# Group 2 — finance empty state (D08)

Status: DONE_WITH_CONCERNS (browser populated coverage is synthetic client display; final broad checks remain owned by controller).

## Scope and architecture

Worktree `/home/obsidian/dev/opmoni/.worktrees/erp-ui-audit-shell`, branch `codex/standardize-erp-dashboard-ui`, base `c032076`. Existing dirty root AGENTS.md and untracked frontend/AGENTS.md preserved and excluded. Read group brief, global constraints, group1 runtime handoff, proposal, design and delta spec, repository/frontend instructions, Nuxt UI conventions/component-selection/data-table/dashboard references, installed Empty.vue/generated theme, and main `.dashboard` customer table. Nuxt UI MCP Empty usage confirmed native title/description/icon props and examples. Applied TDD, systematic-debugging and verification skills.

Finance composable remains sole owner of loads, metadata, retained rows, authorization and operations. Its successful reads set rows/meta; pending reads set loading; ordinary failure preserves previous confirmed rows/meta and records pageError. Runtime change only moves the existing empty message into native UEmpty outside the wide table, conditioned on confirmed metadata, zero current rows, idle loading and no pageError. Table retains its 48rem minimum and scrolling in other states. Its empty slot deliberately remains blank to prevent the native generic empty message from misrepresenting unavailable reads. Toolbar, routes/hash, forms, retry/reconciliation handlers, finance composable API, requests, payloads and authority checks unchanged.

## TDD and checks

Commands from worktree root:

```bash
pnpm --dir frontend exec node --experimental-strip-types --test tests/erpFinanceWorkflows.test.ts
pnpm --dir frontend exec eslint app/components/erp/ErpFinanceWorkspace.vue tests/erpFinanceWorkflows.test.ts
git diff --check
```

Clean RED before runtime edits: exit1, 58 tests,55 pass,3 fail. Expected failures: confirmed-empty lacks native UEmpty; failed-empty-refresh and pending-empty-refresh still display the old successful empty guidance. Initial harness selector sought Ver detalhes instead of existing Detalhes; source/stack inspection corrected that test selector before clean RED. UTable test double now invokes empty only when data has no rows, matching native semantics. No CSS-class-string assertions.

GREEN: exit0,58 tests/3 suites,0 failures. Covers unknown and initial pending/failed reads without fabricated zero count or empty guidance; confirmed empty with native title/description outside table; populated and failed-refresh with unchanged confirmed rows and Detalhes action; failed/pending refresh after confirmed empty without presenting a new empty success; failures retain actionable Tentar novamente; every state retains Nova obrigação. Existing exact decimal, pagination/totals, authority/race, CSRF, draft, mutation and operation-recovery tests all passed.

Changed-file ESLint exit0/no output. First lint identified two multiline cleanup callbacks written on one line; formatting corrected without assertion changes. git diff --check exit0. Full suite/typecheck/build and final detector intentionally deferred to controller per task boundary.

## Mobile browser evidence

Sequentially reused authenticated `erp-ui-group1` on existing worktree runtime localhost:3107 at390x844, default16px root. Normal existing audit fixture was read; no business operation or backend mutation occurred. Before screenshot/metrics show empty guidance in a768px-wide table atx17 while page remains390px. After normal confirmed-empty read, table absent; native title x42.08–347.92, y541–565; description x70.80–319.20,y573–593. Both messages fit fully inside viewport. Screenshots visually inspected.

Populated coverage uses a complete synthetic obligation inserted only into the mounted finance component's browser-memory rows/meta, with an exact large decimal string and long category. This simulation issues no request, grants no authority, persists nothing, and was restored immediately to original rows/meta. Actual worktree table and installed Nuxt UI components rendered it. Table1294.56px; native inner scroller client768/scroll1295; visible outer frame x16–374,client356/scroll768. Both inner and outer scrollLeft successfully moved from0 to100. Page scrollWidth=clientWidth=390 for empty and populated states.

An initial geometry assertion incorrectly required the native inner768px scroll container to fit inside the viewport. Ancestor geometry inspection identified the existing nested local scroll containers; final assertions measure the visible outer frame and verify both scroll containers. No runtime change was made for this measurement correction. Synthetic populated coverage establishes display/scroll geometry only, not a real populated backend read or financial operation. Pending/failure states are exercised through the real mounted workspace/composable in the focused tests, not simulated browser network outages.

Evidence files prefixed group-2 contain clean RED/GREEN output, final ESLint output, before/after geometry and screenshots. Saved JSON output was decoded into native JSON objects; trailing spaces in RED runner output were normalized. Audit baseline outside implementation unchanged. No credentials/cookies/keys included.

## Runtime handoff and limits

Existing localhost:3107 runtime remains running unchanged with worktree-owned .nuxt and server-only environment supplied by group1. Named browser erp-ui-group1 remains authenticated, mobile390x844 on `/erp/financeiro#obligations`; original normal empty finance data restored. Browser and runtime reusable sequentially by group3. No backend/queue/scheduler restart, dependency/env/gate/schema/instruction edit, merge/push/archive or checkbox update. No live fiscal/SERPRO, real financial mutation, independent populated backend acceptance, or full-project release acceptance claimed.
