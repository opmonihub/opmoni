## MODIFIED Requirements

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

## ADDED Requirements

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
