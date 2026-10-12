> Status: superseded architecture. This pending proposal targets the earlier Nuxt-only backend decision (D005). The user subsequently chose a Laravel 13 backend in `backend/` (D006). Preserve this proposal as reference; do not apply its implementation tasks until the proposal, design, specs, and tasks have been revised and reviewed for D006. See `docs/architecture/product-decisions.md`.

## Context

See `proposal.md` for motivation and scope. The accepted decisions are recorded in `docs/architecture/product-decisions.md`: the first release covers access/Accounts, Clients/A1, and NF-e/CT-e capture; application access and data belong to Nuxt `server/`, with PHP confined to `services/fiscal/`.

This change covers only the access/Accounts foundation. It is a proposal awaiting review, not an accepted implementation design. The current project has Nuxt 4, Nuxt UI, Zod, and sample API handlers, but no authentication, database, migrations, or access pages. Existing main OpenSpec capability files are absent.

### Verified legacy reference

The source review on 2026-10-12 inspected `/home/obsidian/dev/legacy`; it did not run the legacy application, tests, containers, migrations, or external integrations, and did not read private credentials.

| Legacy behavior found in code | Evidence relative to the legacy root | Treatment in this proposal |
| --- | --- | --- |
| One-time registration creates the initial Super Admin, Account and admin membership; PostgreSQL advisory lock serializes bootstrap | `backend/app/Http/Controllers/AuthController.php:16`, `:38`, `:143` | Preserve product behavior and require PostgreSQL concurrency coverage |
| Registration accepts team size but does not persist it | `backend/app/Http/Controllers/AuthController.php:38` | Persist a validated team-size enum |
| Email is globally unique; membership has a per-Account role and a unique user/Account pair | `backend/database/migrations/0001_01_01_000000_create_users_table.php:17`, `2026_01_01_000003_create_account_user_table.php:14` | Preserve identity and membership model |
| Current Account is stored on the user, so switching affects other sessions | `backend/app/Http/Controllers/Tenant/AccountSwitchController.php:39` | Propose session-local selection instead |
| Super Admin can enter foreign Accounts and acts as admin under support | `backend/app/Policies/Concerns/HasTenantRole.php:13`, `frontend/app/composables/useAuth.ts:49` | Defer support; no implicit foreign-Account access |
| New-owner first access uses a seven-day link, creates the user only on acceptance, and returns a copyable URL without sending email | `backend/app/Services/AdminAccountProvisioner.php:138`, `:227` | Preserve this provisioning path; omit direct platform password assignment |
| Existing owner email is rejected, without changing the existing identity | `backend/app/Services/AdminAccountProvisioner.php:227`, `backend/app/Http/Requests/Admin/StoreAccountRequest.php:30` | Preserve conflict behavior for this first change |
| Account creation also provisions a subscription and default departments | `backend/app/Observers/AccountObserver.php:18` | Exclude those side effects from the approved MVP |
| Tenant writes require an active subscription | `backend/app/Http/Middleware/ResolveTenant.php:40` | No subscription guard in the new access foundation |
| Missing tenant context disables the global Account filter | `backend/app/Concerns/BelongsToAccount.php:21` | Deny operations without explicit request context |
| Auth and switch responses load raw Account data; the admin Resource applies a safer projection | `backend/app/Http/Controllers/AuthController.php:138`, `backend/app/Http/Controllers/Tenant/AccountSwitchController.php:43`, `backend/app/Http/Resources/AccountResource.php:17` | Use explicit safe projections consistently |
| The member “invite” asks the admin to set a password and creates the identity immediately; no last-admin guard exists | `backend/app/Http/Controllers/Tenant/AccountMemberController.php:39`, `:90`, `frontend/app/pages/equipe/index.vue:87` | Record as legacy context, but defer member-management design |
| Switching reloads `/` to discard Nuxt state | `frontend/app/components/TeamsMenu.vue:40` | Preserve full reload and invalidate previous tenant requests |
| Login has a remember checkbox that is not submitted | `frontend/app/pages/login.vue:40`, `frontend/app/composables/useAuth.ts:92` | Omit the ineffective control |
| Onboarding is bootstrap, and its terms checkbox has no server-side contract | `frontend/app/pages/onboarding.vue:14`, `:98` | Keep bootstrap; do not claim unimplemented terms acceptance |
| First access treats service failure as an invalid link | `frontend/app/pages/primeiro-acesso.vue:31` | Separate retryable service failure from invalid/expired links |

