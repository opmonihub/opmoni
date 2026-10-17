> Reconciliation status (2026-10-13): this artifact records the earlier platform target, not the delivered system baseline. See [system reconciliation](../../../../../docs/architecture/system-spec-reconciliation.md) and `reconcile-system-specs`. D009 supersedes foreign support entry/elevation. Other stronger API, catalog, certificate-source, and concurrency guarantees remain pending where absent or unverified. Reconcile this artifact against the canonical specs before implementation, sync, or archive; do not mark its unchecked tasks complete from the import or documentation sync.

## Purpose

Allow an authorized Super Admin to operate in an active foreign Account using an explicit, auditable session context while preserving real memberships, identity, and tenant isolation.

## ADDED Requirements

### Requirement: Explicit session-local support entry
POST `/api/v1/support/accounts/{id}/enter` SHALL require Super Admin, an active target Account, valid origin/CSRF, and the existing serialized session. It SHALL preserve the authenticated user's identity and actual memberships, set a support context in that session only, preserve its original expiry, and return the top-level identity representation. Nonexistent targets SHALL return 404 for authorized administrators; suspended targets SHALL return 403 with `account_suspended`. An own membership SHALL use ordinary Account switching rather than fabricate support. Repeating entry to the current supported Account SHALL not duplicate the enter event; entering a different foreign Account SHALL close the previous support context before opening the new one.

#### Scenario: Enter a foreign active Account
- **WHEN** a Super Admin enters an active Account without a membership
- **THEN** only that session enters support, no membership/user record is created or altered, and one enter event is recorded

#### Scenario: Switch support targets
- **WHEN** a supported administrator enters a different foreign Account
- **THEN** the prior context records exit and the new one records enter with no mixed tenant state

### Requirement: Support-aware identity and tenant authorization
Identity SHALL retain `accounts` as real memberships, add `is_member` metadata, and include `support:null|{account_id,account_name,started_at}`. During support, `current_account` SHALL represent the active supported Account with `is_member=false` and effective role admin; this representation SHALL not masquerade as a persisted membership. Every operational request SHALL revalidate current Super Admin authorization, target existence/status, explicit support context, and the displayed Account assertion. Body-supplied Account IDs SHALL remain prohibited. Missing context SHALL fail closed; stale assertions SHALL return 409. Ordinary Account switching SHALL remain membership-only and SHALL end support with an exit event before selecting a real membership.

#### Scenario: Stale request from the previous office
- **WHEN** an operational request asserts the prior Account after support entry
- **THEN** it receives `account_context_changed` without reading or modifying either Account's operational data

#### Scenario: Super Admin flag is revoked
- **WHEN** an active support user's global privilege is revoked before the next request
- **THEN** the support privilege is rejected and the user cannot continue with the effective admin role

### Requirement: Safe support exit and invalidation
POST `/api/v1/support/exit` SHALL work without a selected active tenant, record exit, clear support, and restore the previous Account only if it is still an active real membership; otherwise it SHALL restore the default active membership or null. It SHALL be idempotent when no support is active and SHALL not extend session expiry. Suspension/deletion/privilege loss SHALL clear invalid support on identity refresh, record the invalidation reason where a context existed, and prevent tenant access. Logout SHALL clear support and record exit while preserving the existing logout revocation behavior. A new login SHALL never resume support. The absence of any own Account SHALL not prevent exit or platform administration.

#### Scenario: Exit with no own membership
- **WHEN** a Super Admin with no active own Account exits support
- **THEN** support clears successfully, current Account becomes null, and the Admin workspace remains available

### Requirement: Transactional support audit
Support audit SHALL persist actor ID, Account ID, action (`enter|exit|create|update|delete`), trusted client IP, timestamp, and allowlisted resource metadata. Enter/exit and support-mode operational mutations SHALL fail if their audit cannot commit; rolled-back mutations SHALL not leave successful audit events. Reads SHALL not create write events. Events SHALL not contain secrets, passwords, tokens, certificate bytes, raw request bodies, or session identifiers. Audit events SHALL remain available after context exit and SHALL not expose update/delete APIs. Concurrent session transitions SHALL not create duplicate or contradictory successful events.

#### Scenario: Audit insertion fails during a support mutation
- **WHEN** the application cannot persist the required audit record
- **THEN** the operational mutation rolls back and is not reported as successful

### Requirement: Support search and audit page
`/admin/suporte` SHALL provide Entrar and Auditoria tabs. Account search SHALL use the server Account query/detail APIs, show name/ID/status, and enable entry only for active Accounts. GET `/api/v1/admin/support/logs` SHALL return a 15-row paginated list ordered by timestamp/ID descending with optional positive `account_id`, actor name/email, Account name, action, timestamp, IP, and allowlisted metadata. Auditoria SHALL provide Account filtering, column visibility, count, pagination, and explicit empty/error/loading states. Resumo SHALL reuse the same safe event representation.

#### Scenario: Search and filter support data
- **WHEN** an administrator searches by Account name and filters audit by a valid Account ID
- **THEN** account search does not download every platform Account and audit contains only the selected Account's events

### Requirement: Visible support context and tenant-state reset
While support is active, the application SHALL show a persistent banner naming the Account, describing audited access, and offering exit. Entry, exit, real-membership switching, invalidation, and cross-tab/focus detection SHALL clear previous Account-bound client data before continuing. Navigation SHALL not return stale resources from a previous office. The Super Admin group SHALL remain available in support mode.

#### Scenario: Focus after another tab exits support
- **WHEN** a tab refreshes identity after support was exited in another tab sharing its session
- **THEN** its support banner and previous Account-bound data are cleared and navigation uses the restored identity
