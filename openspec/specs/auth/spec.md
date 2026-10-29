# auth Specification

## Purpose

Establish authenticated global user identities for accounting-office Accounts through first-run setup, password login, and owner activation, without depending on fiscal certificates or commercial subscriptions.

## Requirements

### Requirement: Bootstrap is available only once
The system SHALL expose `GET /api/v1/registration-status` with `{registration_available:boolean}` and SHALL accept `POST /api/v1/register` only when no users and no Accounts exist. A valid registration SHALL atomically create one user, one active Account, an `admin` membership, and a session selecting that Account; the user SHALL be the initial Super Admin. Input SHALL contain `name`, `email`, `password`, `company`, and `size`; the team-size value SHALL be one of `solo`, `2-10`, `11-50`, `51-200`, or `200+` and SHALL be persisted. Passwords SHALL contain 8–128 characters. Account creation SHALL also initialize one active Básico subscription and the Fiscal, Pessoal, Contábil, and Societário departments within the creation transaction. Existing catalog rows SHALL not close initial registration or be reset by bootstrap.

#### Scenario: Successful initial registration
- **WHEN** a valid initial registration is submitted to an empty installation
- **THEN** the system returns 201, creates the user, Account and membership together with its default subscription and departments, authenticates the user, and disables subsequent registration

#### Scenario: Installation already initialized
- **WHEN** any user or Account exists and a registration is submitted
- **THEN** the system returns 403 with no new user, Account, or membership

#### Scenario: Concurrent bootstrap requests
- **WHEN** two valid registrations reach an empty installation concurrently
- **THEN** exactly one returns 201 and the other returns 403 after initialization, with only one bootstrap user and Account created

### Requirement: Password login establishes a revocable session
The system SHALL accept `POST /api/v1/login` with email and password, return 200 with the current-user representation on success, and replace any previous session identifier. Unknown email and incorrect password SHALL return the same 422 credentials error with the message "Email ou senha inválidos". Every successful password login SHALL automatically select the default active member Account, defined as the earliest membership by membership ID among active Accounts. This SHALL apply even with multiple memberships; absence of an active office membership SHALL leave `current_account` null; company workspace availability SHALL be resolved independently under erp-company-foundation. Manual switching SHALL remain session-local; a new login SHALL return to the default rather than reuse a manual switch. Sessions SHALL expire no later than 12 hours after authentication, without a remember-me option in this release. This SHALL be an absolute deadline established at authentication; ordinary requests, cookie renewal, and Account switching SHALL NOT extend it. Successful authentication SHALL invalidate the previous session identifier so replay cannot authenticate with it.

#### Scenario: Valid credentials
- **WHEN** a user supplies correct credentials
- **THEN** subsequent requests carry an authenticated session and the response contains only public user, Account and authorized Company fields

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
`GET /api/v1/me` SHALL retain `{id,name,email,is_super_admin,accounts,current_account}` and add safe `companies` and `current_company` fields for an authenticated user. Company entries SHALL expose only authorized id/name/status/role/permission projections, distinguishing direct membership from explicit office delegation. Company-only identities SHALL not acquire office memberships. Suspended company metadata SHALL not authorize operation. `accounts` SHALL include only that user's memberships, each with `{id,name,status,role}`; `current_account` SHALL contain those fields or be null. This endpoint SHALL remain available when an Account is suspended or the user has no memberships. Passwords, session credentials, owner activation secrets, and internal Account settings SHALL never be included. Access responses SHALL use `Cache-Control: no-store`.

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

### Requirement: Business writes use the established access session
Client, fiscal, monitoring, work, member, department, and WhatsApp browser mutations SHALL use the existing authenticated session, trusted frontend origin, and session-bound CSRF token. Business integration SHALL not create a separate bearer-authentication or legacy-login path. Public provider webhooks SHALL instead follow their explicitly authenticated provider contract.

#### Scenario: Business write without CSRF
- **WHEN** an authenticated member posts a client with a missing or invalid CSRF token
- **THEN** the API returns 403 before creating the client

### Requirement: ERP login keeps one global session
Company selection and login SHALL reuse the existing revocable session and absolute deadline. New password login SHALL prefer the default active office; if none exists it SHALL select the earliest active direct company membership. Company creation or selection SHALL not create an office membership, duplicate a password store or extend authenticated lifetime.

#### Scenario: Login without an office but with company membership
- **WHEN** valid credentials identify a user with no active office and an active direct company membership
- **THEN** current_account remains null and the identity includes the default company for ERP entry

