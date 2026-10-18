## MODIFIED Requirements

### Requirement: Tenant access requires a current active membership
Every office-scoped tenant operation SHALL require an authenticated user, a selected active Account, and a current membership in that Account. Missing authentication SHALL return 401; missing selection, invalid membership, or suspension SHALL return 403 with `account_required`, `membership_required`, or `account_suspended` respectively. No missing context SHALL fall back to an unrestricted query. Authentication and platform endpoints SHALL use their own explicit guards rather than tenant bypass flags.

#### Scenario: Missing Account context
- **WHEN** an authenticated user calls a tenant endpoint without an authorized current Account
- **THEN** the system returns 403 before reading or changing any tenant data

#### Scenario: No authorized context is available
- **WHEN** an authenticated session has no selected authorized office
- **THEN** tenant operations receive 403 with `account_required`; identity may resolve an active default, while logout and authorized switching remain available

#### Scenario: Membership removed after login
- **WHEN** a session selects an Account but the user no longer has its membership
- **THEN** subsequent tenant requests are denied even if the client still displays the old role

### Requirement: Tenant identifiers cannot override the authorized Account
Office-scoped tenant resource reads and writes SHALL be constrained to the authorized Account. A direct resource ID belonging to another Account SHALL return 404; a related foreign resource ID in a payload SHALL return 422. Tenant payloads SHALL reject caller-supplied `account_id` with 422 rather than changing context. Only the authorized Account-switch endpoint SHALL change the current Account.

#### Scenario: Foreign resource identifier
- **WHEN** an Account A member requests an Account B resource by ID
- **THEN** the result is 404 with no Account B data

#### Scenario: Injected Account in a tenant payload
- **WHEN** an operational payload supplies an `account_id` instead of relying on authorized context
- **THEN** the operation returns 422 and changes nothing

### Requirement: Stale tabs cannot act in a newly selected Account
Office-scoped tenant requests SHALL include `X-Account-Id` matching the Account currently displayed by the client. This assertion SHALL never grant access by itself. An absent assertion SHALL return 422, and a mismatch with the session's authorized Account SHALL return 409 with `account_context_changed` before reading or changing tenant data. Identity, access, switching, and platform endpoints SHALL not require this assertion. Tabs sharing a session SHALL refresh identity on focus and discard their previous tenant view when selection changed.

#### Scenario: Another tab changed Account
- **WHEN** a tab displaying Account A submits a tenant request after another tab selected Account B in the same session
- **THEN** the request receives 409 and performs no operation in Account B

## ADDED Requirements

### Requirement: Company authorization is independent of office selection
ERP company operations SHALL use company membership or explicit owner-authorized office delegation and company context instead of treating an office Account as an issuing company. Existing Account-context requirements SHALL continue governing office routes, jobs and storage. The presence of company access SHALL never grant access to an office portfolio or change the existing Account-role/subscription guards. Company context SHALL be restored after every background operation and missing context SHALL fail closed.

#### Scenario: Company owner requests office portfolio
- **WHEN** a company-only owner requests office Client/Fiscal/Team data
- **THEN** the existing office guard denies access without a real Account membership

#### Scenario: Failed company job precedes another company
- **WHEN** a worker processes a company A job that throws and then a company B job
- **THEN** the second job uses only B context and no context-free operation becomes unscoped
