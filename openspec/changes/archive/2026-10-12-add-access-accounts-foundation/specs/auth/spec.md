> Status: planning reconciled with the approved Nuxt frontend and Laravel backend architecture (D006). Product implementation and detailed page acceptance remain tracked in `tasks.md`; this status does not assert completed authentication or Account isolation.

## Purpose

Establish authenticated global user identities for accounting-office Accounts through first-run setup, password login, and owner activation, without depending on fiscal certificates or commercial subscriptions.

## ADDED Requirements

### Requirement: Bootstrap is available only once
The system SHALL expose `GET /api/v1/registration-status` with `{registration_available:boolean}` and SHALL accept `POST /api/v1/register` only when no users and no Accounts exist. A valid registration SHALL atomically create one user, one active Account, an `admin` membership, and a session selecting that Account; the user SHALL be the initial Super Admin. Input SHALL contain `name`, `email`, `password`, `company`, and `size`; the team-size value SHALL be one of `solo`, `2-10`, `11-50`, `51-200`, or `200+` and SHALL be persisted. Passwords SHALL contain 8–128 characters. No subscription or default department SHALL be created.

#### Scenario: Successful initial registration
- **WHEN** a valid initial registration is submitted to an empty installation
- **THEN** the system returns 201, creates the user, Account and membership together, authenticates the user, and disables subsequent registration

#### Scenario: Installation already initialized
- **WHEN** any user or Account exists and a registration is submitted
- **THEN** the system returns 403 with no new user, Account, or membership

#### Scenario: Concurrent bootstrap requests
- **WHEN** two valid registrations reach an empty installation concurrently
- **THEN** exactly one returns 201 and the other returns 403 after initialization, with only one bootstrap user and Account created

### Requirement: Password login establishes a revocable session
The system SHALL accept `POST /api/v1/login` with email and password, return 200 with the current-user representation on success, and replace any previous session identifier. Unknown email and incorrect password SHALL return the same 422 credentials error with the message "Email ou senha inválidos". Every successful password login SHALL automatically select the default active member Account, defined as the earliest membership by membership ID among active Accounts. This SHALL apply even with multiple memberships; no active membership SHALL leave `current_account` null for unavailable access. Manual switching SHALL remain session-local; a new login SHALL return to the default rather than reuse a manual switch. Sessions SHALL expire no later than 12 hours after authentication, without a remember-me option in this release. This SHALL be an absolute deadline established at authentication; ordinary requests, cookie renewal, and Account switching SHALL NOT extend it. Successful authentication SHALL invalidate the previous session identifier so replay cannot authenticate with it.

#### Scenario: Valid credentials
- **WHEN** a user supplies correct credentials
- **THEN** subsequent requests carry an authenticated session and the response contains only public user and Account fields

#### Scenario: Invalid credentials
- **WHEN** the email is unknown or the password is incorrect
- **THEN** the system returns the same credentials error and creates no authenticated session

#### Scenario: One active office and suspended memberships
- **WHEN** valid credentials identify exactly one active member Account and any number of suspended member Accounts
- **THEN** login selects the sole active Account and returns it as `current_account`

#### Scenario: Multiple active offices enter the default
- **WHEN** valid credentials identify multiple active member Accounts
- **THEN** login authenticates the user with the default active office selected and returns their safe membership list for optional AccountMenu switching

#### Scenario: New login does not reuse a previous office choice
- **WHEN** a user with multiple active member Accounts completes another password login after previously selecting an Account
- **THEN** the new session selects the default active member Account

#### Scenario: Expired session
- **WHEN** a session has expired or been revoked
- **THEN** authenticated endpoints respond 401 and return no protected data

#### Scenario: Activity does not extend authenticated lifetime
- **WHEN** a user continues making requests or switches Account until the original authenticated deadline has passed
- **THEN** the former session receives 401 on authenticated endpoints and no activity or switch extends its validity

#### Scenario: Authentication rotates the previous session
- **WHEN** authentication succeeds and the previous session identifier is replayed
- **THEN** the previous identifier cannot access authenticated data, while the new session represents the authenticated identity

### Requirement: Current identity is independent of tenant access
`GET /api/v1/me` SHALL return `{id,name,email,is_super_admin,accounts,current_account}` for an authenticated user. `accounts` SHALL include only that user's memberships, each with `{id,name,status,role}`; `current_account` SHALL contain those fields or be null. This endpoint SHALL remain available when an Account is suspended or the user has no memberships. Passwords, session credentials, owner activation secrets, and internal Account settings SHALL never be included. Access responses SHALL use `Cache-Control: no-store`.

