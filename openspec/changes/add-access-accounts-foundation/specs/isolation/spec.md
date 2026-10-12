> Status: superseded architecture. This pending proposal targets the earlier Nuxt-only backend decision (D005). The user subsequently chose a Laravel 13 backend in `backend/` (D006). Preserve this proposal as reference; do not apply its implementation tasks until the proposal, design, specs, and tasks have been revised and reviewed for D006. See `docs/architecture/product-decisions.md`.

## Purpose

Ensure that future client records, certificates, and fiscal documents can be accessed only through the authenticated user's current active Account and authorized membership, including under concurrent requests.

## ADDED Requirements

### Requirement: Tenant access requires a current active membership
Every tenant operation SHALL require an authenticated user, a selected active Account, and a current membership in that Account. Missing authentication SHALL return 401; missing selection, invalid membership, or suspension SHALL return 403 with `account_required`, `membership_required`, or `account_suspended` respectively. No missing context SHALL fall back to an unrestricted query. Authentication and platform endpoints SHALL use their own explicit guards rather than tenant bypass flags.

#### Scenario: Missing Account context
- **WHEN** an authenticated user calls a tenant endpoint without an authorized current Account
- **THEN** the system returns 403 before reading or changing any tenant data

#### Scenario: Membership removed after login
- **WHEN** a session selects an Account but the user no longer has its membership
- **THEN** subsequent tenant requests are denied even if the client still displays the old role

### Requirement: Roles are enforced by the server
Within an authorized Account, `admin` and `operador` SHALL be eligible for future client/certificate management and fiscal capture; `user` SHALL have read access only. Membership administration SHALL be reserved to `admin` when introduced in a later change. Super Admin SHALL govern platform Account endpoints independently and SHALL NOT receive implicit tenant access without membership. No permissions SHALL depend on subscription or plan state in this release.

#### Scenario: Read-only member attempts an operational write
- **WHEN** a member with role `user` attempts an operation requiring `admin` or `operador`
- **THEN** the server returns 403 and leaves Account data unchanged

#### Scenario: Global administrator is not an Account member
- **WHEN** a Super Admin without membership attempts to read or write tenant resources of that Account
- **THEN** the server denies access; global platform administration does not grant operating access

### Requirement: Tenant identifiers cannot override the authorized Account
Tenant resource reads and writes SHALL be constrained to the authorized Account. A direct resource ID belonging to another Account SHALL return 404; a related foreign resource ID in a payload SHALL return 422. Tenant payloads SHALL reject caller-supplied `account_id` with 422 rather than changing context. Only the authorized Account-switch endpoint SHALL change the current Account.

#### Scenario: Foreign resource identifier
- **WHEN** an Account A member requests an Account B resource by ID
- **THEN** the result is 404 with no Account B data

#### Scenario: Injected Account in a tenant payload
- **WHEN** an operational payload supplies an `account_id` instead of relying on authorized context
- **THEN** the operation returns 422 and changes nothing

### Requirement: Concurrent requests and background operations preserve context
Each request SHALL have its own authenticated Account context; concurrent requests for different Accounts SHALL not share mutable tenant selection. Background operations introduced later SHALL carry explicit Account identifiers and SHALL reject execution without them. Client responses containing protected data SHALL not be cached publicly.

#### Scenario: Parallel requests from separate offices
- **WHEN** two sessions from different Accounts issue tenant requests concurrently
- **THEN** each response contains only its authorized Account's data, including after a previous request failed

### Requirement: Stale tabs cannot act in a newly selected Account
Tenant requests SHALL include `X-Account-Id` matching the Account currently displayed by the client. This assertion SHALL never grant access by itself. An absent assertion SHALL return 422, and a mismatch with the session's authorized Account SHALL return 409 with `account_context_changed` before reading or changing tenant data. Identity, access, switching, and platform endpoints SHALL not require this assertion. Tabs sharing a session SHALL refresh identity on focus and discard their previous tenant view when selection changed.

#### Scenario: Another tab changed Account
- **WHEN** a tab displaying Account A submits a tenant request after another tab selected Account B in the same session
- **THEN** the request receives 409 and performs no operation in Account B

### Requirement: Switching or ending a session removes stale tenant data
After a successful Account switch or logout, the interface SHALL clear tenant-specific cached results, selections, forms, and authorization state before rendering the next Account or public page. Late responses from a former Account SHALL not be rendered under the new Account. Failed switching SHALL preserve the previous authorized context.

#### Scenario: Account switch with cached documents
- **WHEN** a member switches Account while client results from the previous Account are cached or in flight
- **THEN** those results cannot appear in the newly selected Account's interface
