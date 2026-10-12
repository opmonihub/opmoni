> Status: superseded architecture. This pending proposal targets the earlier Nuxt-only backend decision (D005). The user subsequently chose a Laravel 13 backend in `backend/` (D006). Preserve this proposal as reference; do not apply its implementation tasks until the proposal, design, specs, and tasks have been revised and reviewed for D006. See `docs/architecture/product-decisions.md`.

## Why

The approved first release needs authenticated accounting offices and isolated Accounts before client A1 certificates or fiscal XML documents can be safely addressed. The current repository is a Nuxt dashboard template without authentication or persistence; the legacy provides working product flows, but its Laravel implementation and subscription dependencies do not match the selected architecture.

## What Changes

- Implement access and application persistence in Nuxt `server/`; keep PHP exclusively in `services/fiscal/` for a later fiscal change.
- Preserve the legacy's first-run onboarding, email/password login, logout, global user identity, three membership roles, and Account selection.
- Add minimal Super Admin Account provisioning, owner first access, suspension, and reactivation so additional offices can join after bootstrap.
- Provide access pages and a minimal authenticated shell; document their fields, loading, validation, and failure states.
- Propose request-scoped Account resolution that denies access without a valid Account, revocable database sessions, and transactional one-use owner activation.
- Remove subscription prerequisites from access: commercial plans and subscriptions are deferred by the accepted release scope.
- Keep client registration, certificates, XML capture, member-management screens, support access, Work, SERPRO, and WhatsApp outside this change.

## Capabilities

### New Capabilities

- `auth`: One-time bootstrap, password authentication, revocable sessions, logout, current-user contract, and owner first access.
- `accounts`: Global user identity, membership roles, current Account selection, and minimal platform provisioning/suspension.
- `isolation`: Request-scoped Account authorization, role enforcement, cross-Account access denial, and cache isolation.
- `access-pages`: Login, onboarding, first access, Account-unavailable states, Account switcher, and minimal platform Account page.

### Modified Capabilities

None. This repository has no existing main capability specifications; the legacy is a reference, not an OpenSpec root to modify.

## Impact

- Future implementation affects `server/api/`, `server/utils/`, server-only persistence/migrations, `app/pages/`, `app/middleware/`, `app/composables/`, and the authenticated layout/switcher.
- Proposed persistence is PostgreSQL with `pg`, versioned SQL migrations, and Node's asynchronous password hashing; exact dependency versions must be verified at implementation time.
- Same-origin cookie authentication replaces Laravel/Sanctum. No Laravel `backend/` or application authentication logic is added to the PHP service.
- Access APIs preserve familiar legacy route names where applicable, but this is not a data migration or a promise of Laravel response compatibility.
- Planning artifacts are for review. Approval of the release scope and backend location does not approve the detailed page or security design, and no product code is implemented by this change proposal.
