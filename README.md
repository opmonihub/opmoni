# Opmoni

## Project structure

- `frontend/`: complete Nuxt application, including its existing sample server handlers.
- `backend/`: Laravel 13 backend without a frontend starter kit, Blade welcome page, Vite, or Node dependencies.
- `docs/` and `openspec/`: product decisions and planning artifacts.
- Fiscal capture currently lives in `backend/`; `services/fiscal/` remains a planned separate PHP fiscal service and is not implemented.

## Specifications and coverage

[Canonical OpenSpec contracts](openspec/specs/) cover access, Account isolation, Clients, Fiscal, Monitoring/SERPRO, Work, Team, WhatsApp, company ERP, platform administration, and runtime boundaries. The [system reconciliation map](docs/architecture/system-spec-reconciliation.md) links registered application routes and frontend page families to their specs and implementation evidence, records D001-D009 status, and distinguishes delivered behavior from pending platform targets.

The [archived reconcile-system-specs change](openspec/changes/archive/2026-10-13-reconcile-system-specs/proposal.md) preserves the delta history. The earlier [Super Admin proposal](openspec/changes/add-super-admin-platform/proposal.md) remains pending where its stronger contracts differ from the integrated baseline; its foreign support elevation is superseded by D009. Syncing specs does not complete its implementation checklist.

