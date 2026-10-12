> Status: superseded architecture. This pending proposal targets the earlier Nuxt-only backend decision (D005). The user subsequently chose a Laravel 13 backend in `backend/` (D006). Preserve this proposal as reference; do not apply its implementation tasks until the proposal, design, specs, and tasks have been revised and reviewed for D006. See `docs/architecture/product-decisions.md`.

## Purpose

Provide clear Portuguese access pages for accounting-office operators and platform administrators, distinguishing first installation, daily login, owner activation, and unavailable Account access.

## ADDED Requirements

### Requirement: Protected navigation resolves access before rendering
Unauthenticated visitors to protected pages SHALL be redirected to `/onboarding` when initial registration is available, otherwise to `/login`, without rendering protected content first. Failure to check registration availability SHALL fall back to login. An authenticated user without an active selected Account SHALL reach `/acesso-indisponivel`; Super Admin platform pages SHALL remain accessible without tenant selection. A normal user visiting `/admin/contas` SHALL receive an access-denied page rather than platform data.

#### Scenario: First visit to an empty installation
- **WHEN** a visitor opens a protected page while bootstrap is available
- **THEN** the first rendered destination is onboarding and no protected content is exposed

### Requirement: Login presents usable and truthful controls
`/login` SHALL display email, password, and Entrar, with field validation and a disabled loading submit state. It SHALL distinguish invalid credentials, rate limiting, and service unavailability, retaining the email after failure and allowing retry. Successful authentication SHALL navigate to `/` or `/acesso-indisponivel` according to available membership. The page SHALL offer no remember-me, social login, or password-recovery controls without an implemented contract, and SHALL offer onboarding only when registration is available.

#### Scenario: Service unavailable during login
- **WHEN** a valid form cannot be submitted because the service is unavailable
- **THEN** the page explains the temporary failure and allows retry without labeling it an incorrect password

### Requirement: Onboarding represents initial installation only
`/onboarding` SHALL show three steps: Acesso with name, email and password; Escritório with office name and team size; and Revisão with the non-secret values. Voltar and Continuar SHALL preserve entered values; Concluir SHALL submit once while loading. Passwords SHALL never appear in the review summary. Successful bootstrap SHALL open the authenticated shell; a concurrent initialization SHALL explain that setup is already complete and offer login. The page SHALL redirect to login if bootstrap is unavailable and SHALL not claim terms acceptance without supplied terms and a server contract.

#### Scenario: Review initial setup
- **WHEN** valid access and office information have been entered
- **THEN** the review shows name, email, office and team size, with no password displayed

### Requirement: First access separates invalid links from service failure
`/primeiro-acesso` SHALL validate the token and email, show a loading state during validation, and display the owner and office names only for valid activation. It SHALL request password and confirmation and protect against duplicate submits. Invalid/expired links SHALL explain that a new link is needed; network or server failures SHALL have a retry action instead of being reported as invalid links. The page SHALL not load third-party analytics or assets that receive the activation URL and SHALL use a no-referrer policy.

#### Scenario: Link validation fails temporarily
- **WHEN** validation cannot complete because of network or server failure
- **THEN** the page shows a retryable validation failure and does not state that the link is expired

### Requirement: Account-unavailable page offers a way forward
`/acesso-indisponivel` SHALL explain whether no active membership is available or the selected Account is suspended, without displaying protected operational data. It SHALL offer another active membership when available, logout, and platform Account administration only for Super Admin. It SHALL not redirect in a loop between itself and the authenticated home.

#### Scenario: All own Accounts are suspended
- **WHEN** an authenticated member has no active Account to select
- **THEN** the page explains the blocked access and still allows logout

### Requirement: Authenticated shell has an Account-aware switcher
The shell SHALL show the user's current Account name and role, an Account switcher listing their memberships, and logout. The current Account SHALL be marked; suspended choices SHALL be disabled. A successful switch SHALL reload into `/` after discarding previous tenant state; failure SHALL show an actionable error and retain the previous Account. The initial home SHALL contain only real identity and Account information, with no template revenue or sales figures and no links to unimplemented business modules.

#### Scenario: Failed Account switch
- **WHEN** the switch request is denied or fails
- **THEN** the current Account and displayed data remain unchanged and the error is visible

### Requirement: Platform Account page supports only foundational operations
`/admin/contas` SHALL be visible only to Super Admin and SHALL provide name/ID search, active/suspended filtering, pagination, creation, rename, suspension/reactivation, and owner-link reissue. The creation modal SHALL request office name, owner name, and owner email, with field errors and a loading state. A newly issued activation URL SHALL be shown in a temporary copy action; list refresh or modal reopening SHALL not reveal it again. Suspension SHALL require confirmation. The page SHALL distinguish loading, failed-load with retry, empty installation, and empty filtered results. It SHALL expose no plans, billing, support entry, password assignment, or operational Account data.

#### Scenario: New office requires first access
- **WHEN** a Super Admin creates a valid office
- **THEN** the page offers the newly generated owner link for copying and refreshes the Account list

#### Scenario: No results match the filter
- **WHEN** a successful filtered Account query returns no records
- **THEN** the page offers clearing the filters rather than showing a service error
