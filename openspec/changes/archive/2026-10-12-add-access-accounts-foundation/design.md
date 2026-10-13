> Status: planning reconciled with the approved Nuxt frontend and Laravel backend architecture (D006). Product implementation and detailed page acceptance remain tracked in `tasks.md`; this status does not assert completed authentication or Account isolation.

## Context

See `proposal.md` for motivation and scope. The accepted decisions are recorded in `docs/architecture/product-decisions.md`: the first release covers access/Accounts, Clients/A1, and NF-e/CT-e capture; application access, authorization, and persistence belong to Laravel in `backend/`, with Nuxt in `frontend/` and a separately planned `services/fiscal/`. D006 supersedes D005.

This change covers only the access/Accounts foundation. The user approved this architectural revision; detailed page acceptance remains incremental. The current project has Nuxt 4, Nuxt UI, Laravel 13, baseline Laravel migrations, and sample Nuxt API handlers. Compose already provides frontend, backend, setup, queue, PostgreSQL 18, and Redis services with bind mounts. Redis is configured for cache, sessions, and queues. The private Nuxt proxy forwards `/api/v1/*` to Laravel and `/api/v1/health` verifies the connection. Product authentication, Account models, isolation, and access pages remain unimplemented. Existing main OpenSpec capability files are absent.

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

Evidence from existing tests was read, not executed: `backend/tests/Feature/Tenancy/AuthTest.php`, `backend/tests/Feature/Admin/FirstAccessTest.php`, `backend/tests/Feature/Tenancy/SupportAccessTest.php`, `frontend/tests/authMiddleware.test.ts`, and `frontend/tests/apiPlugin.test.ts`. Those tests are references, not proof that the new frontend/backend contracts work.

## Goals / Non-Goals

**Goals:**

- Make access and tenant boundaries explicit before introducing confidential client and certificate data.
- Preserve recognizable legacy user flows while exposing every proposed difference for review.
- Define all access pages and API contracts in a form testable across Nuxt, Laravel, PostgreSQL, and Redis.

**Non-Goals:**

- No wholesale legacy code port, legacy database migration, shared credentials, or legacy password/hash import.
- No additional-member management, password recovery, email delivery, support/impersonation, commercial administration, client CRUD, or fiscal connector implementation.
- No production deployment or remote environment modification during this planning workflow.

## Decisions

### 1. Laravel owns the application boundary; Nuxt owns presentation

Use Laravel controllers, Form Requests, application actions/services, Eloquent models, policies, and API Resources under `backend/`. Laravel performs authoritative validation and authorization. Nuxt uses its existing component system for pages and public TypeScript contracts under `frontend/shared/types/`; client validation improves feedback but cannot authorize an operation.

Browser and SSR calls use relative `/api/v1/*` paths through `frontend/server/api/v1/[...path].ts`. `NUXT_BACKEND_URL` stays in private runtime configuration: `http://backend:8000` in Compose, `http://127.0.0.1:8000` for host commands. The fixed upstream forwards methods, query, body, Accept, authorization, cookies, and response status. Manual redirects and a ten-second upstream timeout are already configured. Existing unversioned Nuxt handlers remain sample data until product pages replace their consumers.

Use the Laravel session guard with a stateful cookie/session/CSRF middleware stack on the versioned access routes; registering a route in `routes/api.php` alone does not enable sessions. Keep JSON responses, including authentication failures, rather than redirects to a backend login page. This same-origin browser boundary does not require personal API tokens or an additional authentication package. Sanctum can be evaluated in a separate change if external token clients become necessary. The previous custom Nuxt authentication and persistence design is superseded.

Proposed implementation layout:

```text
backend/
  app/Http/Controllers/Api/V1/   # Access, Account and platform endpoints
  app/Http/Requests/            # Authoritative input validation
  app/Http/Resources/           # Safe response projections
  app/Http/Middleware/         # Session lifetime, origin and Account guards
  app/Models/                  # Users, Accounts, memberships, activations
  app/Policies/                # Membership roles and platform authority
  app/Actions/                 # Transactional bootstrap and lifecycle actions
  database/migrations/         # Laravel schema evolution
  routes/api.php               # Versioned routes and explicit middleware
  tests/                       # Contracts, isolation and concurrency
frontend/
  app/pages/                   # Reviewed access and Account pages
  app/layouts/auth.vue
  app/middleware/              # Navigation guards, not authorization
  app/composables/useAuth.ts
  server/api/v1/[...path].ts    # Existing fixed-origin Laravel proxy
  shared/types/access.ts       # Public response types only
  tests/                       # UI and SSR integration coverage
services/fiscal/               # Separately planned; untouched here
```