Evidence from existing tests was read, not executed: `backend/tests/Feature/Tenancy/AuthTest.php`, `backend/tests/Feature/Admin/FirstAccessTest.php`, `backend/tests/Feature/Tenancy/SupportAccessTest.php`, `frontend/tests/authMiddleware.test.ts`, and `frontend/tests/apiPlugin.test.ts`. Those tests are references, not proof that the new Nuxt contracts work.

## Goals / Non-Goals

**Goals:**

- Make access and tenant boundaries explicit before introducing confidential client and certificate data.
- Preserve recognizable legacy user flows while exposing every proposed difference for review.
- Define all access pages and API contracts in a form testable against Nuxt and PostgreSQL.

**Non-Goals:**

- No Laravel port, legacy database migration, shared credentials, or legacy password/hash import.
- No additional-member management, password recovery, email delivery, support/impersonation, commercial administration, client CRUD, or fiscal connector implementation.
- No production deployment or remote environment modification during this planning workflow.

## Decisions

### 1. Nuxt owns the application boundary

Use server-only handlers, services, and repositories under `server/`, with shared public contracts under `shared/types/`. Zod validates inputs; H3 handles request/cookie primitives. No business authorization relies on Vue middleware alone. The client uses relative same-origin `/api` requests, and SSR forwards only the current request's cookie to the local API without retaining identity in a module-global variable.

The Nuxt application now lives in `frontend/`. Nuxt paths in this proposal are relative to that directory; `services/fiscal/` remains relative to the repository root.

Proposed implementation layout (Nuxt paths relative to `frontend/`):

```text
app/
  layouts/auth.vue
  pages/login.vue
  pages/onboarding.vue
  pages/primeiro-acesso.vue
  pages/acesso-indisponivel.vue
  pages/admin/contas.vue
  middleware/auth.ts
  middleware/super-admin.ts
  composables/useAuth.ts
server/
  api/                         # Thin access and Account route handlers
  services/auth/               # Bootstrap, sessions, first access
  services/accounts/           # Platform provisioning and switching
  repositories/                # Explicit queries and safe projections
  utils/                       # Database, request guards, validation/errors
  database/migrations/         # Versioned SQL, not executed on request
shared/types/access.ts
scripts/                       # Explicit migration/test entry points
tests/                         # Unit, API, PostgreSQL race and browser checks
services/fiscal/                # Separate future PHP service; untouched here
```

Laravel/Sanctum was considered as the closest code reuse, but the user explicitly chose Nuxt application persistence. A generic all-in-one authentication provider was also considered; the proposal uses a small internal session boundary so bootstrap, owner activation, and membership checks remain explicit. Changing that implementation later must preserve the behavior specs.

### 2. PostgreSQL with explicit transactions and migrations

Propose `pg` and parameterized SQL repositories with a server-only pool. Use one checked-out connection for every transaction, including advisory locks, provisioning, and activation; never interleave a transaction through arbitrary pooled connections. SQL migrations execute through an explicit command and maintain a migrations ledger. Record dependencies with `pnpm` and verify Node/Nuxt compatibility during apply; do not add dependencies during propose.

An ORM was considered but is unnecessary for the small initial model. SQLite or in-memory storage would not validate the shared locking, persistence, or multi-instance rate-limit behavior. Development and tests therefore use PostgreSQL, with separate databases and a durable local Docker volume. Compose environment values remain explicit per service.

Proposed tables:

| Table | Relevant fields and constraints |
| --- | --- |
| `users` | ID, name, normalized unique email, password hash, Super Admin flag, timestamps |
| `accounts` | ID, name, active/suspended status, validated team size nullable for platform-created Accounts, timestamps |
| `account_memberships` | Account FK, user FK, role enum, timestamps, unique Account/user pair |
| `access_sessions` | Hash of random session credential, nullable user FK, nullable selected Account FK, CSRF token, absolute expiry, revocation time |
| `owner_activations` | Account FK, normalized email, owner name, token hash, expiry, consumed/revoked timestamps; one current activation per Account |
| `access_rate_limits` | Hashed IP/email bucket key, endpoint/window identifier, count, expiry; unique bucket/window |
| `schema_migrations` | Applied migration identifier and timestamp |

Activation email reservation uses a transaction-level lock derived from normalized email. Provisioning and reissue reject an existing user or live pending activation for another Account; revoke/expire obsolete reservations within the transaction. User creation still enforces the unique email constraint, including a conflict appearing between issuance and activation.

### 3. Opaque revocable sessions and explicit CSRF