The nine ERP changes and complete-XML accounting workflow were synchronized and archived on 2026-10-17; the [archive record](docs/architecture/system-spec-reconciliation.md#erp-and-complete-xml-archive--2026-10-17) lists their scope and evidence limits. Live fiscal canaries, production release approval, and several browser acceptance flows remain unverified in the recorded evidence. Archiving does not enable ERP production, fiscal transmission, or wider automatic science.

## Development

### Docker Compose

Build and start the development services from the repository root:

```bash
cp .env.example .env
# Replace the empty FRONTEND_INGRESS_KEY with the output of:
openssl rand -hex 32
export APP_UID="$(id -u)" APP_GID="$(id -g)"
docker compose up --build -d
docker compose ps -a
```

The application images use `frontend/docker/` and `backend/docker/`. The `setup` service installs Composer dependencies, preserves or generates the Laravel application key, clears development caches, and applies PostgreSQL migrations before the backend and queue start. Frontend dependencies are installed on startup. Both applications run as your host user.

| Service | Local address |
| --- | --- |
| Nuxt | `http://localhost:3000` |
| Laravel health | `http://localhost:8000/up` |
| PostgreSQL 18 | `localhost:5432` |
| Redis 8 | `localhost:6379` |

Compose declares development environment variables explicitly on each service. PostgreSQL uses database/user/password `opmoni`; these settings are for the local development stack. Host ports can be changed with `FRONTEND_PORT`, `BACKEND_PORT`, `POSTGRES_PORT`, and `REDIS_PORT` before starting Compose.

#### Environment and ignore files

| File | Responsibility |
| --- | --- |
| `.env.example` → `.env` | Compose ingress key and published host ports |
| `backend/.env.example` → `backend/.env` | Laravel application key and host-development settings; Docker setup creates this file if absent |
| `frontend/.env.example` → `frontend/.env` | Nuxt private backend origin and matching ingress key for host development |

Compose passes its variables explicitly into each service; it does not load a child application's `.env` as its interpolation source. For host execution, copy both application examples and use the same secret for backend `FRONTEND_INGRESS_KEY` and frontend `NUXT_INGRESS_KEY`. Preserve an existing Laravel `APP_KEY` and existing environment files.

The root `.gitignore` owns shared environment, dependency, log, and editor exclusions, including the local `.dashboard/` reference. Application `.gitignore` files contain framework-specific rules. Laravel's storage/cache `.gitignore` files preserve empty runtime directories. Each `.dockerignore` remains independent because Docker builds use separate `frontend/` and `backend/` contexts; root Git rules do not protect Docker build contexts.

Source directories are bind-mounted. Vue updates use Nuxt HMR and file polling; PHP is loaded on each request with OPCache disabled. The queue uses `queue:work` so each job enforces its own timeout (85 seconds for fiscal capture, up to 300 seconds for bulk deletion), below the 420-second Redis reservation. Restart the queue and scheduler after backend job or command changes with `docker compose restart queue scheduler`; ordinary HTTP source edits require no image rebuild. The scheduler runs the imported recurring commands with `schedule:work`. `artisan serve --no-reload` preserves Compose environment overrides while using Docker service names instead of host loopback addresses; it does not disable loading updated PHP source.

Dependencies and Nuxt development metadata use separate named volumes. Production output is written under `frontend/.output` on the source mount so Nuxt can replace it during a normal build; the previous output volume is retained without mounting its root. PostgreSQL uses its own new `postgres18_data` volume mounted at `/var/lib/postgresql`, and Redis persists data in `/data`. Existing volumes are preserved. `docker compose down` stops the stack while retaining named volumes.

```bash
docker compose logs -f frontend backend queue
docker compose exec frontend pnpm lint
docker compose exec frontend pnpm typecheck
docker compose exec frontend pnpm build
docker compose exec backend composer test
docker compose exec backend php artisan migrate --no-interaction
```

After changing dependency manifests, install dependencies in the corresponding running container. Rebuild images when changing Dockerfiles, PHP extensions, or system packages. Compose environment changes require recreating the affected service.

```bash
docker compose exec frontend pnpm install --frozen-lockfile
docker compose exec backend composer install --no-interaction --prefer-dist
```

Configuration references: [Docker bind mounts](https://docs.docker.com/engine/storage/bind-mounts/), [Compose startup order](https://docs.docker.com/compose/how-tos/startup-order/), [PostgreSQL image and version 18 storage](https://hub.docker.com/_/postgres), and [Laravel queue listeners](https://laravel.com/framework/docs/13.x/queues#running-the-queue-worker).

### Without Docker

Start Nuxt in one terminal:

```bash
cd frontend
pnpm install
# On a fresh clone, configure the private ingress key before starting:
cp .env.example .env
pnpm dev
```

Start Laravel in another terminal:

```bash
cd backend
composer install
# On a fresh clone:
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

Laravel runs at `http://localhost:8000`, with a health check at `/up`. PostgreSQL is the default development database, with Redis for cache, sessions, and queues. The backend `.env` uses `127.0.0.1` for host commands; Compose overrides the hosts to `postgres` and `redis` inside containers. The frontend proxies `/api/v1/*` to Laravel. Set the same private key as `FRONTEND_INGRESS_KEY` in the backend and `NUXT_INGRESS_KEY` in the frontend for host execution; `FRONTEND_ORIGIN` must match the browser origin.

## Frontend and backend communication

The browser and Nuxt SSR use relative `/api/v1/*` requests. Nuxt forwards them to Laravel through a server-only proxy, preserving methods, query parameters, request bodies, cookies, and response status codes. The backend origin is private runtime configuration: `NUXT_BACKEND_URL=http://backend:8000` in Docker, or `http://127.0.0.1:8000` outside Docker. It is not placed in public runtime configuration.

The proxy returns Laravel responses, does not follow upstream redirects, and uses a ten-second timeout without automatic mutation retries. Access failures use a retryable JSON service error. Laravel owns password authentication, absolute session deadlines, CSRF and Origin checks, Account membership authorization, and platform administration. The proxy replaces caller-supplied ingress/forwarding metadata with its private key and actual socket client address; direct untrusted backend access is denied except for health checks.

```typescript
const { data, error } = await useFetch('/api/v1/health')
```

Verify the full connection through the frontend:

```bash
curl http://localhost:3000/api/v1/health
# {"status":"ok","service":"backend"}
```

New Laravel API routes belong under the `v1` group in `backend/routes/api.php`. The template's existing `/api/customers`, `/api/mails`, `/api/members`, and `/api/notifications` remain Nuxt sample handlers; they have not been migrated to Laravel.

## Validation

```bash
pnpm --dir frontend lint
pnpm --dir frontend typecheck
pnpm --dir frontend build
cd backend
composer test
vendor/bin/pint
```

## Access foundation

Open `http://localhost:3000`. Onboarding is available only when **both** users and Accounts are empty. The first registration atomically creates the initial Super Admin, office, and admin membership. If either a user or an Account exists, registration is closed and the interface offers login. New offices receive the basic subscription and four default departments through the account observer. Existing offices receive these defaults through an idempotent additive migration.

After password login, the default active member office enters directly, including users with multiple offices. The default is the earliest membership by membership ID among active Accounts. New login returns to that default; reload preserves manual switching within the session. AccountMenu handles optional switching after entry. No active memberships show unavailable access. Authenticated sessions have a fixed 12-hour deadline; pre-authentication CSRF sessions last at most 30 minutes. Logout revokes server-side credentials before clearing client state.

Super Admin can manage office names/status and issue one-use, seven-day owner links under `/admin/contas`. Copy new links at issuance; ordinary lists never reveal the token, and reissue invalidates the old link. Existing owner identities cannot be overwritten. Suspended offices retain their data but deny tenant operations and activation.

### ERP commercial access (disabled by default)

Company ERP plans, subscriptions and module entitlements are separate from office `plans` / `subscriptions`. Public signup uses `POST /api/v1/erp/register` only when the platform is already bootstrapped **and** `ERP_REGISTRATION_ENABLED=true` in the backend environment. New companies receive a **pending** subscription; Super Admin activates contracts under `/admin/erp/assinaturas` (no payment provider call). Owners read status at `/erp/contrato`. Ordinary ERP operating writes require an **active** company subscription (`403 erp_subscription_inactive`); foundation company settings remain editable while pending. Keep `ERP_REGISTRATION_ENABLED=false` until launch acceptance. `ERP_OPERATING_WRITES_ENABLED` remains independent and does not bypass the commercial gate.

Redis separates queues (database 0), cache/limits/locks (1), and sessions (2). Never flush these development databases to reset tests. Clients, Fiscal, Monitoring, Work, Team and business Settings are integrated into the current shell and `/api/v1` contract. Tenant operations require real account membership even for Super Admin; legacy support impersonation is superseded. Automatic password recovery remains deferred.

## PostgreSQL and Redis integration tests

Create the dedicated test database once; do not substitute the development database:

```bash
docker compose exec -T postgres createdb -U opmoni opmoni_test
docker compose exec -T backend vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose exec -T backend php artisan test --compact
docker compose exec -T frontend pnpm test
docker compose exec -T frontend pnpm lint
docker compose exec -T frontend pnpm typecheck
docker compose exec -T frontend pnpm build
```

`phpunit.pgsql.xml` uses isolated database `opmoni_test` and Redis databases 13/14/15 with test prefixes. Its bootstrap overrides inherited container settings, and assertions refuse destructive fixtures against development stores. Tests run separate PHP HTTP-kernel processes with real PostgreSQL/Redis for bootstrap, owner acceptance, email reservation, rate budgets, logout replay, switching/suspension, and lock ownership. The regular PHPUnit suite keeps SQLite/array drivers. Fiscal writer integration tests use PostgreSQL and isolated fake storage. Both PHPUnit profiles reserve 768 MB for the complete imported suite and report generation. Three optional live SERPRO probes require external credentials; local provider contracts run with fakes.

Run `php artisan migrate --force --no-interaction` explicitly after schema updates; migrations never run during HTTP requests. Identity transactions commit before session issuance. If session storage fails after registration or activation, restore Redis and log in with the committed credentials; do not repeat bootstrap or overwrite the existing owner. Failed switching/logout remain retryable; reconcile state before retrying timed-out mutations.


### Complete regular-suite repository layout

Several cross-layer backend tests read the root Compose file and sibling frontend sources. Run them from the host repository layout with installed Composer dependencies and an explicit fiscal test default:

```bash
cd backend
FISCAL_ENVIRONMENT=producao vendor/bin/phpunit
```

The PHPUnit bootstrap still forces SQLite in memory, array sessions/cache, and fake provider contracts. This setting controls test expectations; it does not change the running development stack's homologation setting. The backend-only development container does not mount the sibling files those tests inspect.

## Earlier access verification — 2026-10-12

This historical snapshot predates later default-office and Account menu changes. Its browser scenarios and review conclusion are not a claim about the current working tree.

| Check | Result |
| --- | --- |
| Backend SQLite/array suite | 16 tests, 142 assertions passed |
| Independent PostgreSQL/Redis processes | 16 tests, 365 assertions passed |
| Frontend routing/password boundary tests | 7 passed |
| Pint, ESLint, Nuxt typecheck | Passed |
| Default Nuxt production build | Passed |
| OpenSpec strict validation and whitespace checks | Passed |
| Independent implementation review | Findings resolved; no outstanding findings |

Browser acceptance used the production Nuxt output, a separate test backend, `opmoni_test`, and isolated Redis stores. It covered three-step bootstrap with password-free review, login, initial office cards, reload/SSR preservation, new-login selection, subsequent team switching, independent identities, shared-tab focus updates and logout, platform creation/atomic rename/suspension, owner activation/replay, suspension during selection, unavailable access, missing CSRF, and truthful retryable backend/Redis failures. First-access pages made no third-party requests and used no-referrer; public build output contained no private ingress credential.

Nuxt SSR propagates request cookies and response cookies, and preserves server-created ingress context through Nitro's `_platform` transport. The development listener sanitizes caller metadata before its private Unix-socket worker hop; forged IP/protocol/key headers cannot affect client budgets. Development API and empty-installation SSR onboarding were also verified. Temporary QA containers, identities, sessions and activation credentials were removed; development users/Accounts remained empty. These checks cover the access foundation, not deferred fiscal, membership-management or automatic recovery features.
