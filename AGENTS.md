# Repository guidelines

## Start here

- Explore the current checkout, manifests, configuration, routes and sibling implementations before changing code. Historical reports and GitHub history do not establish current behavior or passing checks.
- Read [backend/AGENTS.md](backend/AGENTS.md) for Laravel work and [frontend/AGENTS.md](frontend/AGENTS.md) for Nuxt work. Read both for changes spanning the API boundary; keep framework-specific rules in those files.
- [README.md](README.md) documents setup and operational constraints. The frontend README still contains dashboard template instructions.
- Consult [canonical specifications](openspec/specs/), [product decisions](docs/architecture/product-decisions.md) and the [system reconciliation map](docs/architecture/system-spec-reconciliation.md) for the affected capability. For a named OpenSpec change, locate its matching artifacts and checkout before implementing it.

## Application map

| Location | Responsibility |
| --- | --- |
| `backend/` | Laravel 13 on PHP 8.4; JSON API, authentication, authorization, persistence, jobs and scheduled commands. No Vite or Node build. |
| `backend/routes/` | `api.php` registers `/api/v1` and includes business, company ERP and office commercial route files. `console.php` includes recurring business commands. |
| `backend/app/Erp/` | Company ERP domain services; `Commercial/` contains office ERP enrollment, subscriptions, billing, members and access recovery. |
| `backend/app/Services/` | Business services, including client portfolio, SERPRO and WhatsApp integration. Controllers live under `app/Http/Controllers/Api/V1/`. |
| `frontend/` | Nuxt 4, Vue 3, Nuxt UI 4 and Tailwind CSS 4. Pages, layouts and composables live under `app/`; shared API types and pure helpers under `shared/`. |
| `frontend/server/` | Nitro handlers, including the private Laravel proxy at `api/v1/[...path].ts`; some other API handlers remain dashboard samples. |
| `scripts/` | Worktree setup and task-specific guarded test runtimes. |
| `docs/`, `openspec/` | Architecture, specifications, planning and recorded acceptance evidence. |

Fiscal functionality currently runs inside the Laravel backend. `services/fiscal/` is a planned extraction, not an implemented service.

## API and domain boundaries

- Reuse `$api` or `useAccessApi().api` with relative paths such as `/clients`; the transport adds `/api/v1`, CSRF and context headers. Preserve its request admission, cancellation and session-generation checks.
- Keep `NUXT_BACKEND_URL` and `NUXT_INGRESS_KEY` private. The ingress key matches backend `FRONTEND_INGRESS_KEY`; `FRONTEND_ORIGIN` must match the browser origin. Preserve trusted ingress, cookie forwarding and Origin/CSRF protection.
- Office Account and ERP Company are distinct scopes. `ResolveAccount` validates the selected session Account against `X-Account-Id`; `ResolveCompany` validates Company access against `X-Company-Id`. Request body IDs are not authority. Preserve scoped queries and establishment permissions.
- Super Admin authorizes platform administration; tenant operations still require real membership. Office commercial operations use `ResolveOfficeErpCommercial` and the trusted `OfficeErpExecutionContext`.
- Reuse `OfficeErpMoney` and `frontend/shared/utils/officeErpCommercial.ts` for commercial amounts. Keep monetary values as decimal strings and avoid PHP floats or JavaScript `Number()` conversions.
- Preserve idempotency and recovery through `OfficeErpOperations` and `useOfficeErpCommand`. Replay and operation lookup must remain subject to current authorization.
- Reuse existing UI components, layouts and `frontend/app/utils/pageShell.ts`; product copy is Brazilian Portuguese.

## Commands and verification

Use Node.js 24 and the pnpm version pinned in `frontend/package.json` (`12.11.2`). Use locked dependency installation: `pnpm --dir frontend install --frozen-lockfile` and, from `backend/`, `composer install --no-interaction --prefer-dist`.

| Working directory | Command | Purpose |
| --- | --- | --- |
| Repository root | `pnpm --dir frontend dev` | Nuxt development server |
| Repository root | `pnpm --dir frontend lint` | ESLint |
| Repository root | `pnpm --dir frontend typecheck` | Nuxt types and separate API contract TypeScript checks |
| Repository root | `pnpm --dir frontend test` | Node test runner; no live ingress listener |
| Repository root | `pnpm --dir frontend build` | Production frontend build |
| `backend/` | `composer run dev` | Laravel development server |
| `backend/` | `php artisan route:list --path=api --except-vendor` | Inspect registered API routes |
| `backend/` | `OPENSSL_CONF=/etc/ssl/openssl.cnf FISCAL_ENVIRONMENT=producao vendor/bin/phpunit` | Complete regular backend suite |

- Start with the narrowest relevant tests. PHPUnit accepts a test path or `--filter`; focused frontend tests use `pnpm --dir frontend exec node --experimental-strip-types --test tests/<name>.test.ts`.
- The regular backend profile uses SQLite in memory, array cache/sessions and fake providers. Set `OPENSSL_CONF` before PHP starts for certificate fixtures. `FISCAL_ENVIRONMENT=producao` above controls test expectations, not development services.
- Cross-layer backend tests read the root Compose file and sibling frontend sources. Run the complete regular suite with the repository layout available; the backend-only development container lacks those sibling mounts.
- PostgreSQL/Redis tests use `backend/phpunit.pgsql.xml` and `tests/Integration/bootstrap.php`: dedicated `opmoni_test` database and Redis databases 13/14/15. Verify runtime ownership and test-store isolation before running them; some suites also require task-specific checkout guards.
- Use `frontend/tests/helpers/` for existing Vue/composable harnesses. Follow the backend AGENTS instructions for PHPUnit conventions and PHP formatting.
- Run checks appropriate to the affected behavior and report the actual results. Distinguish local unit/feature, PostgreSQL/Redis, browser and real-provider evidence.

## Environments and change safety

- Preserve existing `.env` files, Laravel `APP_KEY`, private storage and database/Redis volumes. Never reset development data or rotate keys as a setup or test shortcut; destructive operations require explicit authorization.
- Development Compose uses the fixed project name `opmoni`, PostgreSQL 18 and Redis 8, with queue and scheduler services. Inspect service ownership before operating a stack. Backend job/command changes require restarting the corresponding long-running workers.
- Keep parallel worktrees under `.worktrees/`, with their own dependencies and isolated runtimes. `scripts/setup-worktree.sh` copies missing environment files and installs dependencies; copied values still point at the source stores. Running the unchanged root Compose file in another worktree reuses the development stack.
- Task runtime scripts under `scripts/*-runtime/` enforce specific checkouts, branches and mounts. Preserve their guards; an `Unexpected task checkout.` failure means the runtime does not match the checkout.
- Keep ERP registration, trial, operating-write, fiscal and production release gates explicit. Do not enable them to satisfy local tests. Apply schema changes explicitly in the intended environment.
- Preserve unrelated working-tree changes. Prefer existing domain helpers and components, and avoid dependency changes or broad refactors outside the requested scope.