Use a random 32-byte session credential in a host-only cookie, store only its SHA-256 hash, and enforce a fixed 12-hour authenticated lifetime. Session rotation after bootstrap/login/activation revokes the old row, issues a new credential and CSRF token, and prevents session fixation. A transient anonymous session lasts 30 minutes and exists only to bind pre-authentication CSRF.

`GET /api/csrf` creates or loads that session and returns `{csrf_token}` with no-store. Mutations supply `X-CSRF-Token`; compare with the session value using constant-time comparison, and validate `Origin` against the configured application origin. On every successful authentication or switch the client refreshes its CSRF token. A reverse proxy must supply only trusted forwarded-client IP metadata for rate limiting. Apply limits through atomic database buckets and cleanup expired anonymous sessions/buckets periodically.

Use HttpOnly, SameSite=Lax, Path=/, no Domain, and Secure in HTTPS environments. Use the `__Host-opmoni-session` name over HTTPS and a separate local-development name where Secure cannot be used. Remove the template's blanket public CORS rule for access APIs. Do not put session or activation credentials in localStorage or public runtime configuration.

Propose asynchronous Node `scrypt` with per-password random salt and a versioned hash format. Initial parameters are N=32768, r=8, p=3, 64-byte output and 64 MiB maxmem; apply must verify the selected runtime and benchmark CPU/memory with bounded hashing concurrency. Use a dummy hash verification for unknown login emails, never normalize passwords, and never accept passwords longer than the API limit. Node documentation is the implementation reference, not a substitute for session and CSRF tests.

Client/API logout failure remains visible and retryable. It must not leave the user believing a still-active server session was revoked.

### 4. Account context is request-scoped and membership-backed

Load the session, selected Account, current status, and membership into the individual request's context. A pool can be shared, but user/Account state cannot. Tenant repositories require an explicit Account parameter and cannot execute unscoped tenant queries. A valid member cannot override context through an operational request body. Platform repositories are separate and require the global Super Admin guard.

Store selected Account on the session instead of `users.current_account_id`. This avoids switching unrelated sessions. Tabs sharing the same cookie still share selection: they revalidate `/api/me` on focus and clear/reload if the server Account changed. All future tenant requests carry `X-Account-Id` representing the displayed Account as an assertion, not an authorization source; mismatch returns 409 `account_context_changed` before any tenant action, preventing a stale tab from writing in the newly selected Account.

Account switch, membership-sensitive tenant writes, and platform suspension coordinate through transaction locks. Use applicable installation/email advisory locks first, then Account rows in ascending ID order, then the session row, then the activation or affected membership row. Recheck authorization after acquiring the relevant locks. If an operation is authorized before suspension under that lock it can finish; after suspension commits, new operations are denied. List/read operations take one consistent snapshot. This prevents policy checks from becoming stale before mutation and avoids opposite lock ordering during concurrent switches.

Super Admin authority is restricted to platform administration in this change. Support access with audit requires a later independently reviewed capability; there is no hidden superuser bypass. Roles exist now, but membership addition/edit/removal remains a later page decision, including proper invitations and last-admin protection.

### 5. Keep the bootstrap and owner-activation flows distinct

Bootstrap acquires one installation advisory lock and rechecks users/Accounts inside the transaction before creating anything. Hashing can happen before acquiring the lock, but only the lock holder that observes an empty installation commits initialization. Session issuance follows the committed identity transaction; transient session issuance failure is recoverable through login and does not rerun bootstrap.

Platform creation always follows the legacy's first-access variant. It creates an Account and hashed activation; the new owner sets their own password. Direct password assignment and Accounts without a nominated owner are omitted. Activation uses an Account lock, verifies the live hashed token/email/expiry, rejects existing users, and commits user+membership+consumption together. A used or replaced link cannot activate again. Raw links are returned only on issuance, with `no-store`, and are not stored in user-facing lists or ordinary logs. Reissue locks the Account and revokes the previous activation atomically.

This preserves the legacy's email conflict behavior: an existing identity cannot become a new owner through this path. Adding memberships to existing identities belongs to the next membership-management proposal rather than silently changing credentials here.

### 6. Explicit API contracts

All errors use `{code,message,errors?}` with Portuguese user messages and an optional field-error map. All access and Account responses use no-store; untrusted `Origin`/CSRF is 403, authentication is 401, role/Account denial is 403, validation is 422, resource absence is 404, context/state conflict is 409, and throttling is 429 with Retry-After. Authorization occurs before revealing platform records. Status changes accept only active/suspended and never delete Accounts.

