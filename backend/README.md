# Opmoni backend

Laravel 13 owns access, Account persistence and authorization. Nuxt in `../frontend/` presents the interface and proxies `/api/v1/*`; there are no frontend build dependencies here.

## Setup

From the repository root, start the configured Compose stack. The setup service installs dependencies and runs Laravel migrations before the backend serves requests. Preserve the existing PostgreSQL and Redis volumes.

For host development:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
```

PostgreSQL and Redis use the published ports in Compose; container services override their hosts to `postgres` and `redis`. Configure the same private `FRONTEND_INGRESS_KEY` in Laravel and Nuxt, and set `FRONTEND_ORIGIN` to the public frontend origin. Access endpoints reject direct requests without trusted ingress metadata. `/up` and `/api/v1/health` remain public health checks.

## Access and sessions

The first registration is available only while both users and Accounts are empty. Bootstrap creates a Super Admin, an active Account and its admin membership. Subsequent offices are provisioned through the Super Admin Account API; their owners choose passwords using single-use seven-day activation links. Issuance responses alone contain raw links. No email delivery, recovery endpoint or existing-user password assignment is provided.

Cookies are encrypted, HttpOnly, host-only, Path=/ and SameSite=Lax. HTTPS uses the Secure `__Host-opmoni-session` cookie; local HTTP uses `opmoni-session`. Anonymous CSRF sessions expire after 30 minutes and authenticated sessions after 12 hours, with absolute storage deadlines. Activity and switching do not extend them. Obtain `/api/v1/csrf` and send `X-CSRF-Token` together with the public Origin on every mutation. Authentication and switching refresh the token.

Redis database 0 holds queues, database 1 holds cache, shared limits, locks and revocation markers, and database 2 holds sessions. Never flush shared session or queue databases to clear application cache. Laravel session blocking uses a 30-second lease and a two-second acquisition wait; ownership is rechecked before protected commits and persistence. PostgreSQL installation/email advisory locks precede Account and activation/membership row locks. Every locking transaction sets a two-second PostgreSQL lock wait and five-second statement timeout before acquiring its first lock. Operation failure returns a retryable service error, without automatic mutation retries.

Passwords use Argon2id with 64 MiB, two iterations and one thread. The local PHP 8.4 runtime averaged approximately 585 ms over five hashes while tests were running. Native Argon memory is additional to PHP's reported heap. Keep the configured PHP worker count and container memory bounded when deploying.

Current Account selection belongs to the session. Tenant handlers use `ResolveAccount`, `CurrentAccount` and `BelongsToAccount` rather than a global administrator bypass. They require `X-Account-Id`, recheck membership/status under database locks and deny queries without an explicit authorized context. A stale displayed Account returns 409 before work; foreign direct resource IDs return 404 through the tenant scope. Related IDs must use a validation rule scoped to `CurrentAccount::fromRequest()->account->id`.

## Tests and formatting

From the repository root:

```bash
docker compose exec -T backend php artisan test --compact
docker compose exec -T backend vendor/bin/phpunit -c phpunit.pgsql.xml
docker compose exec -T backend vendor/bin/pint --format agent
```

The default suite forces SQLite in-memory, array sessions/cache and sync queues in `tests/bootstrap.php`, including process/server environment values so Docker settings cannot override isolation. The PostgreSQL suite requires a separate `opmoni_test` database and forces Redis databases 13/14/15 with an `opmoni-access-test-` prefix. Its bootstrap and process workers assert isolated stores before destructive resets. Never point this suite at the development database. Fixture tenant routes exist only in tests.

## Recovery

If registration or activation commits but session issuance fails, use password login after Redis recovers; do not repeat bootstrap or change the existing identity. After a timeout on provisioning, inspect the platform Account list before reissuing a link. Reissue invalidates the prior unconsumed link and requires an active office with no members. Suspension preserves data and memberships; reactivation restores membership access without extending expired links.

The platform administrator's assistance contact and operating procedure must be established before real-user launch. They do not authorize a password-assignment or automatic recovery implementation.


## Integrated business modules

Clients, Fiscal, Monitoring, Work, Team, business Settings and platform commercial data use the existing `/api/v1` session and membership contract. The `Account` observer initializes an idempotent basic subscription and four departments; additive migrations initialize existing accounts without changing identities. `backend/tests/Scaffold/migration-manifest.json` maps seven superseded legacy access/impersonation suites to current contract coverage; all 112 current business suites are active.

Background operations establish `CurrentTenant::run(accountId)` and restore the previous context. Compose uses `queue:work` with a 360-second fallback, per-job fiscal deadlines of 85 seconds and bulk deadlines up to 300 seconds, below the 420-second reservation. Restart queue and scheduler services after changing jobs or scheduled commands. Development schedules run in homologation; external provider configuration and contract guards remain explicit.
