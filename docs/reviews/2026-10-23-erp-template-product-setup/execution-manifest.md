# Task 1.1 execution manifest

Date: 2026-10-23. OpenSpec change: `align-erp-template-and-product-setup`.

## Source and ownership

- Worktree: `/home/obsidian/dev/opmoni/.worktrees/align-erp-template-and-product-setup`.
- Branch: `codex/align-erp-template-and-product-setup`; source baseline: `7ad0994`.
- The root `.worktrees/` directory is Git-ignored. Main's existing dirty/untracked work was retained.
- Task Compose project: `opmoni-erp-template`, using only `scripts/erp-template-runtime/compose.yml`. The root Compose project `opmoni` was never launched or changed.
- Backend: `opmoni-erp-template-backend-1`, image `opmoni-backend:dev`, working directory `/workspace/backend`, entire worktree bind-mounted read/write at `/workspace`. This retains sibling frontend and root Compose sources needed by cross-layer tests.
- PostgreSQL: `opmoni-erp-template-postgres-1`, PostgreSQL 18, task-owned volume `opmoni-erp-template-postgres`, database `opmoni_test`.
- Redis: `opmoni-erp-template-redis-1`, Redis 8, task-owned volume `opmoni-erp-template-redis`, actual client connections use databases 13, 14 and 15.
- The three containers attach only to `opmoni-erp-template-net`; network service aliases include `backend`, `postgres` and `redis`, matching the existing PostgreSQL integration bootstrap. No queue/scheduler service or consumer is started.
- Frontend: host Node.js 24 process, started from this worktree's `frontend/`, bound to `127.0.0.1:3340`, using this worktree's independent `node_modules` and `.nuxt`. Its private backend target is the task backend loopback listener. Its PID and executable identity are stored in ignored mode-0600 `.superpowers/sdd/align-erp-template-and-product-setup/frontend-process.json` and verified against `/proc` before subsequent commands.
- Exact container identities, mounts and ports are in `task-1-ownership-and-preservation.json`. No shared development volume is mounted.

| Listener | Task address |
| --- | --- |
| Nuxt | `http://localhost:3340` |
| Laravel | `http://127.0.0.1:18840` |
| PostgreSQL | `127.0.0.1:15440` |
| Redis | `127.0.0.1:16340` |

All four ports were unused before startup. Published Docker ports and the native frontend command are checked for loopback binding.

## Preparation and reproducible commands

Run from the worktree root:

```bash
scripts/erp-template-runtime/run prepare
scripts/erp-template-runtime/run up
scripts/erp-template-runtime/run verify
scripts/erp-template-runtime/run ps
curl --fail http://localhost:3340/api/v1/health
```

`prepare` copies existing root/backend/frontend `.env` files only when absent, with mode 0600; it never generates or rotates `APP_KEY`. Existing files are preserved. Dependency manifests and locks matched the source checkout before independent `cp -a --reflink=auto` copies of backend `vendor` and frontend `node_modules`; no shared writable dependency tree, hardlink to a sampled source dependency, or shared `.nuxt` was introduced. Missing dependencies are copied only after lockfile equality is checked. No dependency or lockfile changed.

`up` starts only the three named task containers and an owned native Nuxt process. It reuses a recorded frontend PID only after verifying its directory and command. The private ingress key is read programmatically from the task backend into process memory; it is never printed or written into the PID record. The ignored frontend log is `frontend-runtime.log` alongside that PID record. Startup is asynchronous; wait for the ready message before HTTP checks.

Only additive normal migration execution was requested against the initially empty task-owned store:

```bash
scripts/erp-template-runtime/run backend php artisan migrate --force --no-interaction
```

Exit status: 0. No reset/fresh/rollback/seed command ran. Existing application migrations were applied unchanged; no identities were imported or created.

The final frontend uses installed executables directly. Avoid the `pnpm` command on these relocated copied dependencies: pnpm 12 attempted automatic dependency relocation/reinstallation. Its task-created cache was retained under the ignored ledger. Use:

```bash
scripts/erp-template-runtime/run frontend node --experimental-strip-types --test tests/erpSidebarNavigation.test.ts tests/erpWorkspaceShell.test.ts tests/erpMobileShell.test.ts tests/clientsNavigationHeader.test.ts
scripts/erp-template-runtime/run frontend node node_modules/eslint/bin/eslint.js .
scripts/erp-template-runtime/run frontend node node_modules/nuxt/bin/nuxt.mjs typecheck
scripts/erp-template-runtime/run frontend node node_modules/typescript/bin/tsc --project tsconfig.contracts.json
scripts/erp-template-runtime/run frontend node node_modules/nuxt/bin/nuxt.mjs build
```