SSR identity uses only the current incoming request's cookies; never retain users or Accounts in module-global state. Initial protected navigation checks identity without first creating an anonymous session that could replace the browser's session. Any SSR flow that intentionally obtains or rotates a session must propagate all upstream `Set-Cookie` headers to the outer response; an internal fetch alone does not do that automatically. Prefer client-side authentication mutations and use request-aware `useFetch`/`useRequestFetch` for SSR reads. No session or activation credentials enter public runtime configuration or localStorage.

### 2. PostgreSQL through Laravel migrations and transactions

Use Laravel migrations and Eloquent/query-builder operations on PostgreSQL. Transactions, advisory locks, and row locks use the same Laravel database connection; never split a single transaction across separate connections. PostgreSQL advisory locks may use bound SQL expressions through that connection. Reuse baseline users and Laravel migration history rather than introducing Node `pg`, a SQL migration runner, or duplicate migration ledgers.

Proposed persisted model:

| Store | Relevant fields and constraints |
| --- | --- |
| PostgreSQL `users` | Existing identity table extended with normalized unique email and global Super Admin flag |
| PostgreSQL `accounts` | ID, name, active/suspended status, validated team size nullable for platform-created Accounts, timestamps |
| PostgreSQL `account_memberships` | Account FK, user FK, role enum, timestamps, unique Account/user pair |
| PostgreSQL `owner_activations` | Account FK, normalized email, owner name, token hash, expiry, consumed/revoked timestamps; one current activation per Account |
| Redis Laravel sessions | Framework session data plus selected Account and explicit anonymous/authenticated absolute deadlines |
| Redis revocation markers | Digests of invalidated session IDs with TTL covering their remaining validity |
| Redis rate limits and session locks | Shared endpoint/IP/email budgets and bounded session serialization |

Baseline Laravel database `sessions` and queue tables may remain present without being used by the configured Redis drivers. There is no separate PostgreSQL `access_sessions` or `access_rate_limits` table in this design. Use distinct Redis prefixes/connections for sessions, cache/limits/locks, and queues so cache cleanup cannot flush sessions or jobs.

Activation email reservation uses a transaction-level lock derived from normalized email. Provisioning and reissue reject an existing user or live pending activation for another Account; revoke/expire obsolete reservations within the transaction. User creation still enforces the unique email constraint, including a conflict appearing between issuance and activation. Add race tests against independent PostgreSQL connections.

Development already uses PostgreSQL and durable Docker volumes. Keep the existing lightweight SQLite/array tests, but add a dedicated PostgreSQL test database and isolated Redis namespace for transaction, session, and concurrency suites; those tests cannot reuse development data or infer locking guarantees from SQLite. Compose environments remain explicit per service. Setup migrations and dependency installation occur in the setup service, not during HTTP requests.

### 3. Laravel-managed sessions, hashing, CSRF, and limits

Use the native session guard and Redis session driver. Successful bootstrap/login/activation destroys the old session ID, establishes a new authenticated session, regenerates CSRF, and records an immutable authenticated deadline no later than 12 hours after authentication. Explicit deadline middleware enforces this absolute limit; Laravel's sliding idle timeout alone is insufficient. Anonymous CSRF sessions have a 30-minute absolute deadline. Configure storage TTLs so neither anonymous nor authenticated sessions outlive their permitted deadlines.

Logout calls the Laravel guard, invalidates server session data, regenerates CSRF, and expires the authenticated cookie. Keep a Redis revocation marker for destroyed authenticated IDs until their old validity ends, checked on every authenticated request, so concurrent stale saves cannot revive a logged-out session. Mark superseded authenticated IDs on reauthentication too. Do not extend an authenticated deadline during Account switching. A Redis failure fails closed with a retryable service error; it cannot authenticate from client state. Logout failure stays visible until revocation is confirmed.

Use HttpOnly, SameSite=Lax, Path=/, no Domain, and Secure over HTTPS. Use `__Host-opmoni-session` over HTTPS and a distinct local-development name where Secure cannot be used. Cookie handling uses Laravel encryption and session middleware. The earlier requirement to store only a hash of a custom session credential is replaced by the framework session mechanism; application code never exposes or logs session IDs. Rotation, old-ID replay, expiry, and concurrent logout must be tested through HTTP with real Redis.