#### Scenario: Authenticated user without an available Account
- **WHEN** an authenticated user has no active memberships
- **THEN** `/api/v1/me` returns 200 with the user's identity and no active current Account, allowing the interface to explain the situation

#### Scenario: Unauthenticated request
- **WHEN** a visitor requests `/api/v1/me` without a valid session
- **THEN** the endpoint returns 401

#### Scenario: An existing session has no selected office
- **WHEN** an authenticated session predates automatic default selection and has no selected Account
- **THEN** `/api/v1/me` selects the default active membership without extending the authenticated deadline, or returns null when no active membership exists

### Requirement: Logout revokes the server session
`POST /api/v1/logout` SHALL revoke the current session and clear its cookie before returning 204. Reusing that session credential SHALL no longer authenticate requests, including after a concurrent request completes with stale session state. The interface SHALL clear protected client state only after confirmed logout or confirmed session expiration; a network failure SHALL remain a retryable failure rather than a success message.

#### Scenario: Logout and replay
- **WHEN** a user logs out successfully and the former session credential is reused
- **THEN** logout returns 204 and the replay receives 401

#### Scenario: Concurrent activity cannot revive logout
- **WHEN** a request started before logout finishes after logout has successfully revoked the session
- **THEN** replay of the former session still receives 401 and cannot restore authenticated access

### Requirement: Owner first access is bounded and single use
An Account owner activation link SHALL expire after seven days. `GET /api/v1/first-access/status` SHALL accept `token` and `email` and return `{valid:false}` for invalid, consumed, expired, revoked, or suspended-Account links, or `{valid:true,owner_name,account_name}` for a valid link. `POST /api/v1/first-access` SHALL accept `token`, `email`, `password`, and `password_confirmation`, atomically create the new owner identity and `admin` membership, consume the link, and authenticate the owner with that Account selected. Existing identities SHALL not be overwritten or have their password changed; this change SHALL reject owner email conflicts with 422.

#### Scenario: Successful owner activation
- **WHEN** a new owner submits matching valid passwords through a valid link for an active Account
- **THEN** the system returns 201 with the current-user representation and the link is no longer usable

#### Scenario: Invalid or replayed activation
- **WHEN** an invalid, expired, consumed, revoked, or suspended-Account activation is submitted
- **THEN** the system returns 422 and creates no user or membership

#### Scenario: Concurrent activation
- **WHEN** two requests attempt to consume the same valid activation link
- **THEN** exactly one creates the owner and membership, and the other returns 422 without overwriting identity data

### Requirement: Cookie writes require request authenticity
Every state-changing access or Account endpoint SHALL reject untrusted origins or missing/invalid CSRF tokens with 403 before mutation, including login, registration, activation, logout, Account switching, and provisioning. Public access clients SHALL obtain a CSRF token from `GET /api/v1/csrf` and send it through `X-CSRF-Token`. Anonymous sessions used for pre-authentication CSRF SHALL expire no later than 30 minutes after creation, without sliding extension. Clients SHALL refresh their CSRF token after successful authentication or Account switching. A same-origin request SHALL still require a valid CSRF token; the trusted origin SHALL be the public frontend origin rather than a private service address. Authentication cookies SHALL be HttpOnly, SameSite=Lax, host-only, Path=/, and Secure over HTTPS; raw session and activation credentials SHALL never be logged or returned by ordinary identity/listing endpoints.

#### Scenario: Forged login request
- **WHEN** a state-changing request arrives from an untrusted origin or without the expected CSRF token
- **THEN** it receives 403 and leaves session and application data unchanged

#### Scenario: Same-origin mutation has no token
- **WHEN** a mutation comes from the trusted frontend origin but omits its CSRF token
- **THEN** it receives 403 before mutation, without relying on origin alone to authorize the request

#### Scenario: Anonymous CSRF session reaches its deadline
- **WHEN** an anonymous session has existed for 30 minutes despite continued activity
- **THEN** its former CSRF token cannot authorize a mutation and the client must obtain a new session-bound token

### Requirement: Access attempts are rate limited
Public status and CSRF endpoints SHALL allow at most 30 requests per minute per client IP. Registration SHALL allow five, login ten, and activation ten attempts per minute per client IP; login SHALL also allow ten attempts per minute per normalized email. Limits SHALL be shared across application instances. A rejected attempt SHALL receive 429 and `Retry-After` and SHALL not perform the requested operation.

#### Scenario: Repeated login attempts
- **WHEN** an IP or normalized email exceeds the login limit
- **THEN** further attempts receive 429 until the rate-limit window permits them