The last four commands are documented for later task validation; they were not run for this runtime-only task. Full frontend test coverage uses the same direct Node test runner and the filenames/glob in `frontend/package.json`.

## Baseline results and preservation

| Check | Result | Evidence |
| --- | --- | --- |
| Task ownership, source contents, mount, network membership, store versions/databases, resolved gates | exit 0 | `task-1-runtime-verification.txt` |
| Backend health, tenant access boundary, access foundation | 14 tests, 143 assertions, exit 0 | `task-1-backend-baseline.txt` |
| Frontend navigation, mobile shell, workspace shell, Clients header | 36 tests, 5 suites, exit 0 | `task-1-frontend-baseline.txt` |
| Backend `/up`, direct API health, proxied frontend API health | HTTP 200 | `task-1-runtime-verification.txt` |
| Frontend anonymous `/api/v1/me` | HTTP 401 | `task-1-runtime-verification.txt` |
| Frontend ERP registration status | HTTP 200, registration unavailable | `task-1-runtime-verification.txt` |
| Bash/Python syntax and PHP syntax | exit 0 | Task report |
| Pint on the runtime PHP verifier | passed, exit 0 | Task report |
| Main checkout/worktree preservation | unchanged status and 30 file fingerprints; six main container IDs/start times/mounts unchanged | `task-1-ownership-and-preservation.json` |

The regular backend tests hardcode browser origin `http://localhost:3000`; their command explicitly sets that origin for the test process only. The first run inherited runtime origin 3340 and failed seven origin checks (exit 1); the corrected test command passed without any application change. SQLite `:memory:` is selected by the regular test bootstrap, while runtime store verification connects read-only to the task PostgreSQL and Redis services.

Only the dedicated test database received migrations. No main PostgreSQL or Redis commands were executed. Development Redis databases 0–2 were never queried, flushed or written by task commands. The development services remained running and attached to their original mounts/network. This evidence asserts that task commands and topology do not target them; it does not claim to freeze writes performed independently by the running development application.

## Runtime corrections and limits

An initial internal task bridge did not publish usable host ports with this Docker 29 runtime. Recreating only the task project on a normal private task-owned bridge restored loopback listeners without changing its volumes. The containerized Nuxt endpoint subsequently timed out connecting to the task backend; DNS resolved the expected numeric IPv4, ARP resolved, and a frontend-only recreation did not repair TCP connectivity. No host firewall, Docker daemon, or main network was changed. The approved native frontend fallback restored the same-origin API health path, preserving task stores and source identity. The final bridge has exactly the three remaining task containers and no frontend container.

Resolved application config verification asserts disabled ERP registration, operating writes, production release and fiscal production; fake ERP fiscal provider; disabled WhatsApp dispatch and Office fiscal CT-e/NFS-e/manifestation gates. No worker, scheduler, provider dispatch, SERPRO request or external delivery ran. The normal bridge is not an outbound firewall; dispatch prevention relies on those resolved settings and the absence of consumers.

The container does not mount shared Git metadata, so `vendor/bin/pint --dirty --format agent` cannot resolve the worktree's external `.git` pointer and exits 1 with “The [--dirty] option is only available when using Git.” The exact runtime PHP file was checked directly with Pint and passed. Application PHP was not edited.

No full test suite, PostgreSQL integration suite, browser flow, authenticated ERP acceptance, live fiscal provider or live SERPRO canary ran. The existing integration access test includes `migrate:fresh` and was deliberately not invoked. The original OpenSpec checklist remains unchanged for controller review.

## Handoff to the browser baseline task

Use this worktree and the running listeners above. Task 2 must prepare a dedicated synthetic User/Company membership, establishment, contract and minimal product data in this task-owned `opmoni_test`, guarded by the runtime ownership/store verifier. Store any generated login credentials in an ignored mode-0600 task config. Do not reuse development users or the integration browser fixture that flushes/reset stores. Authenticate through the normal `/api/v1/csrf` and `/api/v1/login` same-origin frontend endpoints, retaining cookies privately; `AccessController` selects a default direct Company for a user without office membership. No identity is seeded by task 1.

Keep production/registration gates disabled. Product reads can use the synthetic membership; operations requiring write capability need their own explicit test-only scoped setup in the later acceptance task. Report/accounting routes that enforce `ErpReleaseGate` may need the existing allowlisted preview mode for the exact synthetic Company/User pair; do not enable global production release merely to capture a browser baseline.

The local reference is available at `/home/obsidian/dev/opmoni/.dashboard`. For a separate reference listener, copy that ignored reference into this worktree's `.dashboard` independently (including its existing dependencies when lock-compatible), then start its installed Nuxt CLI on a separately verified free loopback port such as 3341. Do not mutate or start the main checkout's reference dependencies. No reference listener was started in task 1.
