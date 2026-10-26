# Opmoni

## Project structure

- `frontend/`: complete Nuxt application, including its existing sample server handlers.
- `backend/`: Laravel 13 backend without a frontend starter kit, Blade welcome page, Vite, or Node dependencies.
- `docs/` and `openspec/`: product decisions and planning artifacts.
- Fiscal capture currently lives in `backend/`; `services/fiscal/` remains a planned separate PHP fiscal service and is not implemented.

## Specifications and coverage

[Canonical OpenSpec contracts](openspec/specs/) cover access, Account isolation, Clients, Fiscal, Monitoring/SERPRO, Work, Team, WhatsApp, company ERP, platform administration, and runtime boundaries. The [system reconciliation map](docs/architecture/system-spec-reconciliation.md) links registered application routes and frontend page families to their specs and implementation evidence, records D001-D009 status, and distinguishes delivered behavior from pending platform targets.

The [archived reconcile-system-specs change](openspec/changes/archive/2026-10-13-reconcile-system-specs/proposal.md) preserves the delta history. The archived [Super Admin change](openspec/changes/archive/2026-10-20-add-super-admin-platform/proposal.md) has completed local implementation acceptance: 46/46 valid tasks, plus ten explicitly retired originals from the original 56-item checklist. D009 supersedes foreign support elevation. Canonical synchronization and archive completed on 2026-10-20; live-provider verification remains unproven; see the [current reconciliation and evidence limits](docs/architecture/system-spec-reconciliation.md#current-platform-acceptance-2026-10-18).

The nine ERP changes and complete-XML accounting workflow were synchronized and archived on 2026-10-17; the [archive record](docs/architecture/system-spec-reconciliation.md#erp-and-complete-xml-archive--2026-10-17) lists their scope and evidence limits. Live fiscal canaries, production release approval, and several browser acceptance flows remain unverified in the recorded evidence. Archiving does not enable ERP production, fiscal transmission, or wider automatic science.

The [ERP template and product setup change](openspec/changes/archive/2026-10-24-align-erp-template-and-product-setup/proposal.md) completed 58/58 local implementation tasks, was integrated and pushed to `main` at `f8efb50`, and was synchronized and archived on 2026-10-24. Its eight capability deltas add 24 requirements and modify four; canonical contracts now include company initialization and product tax profiles. See the [archive record](docs/reviews/2026-10-23-erp-template-product-setup/archive-record.md). Provider production acceptance and release remain separate.

The [Office ERP commercial management change](openspec/changes/archive/2026-10-25-add-office-erp-commercial-management/proposal.md) completed 55/55 local tasks and 69/69 scenario acceptance, was merged into local `main` at `f86172e`, and archived on 2026-10-25 after rechecking all five synchronized capabilities. See its [archive and final verification record](docs/reviews/2026-10-24-office-erp-commercial-management/archive-record.md). No remote push or production rollout was performed; registration, provider, fiscal and payment gates remain unchanged.

The active [simplify-office-erp-client-onboarding change](openspec/changes/simplify-office-erp-client-onboarding/proposal.md) implements direct office customer enrollment, optional owner setup, office-attributed seven-day ERP trials, expiry and explicit paid conversion. It supersedes mandatory offer/invitation onboarding and no-trial clauses in the affected commercial contracts while retaining D009, existing financial history and legacy invitation compatibility. The regular backend suite, scoped Pint, frontend lint/typecheck/tests/build, private browser journeys, and synthetic restored-store migration/recovery evidence are recorded; the final fresh PostgreSQL profile is still running. Strict OpenSpec validation passed. See the [acceptance record](docs/reviews/2026-10-25-erp-client-onboarding/acceptance.md) and [54-scenario crosswalk](docs/reviews/2026-10-25-erp-client-onboarding/scenario-crosswalk.csv). No canonical spec synchronization, gate change or production rollout is implied.

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

### Paseo worktree setup

The root `paseo.json` runs `bash scripts/setup-worktree.sh` after Paseo creates a
worktree. The script copies missing root, backend, and frontend `.env` files from
`PASEO_SOURCE_CHECKOUT_PATH` with mode `0600`, preserving existing files and the
Laravel `APP_KEY`. Configure those three files in the source checkout first.

Setup installs locked Composer and pnpm dependencies into the new worktree;
pnpm's `postinstall` prepares Nuxt. It requires Node.js 24 and pnpm. If PHP and
Composer are unavailable on the host, setup uses the local `opmoni-backend:dev`
image in a temporary container. Build it once from the source checkout with
`docker compose build setup` if necessary. The temporary container mounts only
the new worktree and does not start Compose services, run migrations, rotate
keys, or change fiscal/ERP gates.

Paseo reads setup configuration from the **committed base ref** selected when
creating a worktree. Both `paseo.json` and the setup script must be committed to
that ref; choosing `origin/main` requires the commit to be available there.

Keep worktrees under this repository's `.worktrees/` directory. Paseo 0.12
configures the root globally in `~/.paseo/config.json`, rather than in project
settings. Merge the following into that file, preserve its existing settings,
and restart Paseo before creating a worktree:

```json
{
  "worktrees": {
    "root": "/home/obsidian/dev/opmoni/.worktrees"
  }
}
```

Paseo adds a project hash and workspace slug beneath that root. This setting
applies to every project on that daemon; use a separate daemon configuration
when other repositories need their own worktree locations. New Opmoni branches
use the `codex/` prefix. Dependencies and environment files belong to each
worktree, while copied environment values still reference the source checkout's
databases and services. Start services and apply migrations separately when
needed; the existing Compose file has a fixed project name and host ports, so
running it unchanged in parallel worktrees would reuse the development stack.

To repeat setup manually from a worktree:

```bash
PASEO_SOURCE_CHECKOUT_PATH=/home/obsidian/dev/opmoni bash scripts/setup-worktree.sh
```

Reference: [Paseo worktrees and setup hooks](https://github.com/getpaseo/paseo/blob/main/public-docs/worktrees.md).

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

### Platform administration and initialization

Apply additive schema changes before exposing the matching API and Admin UI. From the backend runtime, initialize the commercial catalog and missing Account subscriptions explicitly:

```bash
php artisan migrate --force --no-interaction
php artisan platform:initialize --no-interaction
```

Initialization inserts missing catalog slugs and visits Accounts in bounded chunks. It creates one active Básico subscription only when an Account has none, preserving edited plans, subscription status, identities and pending owner links. Repeating the command is supported. Existing provisioning paths use the same action when creating or issuing an Account. This command does not activate an existing canceled subscription or charge a customer.

The seven platform routes are `/admin`, `/admin/contas`, `/admin/planos`, `/admin/assinaturas`, `/admin/usuarios`, `/admin/suporte` and `/admin/serpro`. They accept a Super Admin without any Account membership. Entering tenant operations still requires actual membership; support search exposes ordinary member switching only. An active Account with an inactive subscription remains readable by its members while ordinary tenant writes are denied. Platform subscription editing and company ERP contracts remain separate. The legacy `/admin/erp/assinaturas` bookmark routes into the office ERP workspace; the seller workflow itself is under `/gestao-erp` and uses the explicitly selected office context. A platform-only Super Admin without office membership must select an office where they have a real membership before using tenant operations.

The platform API is versioned under `/api/v1`. Account list and detail use `GET /admin/accounts` and `GET /admin/accounts/{id}`; the list accepts literal name search or numeric ID, status, and bounded positive pages. Its envelope is `{data,meta}` with `meta.page`, `meta.per_page`, `meta.total`, and `meta.last_page`. Account rows and detail expose a bounded platform projection of contact, subscription/plan summary, member count, and pending-owner name/email/expiry; activation tokens and broad Account settings are excluded. `GET/PATCH /admin/accounts/{id}/commercial` remains available for compatibility. Summary and commercial metadata use `{data}`; the dedicated `GET /admin/summary` returns account, plan, subscription, and user totals plus at most five sanitized support-log rows.

Account issuance returns `{data, first_access}` with HTTP 201; reissue returns `{first_access}`. Only those immediate responses contain the one-use activation URL. Contact updates accept sparse phone, WhatsApp, UF, and city fields and merge them into `platform_billing_contact`, preserving unrelated settings. Supplying `phone: null` clears both the phone and its WhatsApp flag; a supplied UF is validated and normalized, while omitted fields are preserved.

Resource lists such as subscriptions and support logs use `meta.current_page`; plan list/detail/mutation endpoints return raw plan data rather than a `{data}` resource envelope. Plan update accepts only `name` (2–255 characters) and `limits` with the known `users`, `clients`, and `monitorings` keys. Limits must be null or nonnegative integers; slug and unknown fields are rejected. When `limits` is supplied, omitted known keys are normalized to null, so an empty or sparse object clears those limits. The Admin UI edits plans but does not expose create/delete actions; the API has a separate POST creation endpoint and no delete route. Subscription updates accept a real `plan_id` and `active`, `past_due`, or `canceled` status, without changing Account status, memberships, or activation and without payment collection. An inactive or missing subscription blocks tenant writes while authorized reads remain available.

SERPRO administration supports explicit connection upload or an explicitly selected Account's dedicated platform contracting vault. Super Admin-only `GET/PUT /api/v1/admin/accounts/{id}/contracting-certificate` returns safe configured/revision/X.509 metadata; there is no download or delete API. The platform vault remains separate from each tenant's certificate vault, and both store certificate material encrypted. Neither platform source selection nor Super Admin status creates tenant membership. Legacy attached tenant sources remain readable and can be preserved until explicitly replaced. Connection metadata is `GET /api/v1/serpro/connection`; an active selected Account member may read it, while PUT and `POST /api/v1/serpro/connectivity` require Super Admin. Upload writes use POST multipart with `_method=PUT` through Nuxt while retaining the public PUT contract. Uploads accept native PKCS12 e-CNPJ certificates up to 2 MiB, including an explicit empty password; requests above 4 MiB are rejected at ingress. Safe metadata never hydrates secret, password or file inputs. Account suspension and source replacement participate in the same credential revision fence as connection changes.

The diagnostic performs a bounded authentication request and discards returned tokens. Defaults are a 3-second connection timeout and 8-second provider timeout. The Nuxt proxy allows 15 seconds for diagnostics and certificate/configuration writes, 10 seconds for ordinary access/Admin routes, and remains below the 30-second session-lock budget; existing longer operational routes keep their scoped budgets. The backend checks the configured HTTPS authentication host, verifies TLS, disables redirects and retries, and limits both actor and trusted ingress IP independently to six diagnostic requests per minute. `409 connection_changed` and `429 rate_limited` are request failures; `failed_element` is exactly `configuracao`, `certificado`, `credencial`, or `provedor` on failure and null on success. Local fakes and generated-certificate mTLS fixtures establish protocol behavior, not acceptance of a live SERPRO account.

Keep `SERPRO_AUTH_URL` and `SERPRO_AUTH_TRUSTED_HOSTS` server-controlled; diagnostic overrides are `SERPRO_DIAGNOSTIC_CONNECT_TIMEOUT` and `SERPRO_DIAGNOSTIC_TIMEOUT`. `SERPRO_TEMP_DIR` may point to a writable private directory for short-lived PKCS12 material; the materializer removes files after use. Keep frontend `NUXT_BACKEND_URL` private, set matching backend/frontend ingress keys, and configure `FRONTEND_ORIGIN` to the actual browser origin. Timeout changes must preserve the diagnostic/provider/proxy/session-lock ordering rather than simply increasing one layer.

For application-only rollback, disable the new Admin UI and routes first, retain the forward schema, and restore an application version compatible with the encrypted consumer key and current certificate-source representation. Retain catalog/subscription records, encrypted certificate sources and audit history, together with `APP_KEY`, private storage and database backups. A separate schema reversal is guarded: the platform-credential migration refuses `down()` while its dedicated vault contains credentials. Do not delete credentials to satisfy that guard. Reversing the consumer-key migration decrypts its saved values, so the preserved compatible `APP_KEY` and a coordinated compatible application/migration pair are required. Verify any schema reversal on an isolated restored copy before using it; retaining the forward schema is the application rollback path documented here. Do not use `migrate:fresh`, destructive table drops, identity recreation or key rotation as rollback shortcuts. The vault has no public deletion endpoint; ordinary source changes use explicit replacement.

### ERP commercial access (disabled by default)

Company ERP plans, subscriptions and module entitlements are separate from office `plans` / `subscriptions`. Public signup uses `POST /api/v1/erp/register` only when the platform is already bootstrapped **and** `ERP_REGISTRATION_ENABLED=true` in the backend environment. New companies receive a **pending** subscription. The seven office seller pages are `/gestao-erp` (overview), `/gestao-erp/planos`, `/gestao-erp/clientes`, `/gestao-erp/assinaturas`, `/gestao-erp/faturas`, `/gestao-erp/recebimentos` and `/gestao-erp/configuracoes`; contract activation is performed by an office admin within the selected office, not by a platform-only Super Admin. Owners use `/erp/ativar` for an issued owner invitation and `/erp/contrato` to consult their own Company terms/invoices. The office seller API is grouped under `/api/v1/erp-commercial`: context/summary/operation lookup, office clients, plans/revisions, contracts and lifecycle, owner invitations, invoices/issue/corrections/print, receipts/reversals/private proofs, and office policy preview/update. Company-owned invoice reads use `/api/v1/erp/commercial-invoices`. Ordinary ERP operating writes require an **active** Company subscription (`403 erp_subscription_inactive`); foundation Company settings remain editable while pending. Keep `ERP_REGISTRATION_ENABLED=false` until launch acceptance. `ERP_OPERATING_WRITES_ENABLED` remains independent and does not bypass the commercial gate. See the [integrated local acceptance checkpoint](docs/reviews/2026-10-24-office-erp-commercial-management/integrated-acceptance.md) for the bounded browser, migration, regression and pending-gate evidence.

If an office subscription or resale right later becomes inactive, the commercial admission boundary continues to permit scoped reads and bounded maintenance of already accepted contracts and their financial history (activation/renewal/amendment/suspension/resumption/cancellation, invoice issue/correction, receipt/reversal, policy maintenance, report reads, invitation revocation). This does not permit a new sale: contract creation/sending, invitation issuance, subscription adoption, licence additions, module expansion, and offer creation/publication still require current office commercial rights and capacity. Account membership, selected-office assertion, active office status, Company ownership boundaries and action-specific checks continue to apply. It is not a general inactive-office tenant-write bypass.

Recurring commercial invoices are dispatched hourly by Laravel's scheduler using `office-erp:generate-invoices {--batch=50}` (scheduled as `office-erp:generate-invoices --batch=50` with `withoutOverlapping`). The command accepts batch sizes 1–100 and dispatches work for effective contracts with an initialized billing cursor. This is internal invoice generation; it does not collect payment or call a payment provider.

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
