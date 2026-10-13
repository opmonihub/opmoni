> Status: planning reconciled with the approved Nuxt frontend and Laravel backend architecture (D006). Product implementation and detailed page acceptance remain tracked in `tasks.md`; this status does not assert completed authentication or Account isolation.

## Why

The approved first release needs authenticated accounting offices and isolated Accounts before client A1 certificates or fiscal XML documents can be safely addressed. The repository now has a Nuxt frontend, a Laravel 13 backend, and connected development infrastructure, but application authentication and Account isolation are still missing; the legacy provides reference flows whose subscription dependencies are outside the selected release scope.

## What Changes

- Implement authentication, Account lifecycle, authorization, and application persistence in Laravel under `backend/`.
- Implement access pages and request-aware UI state in Nuxt under `frontend/`, using relative `/api/v1/*` requests through its existing server-only Laravel proxy.
- Preserve the legacy's first-run onboarding, email/password login, logout, global user identity, three membership roles, and Account selection.
- After valid password login, enter the default active member Account directly, including users with multiple memberships. The default is the earliest membership by membership ID among active Accounts. Preserve manual switching across reloads in the session; a new login returns to the default. Show unavailable access when none are active.
- Open the original Nuxt UI dashboard for the selected Account directly after login, without an intermediate profile or Account-summary home page. Keep Account selection and optional switching in AccountMenu in the dashboard sidebar, renaming the existing TeamsMenu component. List only the user's memberships, indicate the current Account, and disable suspended Accounts.
- Add minimal Super Admin Account provisioning, owner first access, suspension, and reactivation so additional offices can join after bootstrap.
- Provide access pages and a minimal authenticated Account workspace shell, without a dedicated initial profile or Account-summary page; document their fields, loading, validation, and failure states.
- Propose request-scoped Account resolution that denies access without a valid Account, revocable Laravel-managed sessions, and transactional one-use owner activation.
- Remove subscription prerequisites from access: commercial plans and subscriptions are deferred by the accepted release scope.
- Keep client registration, certificates, XML capture, member-management screens, support access, Work, SERPRO, and WhatsApp outside this change.

## Capabilities

### New Capabilities

- `auth`: One-time bootstrap, password authentication, revocable sessions, logout, current-user contract, and owner first access.
- `accounts`: Global user identity, membership roles, current Account selection, and minimal platform provisioning/suspension.
- `isolation`: Request-scoped Account authorization, role enforcement, cross-Account access denial, and cache isolation.
- `access-pages`: Login, default office entry, onboarding, first access, Account-unavailable states, Account switcher, and minimal platform Account page.

### Modified Capabilities

None. This repository has no existing main capability specifications; the legacy is a reference, not an OpenSpec root to modify.

## Impact

- Backend implementation affects `backend/routes/api.php`, controllers, request validation, models, authorization, database migrations, and tests. Frontend implementation affects `frontend/app/pages/`, middleware, composables, shared public types, and the authenticated layout/switcher.
- PostgreSQL persistence uses Laravel migrations and database transactions; password hashing and session security belong to Laravel. The former Node `pg`, custom SQL migration runner, and Node password-hashing proposals are superseded. Exact authentication middleware and any additional package requirements belong to the reviewed design and must be verified before installation.
- Docker Compose already provides frontend, backend, setup, queue, PostgreSQL, and Redis services, with bind mounts and explicit per-service environments. Redis is configured for Laravel cache, sessions, and queues; that configuration does not implement authenticated access by itself.
- The existing Nuxt proxy forwards `/api/v1/*` to Laravel using private `NUXT_BACKEND_URL` configuration. `/api/v1/health` verifies the connection; existing Nuxt sample handlers remain demonstration data until explicitly replaced by reviewed product functionality.
- Access APIs use the `/api/v1` namespace while preserving familiar legacy operation names where applicable. This is not a legacy data migration or a promise of identical legacy response behavior.
- The separately planned `services/fiscal/` capability remains outside this change. No client, certificate, XML capture, subscription, or additional-member management implementation is included.
- Login and initial-selection decisions were confirmed by the user on 2026-10-12: fixed 12-hour sessions without remember-me, generic invalid-credentials messages, retryable service failure retaining the form/email, active-Account-only selection and team-style switching. D008 subsequently supersedes the initial profile-card requirement with automatic default office entry. Automatic password recovery remains deferred; the platform administrator handles assistance, without requiring a contact channel to complete this change, as subsequently clarified by the user. The user subsequently authorized direct implementation of the current remaining contracts on 2026-10-12 (D007), including onboarding only when both users and Accounts are empty. This authorization supersedes the remaining incremental page-review gate; implementation progress is tracked separately in tasks.md.

- The user approved restoring the original Nuxt UI dashboard, including its demonstration widgets and sample navigation. This is an explicit presentation exception: demo content must be labeled and does not constitute implemented Account-scoped business modules. Real identity, Account switching, roles, logout, and Super Admin authorization remain authoritative.
