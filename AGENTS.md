# Repository Guidelines

Opmoni serves Brazilian accounting offices. `frontend/` is the Nuxt app and `backend/` is Laravel 13. Browser and SSR call relative `/api/v1/*`; Nuxt proxies them to Laravel with a private ingress key. PostgreSQL and Redis back persistence, cache, sessions, and queues.

## Project Structure & Module Organization

- `frontend/`: Nuxt application, including its existing sample server handlers. `backend/`: Laravel 13. `docs/` and `openspec/`: product decisions and planning artifacts.
- Canonical behavior is `openspec/specs/`; accepted decisions are `docs/architecture/product-decisions.md`; the delivered-versus-pending map is `docs/architecture/system-spec-reconciliation.md`. Setup and the full check matrix are in `README.md`.
- Fiscal capture runs inside Laravel. `services/fiscal/` is planned and absent. Read `docs/architecture/fiscal-service.md` before changing fiscal integration.
- `reference/` and the Nuxt sample handlers (`/api/customers`, `/api/mails`, `/api/members`, `/api/notifications`) are references, not approval to expand scope.

## Build, Test, and Development Commands

From the repository root:

```bash
docker compose exec frontend pnpm lint
docker compose exec frontend pnpm typecheck
docker compose exec frontend pnpm test
docker compose exec frontend pnpm build
docker compose exec backend composer test
docker compose exec -T backend vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose restart queue scheduler
```

The PostgreSQL suite uses database `opmoni_test` and Redis databases 13–15. Create that database once; leave the development database and Redis databases 0–2 intact.

On the host, run `pnpm --dir frontend <script>`, choosing `lint`, `typecheck`, `test`, or `build`. In `backend/`, run `composer test`, then `vendor/bin/pint --dirty --format agent` after PHP edits. Some cross-layer tests read the root Compose file and sibling frontend sources, which are absent from the backend-only container. Run the complete regular PHPUnit suite from the host repository layout:

```bash
cd backend && FISCAL_ENVIRONMENT=producao vendor/bin/phpunit
```

CI runs frontend lint, typecheck, and build on Node 24, and backend `composer test` plus Pint on PHP 8.4. It does not run `pnpm test` or the PostgreSQL suite.

## Coding Style & Naming Conventions

- User-facing copy is Brazilian Portuguese; code and artifacts are English.
- Terms: an Account is the accounting office; a Client is an office portfolio client, distinct from an ERP company customer; Company is the ERP tenant; Super Admin is a platform role with no implicit tenant access; Office Fiscal capture is separate from ERP fiscal issuance.
- Backend files follow `backend/AGENTS.md`. That file and `backend/CLAUDE.md` are Laravel Boost output; leave them generated. Frontend files follow `frontend/AGENTS.md`. `.ai/rules` is absent.

## Testing Guidelines

Run the narrowest check that covers the change, then the matching frontend lint/typecheck or backend Pint command. Report live SERPRO, fiscal canaries, and browser flows as unverified when they were not run.

## Commit & Pull Request Guidelines

- Commit summaries use the imperative mood, optionally with a conventional prefix and scope (e.g. `fix(erp): use aria-label on navbar toggle in erp layout`).
- Pull requests describe the change, reference the OpenSpec change when one applies, and list the checks run plus any flows left unverified.

## Git Worktrees & Spec Workflow

- Create worktrees for this repository under `/home/obsidian/dev/opmoni/.worktrees/`.
- Reuse a suitable existing worktree before creating another one, and preserve its uncommitted work.
- Use native worktree tools only when they honor this directory. Otherwise use `git worktree add` with an explicit path inside `.worktrees/`.
- Do not create this project's worktrees under `/home/obsidian/.codex/worktrees/` or outside the repository's `.worktrees/` directory.
- Verify `.worktrees/` is ignored by Git before creating a worktree. Use the `codex/` branch prefix for new branches unless the user requests another name.
- In-flight work lives in `openspec/changes/`. Apply a change with `.agents/skills/openspec-apply-change`. Archiving a change leaves production fiscal and ERP gates unchanged.

## Security & Configuration

- Preserve `APP_KEY` and existing `.env` files. Keep ingress keys, certificates, and passwords out of Git, logs, and public Nuxt config.
- Schema changes use `php artisan migrate --force --no-interaction`. Roll back by restoring a compatible app version. Skip `migrate:fresh`, identity recreation, and `APP_KEY` rotation.
- Leave `ERP_REGISTRATION_ENABLED=false` until launch acceptance. Fiscal and SERPRO production gates stay as configured unless the task is that rollout.

## Frontend UI

- For every frontend UI change, apply `/home/obsidian/.codex/skills/nuxt-ui/SKILL.md` before editing. Read the relevant references and consult the Nuxt UI MCP for actual component APIs and official examples.
- Compose pages and forms with the existing Nuxt UI components, dashboard patterns, and project shells. Verify compatibility with the installed version and inspect the relevant official page or component example before introducing a custom UI primitive.