### Requirement: ERP identity does not reveal foreign companies
Company identity projections SHALL include only current direct memberships or authorized office delegations and SHALL exclude credentials, internal settings and revoked grants. The absence of an active office SHALL not prevent a permitted company identity read.

#### Scenario: Delegation disappears after revocation
- **WHEN** the user refreshes identity after their only company delegation was revoked
- **THEN** that company no longer appears as an authorized operating destination

### Requirement: Commercial password reset targets an authorized active member
A currently authorized office administrator SHALL directly set a confirmed 8–128-character password only for an active member of its currently managed Company through a target-bound commercial command. The administrator SHALL never read the old password. Global identity and all memberships SHALL remain unchanged, and the UI SHALL state that a reset changes the account password across Companies. Super Admin identities, identities holding any Account administrator role and the acting administrator's own identity SHALL be excluded from this commercial direct-reset path; recipient-only email recovery SHALL remain available when authorized. Raw passwords, password-derived operation fingerprints and credential hashes SHALL NOT enter command journals, audit/history, logs, client storage or queued payloads. The identity's salted password hash remains confined to its credential store.

#### Scenario: Reset a linked ordinary user's password
- **WHEN** a real office administrator submits a valid confirmed password for an eligible active Company member
- **THEN** the password and authentication revision change once, the audit identifies actor/Company/target without credentials, and no membership or ownership changes

#### Scenario: Protected or unlinked target
- **WHEN** a reset references an unrelated/deleted membership, privileged identity or the acting administrator
- **THEN** it is refused without revealing privilege details or changing credentials, and only an authorized email recovery option is offered where applicable

#### Scenario: Same operation is replayed with another password
- **WHEN** a completed reset's original operation identity is submitted again
- **THEN** it returns only the original sanitized receipt after reauthorization and never applies the second password or stores a password fingerprint

### Requirement: Password recovery is recipient controlled and short lived
An authorized member-recovery request SHALL deliver a single-use link only to that member's currently registered email and SHALL never return its bearer token/URL to the office. Recovery tokens SHALL expire after at most 60 minutes; replacement SHALL invalidate older tokens. Completing recovery SHALL require the token, the intended identity and a matching 8–128-character confirmed password, with current initiating-relationship eligibility checked again. The public receiver SHALL not require an office/Company selection or open public registration. Invalid, expired, consumed or replaced links SHALL have indistinguishable invalid-link responses; service failures SHALL remain retryable. Issuance SHALL be limited to one new link per normalized identity per 60 seconds plus shared per-actor/IP attempt budgets; blocked attempts SHALL return 429 with Retry-After. Direct reset and later membership/management revocation SHALL invalidate outstanding recovery attempts for that affected recovery authority.

#### Scenario: Someone asks to redirect recovery
- **WHEN** an administrator supplies a different recipient or foreign user identifier
- **THEN** the request is rejected and no recovery link is returned or sent to the supplied address

#### Scenario: Two receivers consume one link
- **WHEN** independent valid requests attempt to consume the same recovery token
- **THEN** exactly one changes the password and the other receives the same invalid-link response without another credential update

#### Scenario: Mail delivery is unavailable or uncertain
- **WHEN** the configured transport cannot securely deliver a recovery message or its outcome is uncertain
- **THEN** the UI distinguishes failed/unknown delivery from transport acceptance, logs contain no bearer link, and replay/lookup does not issue or send another token automatically

### Requirement: Password changes revoke old sessions globally
Successful direct or recipient password recovery SHALL atomically advance an identity authentication revision with the password change. All sessions authenticated under a prior revision SHALL fail subsequent authentication checks across application instances and Account/Company contexts, even if an already-running request later writes stale session data. Password login SHALL verify the password and capture its authentication revision consistently with concurrent recovery; a request using an obsolete password SHALL not acquire a session carrying the new revision. Recovery SHALL not implicitly authenticate another identity or extend existing absolute deadlines. Missing revision state SHALL be compatible only with the initial unmigrated revision, and authentication-store failure SHALL fail closed.

#### Scenario: Stale session writes after reset
- **WHEN** an old authenticated request persists its session after a password reset committed
- **THEN** subsequent use of that session still receives 401 and cannot revive the old authentication revision

#### Scenario: Password login races reset
- **WHEN** old-password login and reset run in independent processes
- **THEN** login either precedes the reset and receives an immediately obsolete session or rejects the old password, without authenticating it under the new revision