`GET /api/v1/csrf` returns the current Laravel session token as `{csrf_token}` with no-store. Mutations send `X-CSRF-Token`, and explicit middleware validates the public frontend Origin and requires a matching token before any mutation. Preserve the existing 403 contract by mapping CSRF failures to the common error envelope rather than leaking a framework 419 page. Laravel 13 can accept secure same-origin requests without checking a token by default; add an explicit required-token check using the framework session token and constant-time comparison, so that shortcut cannot weaken the agreed contract. Do not exempt login, registration, activation, logout, switching, or platform mutations. Refresh the client token after authentication and switching.

Validate against the public frontend origin, not the private Docker upstream. Configure a trusted Nuxt-to-Laravel ingress that discards spoofed forwarded headers and carries the actual client IP/public request metadata. Trust only that ingress; do not trust arbitrary internet `X-Forwarded-*` values or use the Nuxt container's IP as every user's rate-limit key. The current proxy transport does not yet implement this trust boundary, authentication, or CSRF policy. No blanket access-API CORS rule is needed for same-origin browser calls.

Use Laravel `Hash` with Argon2id, avoiding a custom Node hash format and avoiding bcrypt's truncation risk for the existing 128-character password contract. Verify Argon2id support in the selected PHP runtime and benchmark cost before apply completes. Preserve passwords exactly, use dummy verification for unknown emails, and enforce input length before hashing. Shared Laravel rate limiting uses Redis and the thresholds in `specs/auth/spec.md`, including both IP and normalized-email login budgets; hash email bucket keys and verify atomic enforcement across processes. Storage TTLs remove expired transient entries without bespoke SQL cleanup tables.

### 4. Account context is request-scoped and membership-backed

Load the session, selected Account, current status, and membership into the individual Laravel request's scoped context. Database connections can be shared, but user/Account state cannot. Eloquent tenant scopes and query/action boundaries require explicit authorized Account context and deny queries when it is absent. A valid member cannot override context through an operational request body. Platform actions and queries are separate and require explicit global Super Admin authorization; they never reuse a permissive tenant bypass.

Store selected Account on the session instead of `users.current_account_id`. This avoids switching unrelated sessions. Tabs sharing the same cookie still share selection: they revalidate `/api/v1/me` on focus and clear/reload if the server Account changed. All future tenant requests carry `X-Account-Id` representing the displayed Account as an assertion, not an authorization source; mismatch returns 409 `account_context_changed` before any tenant action, preventing a stale tab from writing in the newly selected Account.

After every successful password login, select the default active member Account: the earliest membership by membership ID among active Accounts. This applies to both one and multiple memberships; with none leave it null for unavailable access. New login returns to this default rather than restoring a manual switch. Bootstrap and owner activation still select their newly created Account. Reloads and SSR reads within a valid session preserve its selection and recheck membership/status rather than resetting the login flow.

Use `/api/v1/account/switch` for optional AccountMenu switching. It requires identity, CSRF, and membership/status authorization, but not an existing tenant selection or displayed-Account assertion. Identity reads repair legacy sessions with null selection by selecting the active default without extending the deadline. Missing authorized context still fails closed; an invalid or suspended selected membership never grants tenant access.

Serialize session-backed access and tenant routes with Laravel session blocking on Redis, including reads that may save session data, switching, authentication, and logout. Acquire the session lock before loading mutable session state. Use bounded lock waits and ensure lock leases cover the operation; if ownership cannot be maintained, fail without performing the protected mutation. Do not retry mutations after the proxy's timeout without reconciling their committed state.

Inside the session lock, acquire applicable PostgreSQL installation/email advisory locks, then Account rows in ascending ID order, then activation or affected membership rows. No code holding PostgreSQL locks subsequently acquires another session lock. Platform suspension uses its caller's session lock and the same Account-row order; it does not acquire member session locks. Recheck authorization under the relevant database locks before writes. Operations authorized before suspension under that lock can finish; after suspension commits, new operations are denied. Reads use one consistent snapshot of Account status and membership. Redis session updates occur only after the database transaction commits; if they fail, identity data remains recoverable by login and context changes are not reported as successful. Session selection remains unchanged until all switch checks succeed.

Super Admin authority is restricted to platform administration in this change. Support access with audit requires a later independently reviewed capability; there is no hidden superuser bypass. Roles exist now, but membership addition/edit/removal remains a later page decision, including proper invitations and last-admin protection.

### 5. Keep the bootstrap and owner-activation flows distinct

Bootstrap acquires one installation advisory lock and rechecks users/Accounts inside the transaction before creating anything. Hashing can happen before acquiring the lock, but only the lock holder that observes an empty installation commits initialization. Session issuance follows the committed identity transaction; transient session issuance failure is recoverable through login and does not rerun bootstrap.

