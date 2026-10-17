## MODIFIED Requirements

### Requirement: Roles are enforced by the server
Within an authorized Account, `admin` and `operador` SHALL manage clients, client certificates, fiscal capture, monitoring actions, and supported work writes; `user` SHALL have read access only. Department management SHALL require `admin` or `operador`; membership administration, office certificate management, and WhatsApp channel pairing/disconnection SHALL require a real Account `admin`. Integration enablement SHALL additionally require Super Admin. Super Admin SHALL govern platform endpoints independently and SHALL NOT receive tenant access or a higher tenant role without real membership. Tenant writes SHALL also obey the subscription and creation-limit contracts in platform-commercial-management.

#### Scenario: Read-only member attempts an operational write
- **WHEN** a member with role `user` attempts an operation requiring `admin` or `operador`
- **THEN** the server returns 403 and leaves Account data unchanged

#### Scenario: Global administrator is not an Account member
- **WHEN** a Super Admin without membership attempts to read or write tenant resources of that Account
- **THEN** the server denies access; global platform administration does not grant operating access

### Requirement: Concurrent requests and background operations preserve context
Each request SHALL have its own authenticated Account context; concurrent requests for different Accounts SHALL not share mutable tenant selection. Queue jobs, scheduled commands, console operations, and private tenant storage operations SHALL carry an explicit Account identifier and SHALL restore the prior context in a finally-equivalent cleanup on both success and failure. Missing background context SHALL not authorize unscoped tenant operations. Client responses containing protected data SHALL not be cached publicly.

#### Scenario: Parallel requests from separate offices
- **WHEN** two sessions from different Accounts issue tenant requests concurrently
- **THEN** each response contains only its authorized Account's data, including after a previous request failed

## ADDED Requirements

### Requirement: Inactive subscriptions deny tenant writes
After authentication, selected Account membership, and Account-status checks, an inactive or missing subscription SHALL block state-changing tenant requests with 403 `subscription_inactive`. Read requests and explicit platform/access operations SHALL retain their own guards. This gate SHALL not grant access based on subscription alone.

#### Scenario: Subscription canceled during session
- **WHEN** a member attempts a business write after subscription cancellation
- **THEN** the request returns 403 `subscription_inactive` without changing tenant data