| Endpoint | Input | Success |
| --- | --- | --- |
| `GET /api/csrf` | Current cookie if present | 200 `{csrf_token}` |
| `GET /api/registration-status` | None | 200 `{registration_available}` |
| `POST /api/register` | name, email, password, company, size | 201 current-user representation |
| `POST /api/login` | email, password | 200 current-user representation |
| `POST /api/logout` | Session + CSRF | 204 |
| `GET /api/me` | Session | 200 current-user representation |
| `POST /api/account/switch` | account_id | 200 current-user representation |
| `GET /api/first-access/status` | token, email query | 200 `{valid,...safe_names}` |
| `POST /api/first-access` | token, email, password, password_confirmation | 201 current-user representation |
| `GET /api/admin/accounts` | q, status, page | 200 `{data,meta:{page,per_page,total}}` |
| `POST /api/admin/accounts` | name, owner_name, login_email | 201 `{data,first_access:{url,expires_at}}` |
| `PATCH /api/admin/accounts/:id` | name and/or status | 200 `{data}` |
| `POST /api/admin/accounts/:id/first-access` | owner_name, login_email | 200 `{first_access:{url,expires_at}}` |

Name/company/owner values are trimmed, 2–255 characters; normalized email is at most 254 characters. Login accepts an email and 1–128-character candidate password so incorrect credentials reach the generic error; registration/activation require 8–128 characters and matching confirmation on activation. Team-size labels map to `solo`, `2-10`, `11-50`, `51-200`, `200+`. Search is literal text, capped at 255; page is a positive integer. Use safe integer IDs and whitelist accepted keys.

### 7. Page review remains incremental

The `access-pages` spec is the first page inventory, not a claim that every screen has been accepted. Present each page for user review before apply: login, onboarding, first access, unavailable Account, switcher/authenticated shell, and minimal platform Accounts. Keep this inventory and task checkboxes synchronized with accepted changes.

Reuse the existing Nuxt UI component system and Portuguese labels. Do not import the legacy's entire redesign, revenue widgets, billing contacts, Work navigation, SERPRO setup, or sample inbox. The authenticated `/` shows real user/Account information; Clients and Fiscal routes are introduced only by their own changes.

Owner activation URLs include bearer tokens in a query for legacy familiarity. Use no-referrer on the page, no third-party resources, no query capture in observability/access logs for this route, and no cached SSR response. Do not persist raw URLs in browser app state after leaving the page. Listing requests never reconstruct old links.

## Risks / Trade-offs

- [Nuxt replaces mature Sanctum/session defaults] → Make cookie, CSRF, revocation, origin validation, and multi-instance throttling explicit and verify them through API and browser tests.
- [Platform-only owner provisioning cannot attach an existing identity] → Preserve the legacy's rejection for now; design real additional-member invitations in a separate change.
- [Support workflows are deferred] → Super Admin can administer Account lifecycle but cannot operate foreign client/certificate data.
- [Tabs share a session] → Revalidate on focus, clear stale responses, and enforce the Account assertion before future tenant actions.
- [Owner links can leak through query logging] → Redact the route's sensitive query fields, use no-referrer/no-store, and exclude third-party page requests.
- [Password hashing can consume shared server resources] → Use asynchronous hashing, shared rate limits, bounded concurrency, and benchmark the stated parameters before release.
- [Source review differs from runtime verification] → Implement new contract tests; do not treat legacy test presence as a passing result.

## Migration Plan

This is a new implementation, not a migration of the legacy installation. Preserve all legacy files and databases. On apply, introduce dependencies, versioned schema and separate development/test PostgreSQL configuration; run migrations explicitly before starting access routes. Existing template UI remains only until the reviewed authenticated shell replaces it.

Use a dedicated development database so rollback can stop the new application or restore the previous code without affecting legacy data. Once real users/Accounts are stored, do not drop tables as a routine rollback; preserve the volume and roll back application code with a reviewed compatible schema. Production deployment requires its own explicit request and verification.

## Technical References

- [Nuxt server directory](https://nuxt.com/docs/4.x/directory-structure/server): server handlers and server-only organization; verify against installed Nuxt during apply.
- [H3 cookie helpers](https://h3.dev/utils/cookie): cookie read/write/delete primitives.
- [Node crypto](https://nodejs.org/api/crypto.html): asynchronous scrypt, secure randomness, hashing and constant-time comparison.
- [node-postgres transactions](https://node-postgres.com/features/transactions): one connection per transaction.
- [node-postgres parameterized queries](https://node-postgres.com/features/queries): SQL values passed separately from query text.