Platform creation always follows the legacy's first-access variant. It creates an Account and hashed activation; the new owner sets their own password. Direct password assignment and Accounts without a nominated owner are omitted. Activation uses an Account lock, verifies the live hashed token/email/expiry, rejects existing users, and commits user+membership+consumption together. A used or replaced link cannot activate again. Raw links are returned only on issuance, with `no-store`, and are not stored in user-facing lists or ordinary logs. Reissue locks the Account and revokes the previous activation atomically.

This preserves the legacy's email conflict behavior: an existing identity cannot become a new owner through this path. Adding memberships to existing identities belongs to the next membership-management proposal rather than silently changing credentials here.

### 6. Explicit API contracts

All errors use `{code,message,errors?}` with Portuguese user messages and an optional field-error map. All access and Account responses use no-store; untrusted `Origin`/CSRF is 403, authentication is 401, role/Account denial is 403, validation is 422, resource absence is 404, context/state conflict is 409, and throttling is 429 with Retry-After. Authorization occurs before revealing platform records. Status changes accept only active/suspended and never delete Accounts.

| Endpoint | Input | Success |
| --- | --- | --- |
| `GET /api/v1/csrf` | Current cookie if present | 200 `{csrf_token}` |
| `GET /api/v1/registration-status` | None | 200 `{registration_available}` |
| `POST /api/v1/register` | name, email, password, company, size | 201 current-user representation |
| `POST /api/v1/login` | email, password | 200 current-user representation |
| `POST /api/v1/logout` | Session + CSRF | 204 |
| `GET /api/v1/me` | Session | 200 current-user representation |
| `POST /api/v1/account/switch` | account_id | 200 current-user representation |
| `GET /api/v1/first-access/status` | token, email query | 200 `{valid,...safe_names}` |
| `POST /api/v1/first-access` | token, email, password, password_confirmation | 201 current-user representation |
| `GET /api/v1/admin/accounts` | q, status, page | 200 `{data,meta:{page,per_page,total}}` |
| `POST /api/v1/admin/accounts` | name, owner_name, login_email | 201 `{data,first_access:{url,expires_at}}` |
| `PATCH /api/v1/admin/accounts/:id` | name and/or status | 200 `{data}` |
| `POST /api/v1/admin/accounts/:id/first-access` | owner_name, login_email | 200 `{first_access:{url,expires_at}}` |

Name/company/owner values are trimmed, 2–255 characters; normalized email is at most 254 characters. Login accepts an email and 1–128-character candidate password so incorrect credentials reach the generic error; registration/activation require 8–128 characters and matching confirmation on activation. Team-size labels map to `solo`, `2-10`, `11-50`, `51-200`, `200+`. Search is literal text, capped at 255; page is a positive integer. Use safe integer IDs and whitelist accepted keys.

### 7. Page review remains incremental

The `access-pages` spec is the page inventory, not a claim that every screen has been accepted. Login and initial office-selection decisions below were confirmed by the user on 2026-10-12. The user subsequently authorized direct implementation of the current remaining page contracts on 2026-10-12 (D007), superseding the incremental review gate. Onboarding must be unavailable if any user or Account exists; both stores must be empty. Keep this inventory and task checkboxes synchronized with accepted changes.

Confirmed login and selection decisions:

- Fixed 12-hour authenticated lifetime with no remember-me; selection and switching do not extend it.
- Unknown email and incorrect password use the same "Email ou senha inválidos" message. Rate limiting and temporary service failure have distinct feedback; the latter retains the form/email and permits retry.
- D008 supersedes the earlier profile-card flow: valid credentials enter the default active office directly, even with multiple memberships. The default is the first active membership by membership ID.
- Optional switching uses AccountMenu within authenticated navigation after entry; rename the existing TeamsMenu component and update all current consumers; obsolete selection URLs redirect to the normal access destination.
- A new login returns to the default; reload preserves a manual session switch.
- Suspended selected Accounts show unavailable access with AccountMenu when an active alternative exists. Failed switching remains visible and preserves the prior selection.
- Automatic password recovery remains deferred. Assistance is the platform administrator's responsibility; the user subsequently clarified that no contact-channel definition is required for this implementation. No reset endpoint, password-assignment workflow, or unsupported recovery control is introduced by this decision.

