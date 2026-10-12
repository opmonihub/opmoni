> Status: superseded architecture. This pending proposal targets the earlier Nuxt-only backend decision (D005). The user subsequently chose a Laravel 13 backend in `backend/` (D006). Preserve this proposal as reference; do not apply its implementation tasks until the proposal, design, specs, and tasks have been revised and reviewed for D006. See `docs/architecture/product-decisions.md`.

## Purpose

Establish authenticated global user identities for accounting-office Accounts through first-run setup, password login, and owner activation, without depending on fiscal certificates or commercial subscriptions.

## ADDED Requirements

### Requirement: Bootstrap is available only once
The system SHALL expose `GET /api/registration-status` with `{registration_available:boolean}` and SHALL accept `POST /api/register` only when no users and no Accounts exist. A valid registration SHALL atomically create one user, one active Account, an `admin` membership, and a session selecting that Account; the user SHALL be the initial Super Admin. Input SHALL contain `name`, `email`, `password`, `company`, and `size`; the team-size value SHALL be one of `solo`, `2-10`, `11-50`, `51-200`, or `200+` and SHALL be persisted. Passwords SHALL contain 8–128 characters. No subscription or default department SHALL be created.

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
The system SHALL accept `POST /api/login` with email and password, return 200 with the current-user representation on success, and replace any previous session identifier. Unknown email and incorrect password SHALL return the same 422 credentials error. Login SHALL choose an active Account membership, or leave the Account unset when no active membership exists. Sessions SHALL expire no later than 12 hours after authentication, without a remember-me option in this release.

#### Scenario: Valid credentials
- **WHEN** a user supplies correct credentials
- **THEN** subsequent requests carry an authenticated session and the response contains only public user and Account fields

#### Scenario: Invalid credentials
- **WHEN** the email is unknown or the password is incorrect
- **THEN** the system returns the same credentials error and creates no authenticated session

#### Scenario: Expired session
- **WHEN** a session has expired or been revoked
- **THEN** authenticated endpoints respond 401 and return no protected data

### Requirement: Current identity is independent of tenant access
`GET /api/me` SHALL return `{id,name,email,is_super_admin,accounts,current_account}` for an authenticated user. `accounts` SHALL include only that user's memberships, each with `{id,name,status,role}`; `current_account` SHALL contain those fields or be null. This endpoint SHALL remain available when an Account is suspended or the user has no memberships. Passwords, session credentials, owner activation secrets, and internal Account settings SHALL never be included. Access responses SHALL use `Cache-Control: no-store`.

#### Scenario: Authenticated user without an available Account
- **WHEN** an authenticated user has no active memberships
- **THEN** `/api/me` returns 200 with the user's identity and no active current Account, allowing the interface to explain the situation

#### Scenario: Unauthenticated request
- **WHEN** a visitor requests `/api/me` without a valid session
- **THEN** the endpoint returns 401

### Requirement: Logout revokes the server session
`POST /api/logout` SHALL revoke the current session and clear its cookie before returning 204. Reusing that session credential SHALL no longer authenticate requests. The interface SHALL clear protected client state only after confirmed logout or confirmed session expiration; a network failure SHALL remain a retryable failure rather than a success message.

#### Scenario: Logout and replay
- **WHEN** a user logs out successfully and the former session credential is reused
- **THEN** logout returns 204 and the replay receives 401

### Requirement: Owner first access is bounded and single use
An Account owner activation link SHALL expire after seven days. `GET /api/first-access/status` SHALL accept `token` and `email` and return `{valid:false}` for invalid, consumed, expired, revoked, or suspended-Account links, or `{valid:true,owner_name,account_name}` for a valid link. `POST /api/first-access` SHALL accept `token`, `email`, `password`, and `password_confirmation`, atomically create the new owner identity and `admin` membership, consume the link, and authenticate the owner with that Account selected. Existing identities SHALL not be overwritten or have their password changed; this change SHALL reject owner email conflicts with 422.

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
Every state-changing access or Account endpoint SHALL reject untrusted origins or missing/invalid CSRF tokens with 403 before mutation, including login, registration, activation, logout, Account switching, and provisioning. Public access clients SHALL obtain a CSRF token from `GET /api/csrf` and send it through `X-CSRF-Token`. Authentication cookies SHALL be HttpOnly, SameSite=Lax, host-only, and Secure over HTTPS; raw session and activation credentials SHALL never be logged or returned by ordinary identity/listing endpoints.

#### Scenario: Forged login request
- **WHEN** a state-changing request arrives from an untrusted origin or without the expected CSRF token
- **THEN** it receives 403 and leaves session and application data unchanged

### Requirement: Access attempts are rate limited
Public status and CSRF endpoints SHALL allow at most 30 requests per minute per client IP. Registration SHALL allow five, login ten, and activation ten attempts per minute per client IP; login SHALL also allow ten attempts per minute per normalized email. Limits SHALL be shared across application instances. A rejected attempt SHALL receive 429 and `Retry-After` and SHALL not perform the requested operation.

#### Scenario: Repeated login attempts
- **WHEN** an IP or normalized email exceeds the login limit
- **THEN** further attempts receive 429 until the rate-limit window permits them