Reuse the existing Nuxt UI component system and Portuguese labels. Do not import the legacy's entire redesign, revenue widgets, billing contacts, Work navigation, SERPRO setup, or sample inbox. The authenticated `/` is the selected Account's workspace shell, entered directly after login. Remove the dedicated identity/profile and Account-summary cards from the initial page. AccountMenu in the authenticated navigation shows the current Account and lists only the user's memberships, with suspended Accounts disabled; this is the sole routine Account-selection control. Keep the current membership role and logout available in the shell without requiring a profile page. Restore the original Nuxt UI dashboard layout with its collapsible/mobile sidebar, dashboard panels, charts, indicators, and existing demo navigation. AccountMenu lives at the top of the sidebar and the authenticated user menu with real logout lives at its footer; platform Account administration is visible only to Super Admin. The user explicitly approved demonstration data after rejecting the empty header-only workspace. Label the restored dashboard as demonstration data; sample widgets and sample routes do not represent implemented tenant business capabilities. Allow only the existing dashboard demonstration routes through authenticated frontend navigation, while keeping active membership requirements and the real platform authorization boundary. Clients and Fiscal product APIs remain separate changes. Renaming the component does not alter API contracts, default ordering, session lifetime, cache invalidation, failure handling, or unavailable-access behavior.

Owner activation URLs include bearer tokens in a query for legacy familiarity. Use no-referrer on the page, no third-party resources, no query capture in observability/access logs for this route, and no cached SSR response. Do not persist raw URLs in browser app state after leaving the page. Listing requests never reconstruct old links.

## Risks / Trade-offs

- [Stateless API routes do not enable Laravel session security] → Attach and test the explicit stateful middleware stack, absolute lifetime, revocation, and required-token/origin checks through the Nuxt proxy.
- [PostgreSQL and Redis have no shared transaction] → Commit identity changes before issuing sessions, use login as recovery, and never acknowledge a failed session/context update as success.
- [Proxy metadata can hide or spoof client identity] → Restrict trusted ingress, sanitize forwarded headers, and test distinct client IP budgets and hostile Origin requests.
- [Redis lock leases or proxy timeouts can expire mid-operation] → Bound operation time, preserve lock ownership, and test timeout outcomes without automatic mutation retries.
- [Platform-only owner provisioning cannot attach an existing identity] → Preserve the legacy's rejection for now; design real additional-member invitations in a separate change.
- [Support workflows are deferred] → Super Admin can administer Account lifecycle but cannot operate foreign client/certificate data.
- [Tabs share a session] → Revalidate on focus, clear stale responses, and enforce the Account assertion before future tenant actions.
- [Owner links can leak through query logging] → Redact the route's sensitive query fields, use no-referrer/no-store, and exclude third-party page requests.
- [Password hashing can consume shared server resources] → Use Laravel Argon2id, shared rate limits, bounded PHP workers, and benchmark the selected cost before release.
- [Source review differs from runtime verification] → Implement new contract tests; do not treat legacy test presence as a passing result.

## Migration Plan

This is a new implementation, not a migration of the legacy installation. Preserve all legacy files and databases. On apply, extend the existing Laravel schema, configure the stateful middleware, and add dedicated PostgreSQL/Redis integration-test configuration. Reuse the existing Compose setup migration step; do not create a Node persistence stack. Verify installed framework/runtime versions and any genuinely required dependencies before changing lockfiles. Existing template UI remains only until the reviewed authenticated shell replaces it.

Use a dedicated development database so rollback can stop the new application or restore the previous code without affecting legacy data. Once real users/Accounts are stored, do not drop tables as a routine rollback; preserve the volume and roll back application code with a reviewed compatible schema. Production deployment requires its own explicit request and verification.

## Technical References

- [Laravel 13 sessions](https://laravel.com/framework/docs/13.x/session): Redis sessions, regeneration, and session blocking.
- [Laravel 13 authentication](https://laravel.com/framework/docs/13.x/authentication): session guard and logout lifecycle; verify against installed framework during apply.
- [Laravel 13 CSRF](https://laravel.com/framework/docs/13.x/csrf): session tokens, headers, and origin shortcut; required-token enforcement remains explicit here.
- [Laravel 13 hashing](https://laravel.com/framework/docs/13.x/hashing): supported Argon2id driver and cost configuration.
- [Laravel 13 rate limiting](https://laravel.com/framework/docs/13.x/rate-limiting): shared cache-backed limits.
- [Nuxt runtime configuration](https://nuxt.com/docs/4.x/guide/going-further/runtime-config): private upstream configuration.
- [Nuxt SSR data fetching](https://nuxt.com/docs/4.x/getting-started/data-fetching): request-aware cookie forwarding; session-setting responses still require outer response propagation.
