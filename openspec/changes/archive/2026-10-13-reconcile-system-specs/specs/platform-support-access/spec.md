## Purpose

Provide platform Account search and audit browsing while preserving the accepted requirement for real membership before entering office operations.

## ADDED Requirements

### Requirement: Support entry uses real membership only
The support page SHALL search platform Accounts through the existing list API and enable entry only for active real memberships. Entry SHALL use the ordinary Account-switch endpoint, keep the actual membership role, and never create a foreign support role or implicit membership. Foreign support enter/exit APIs, support identity projection, and a support impersonation banner SHALL not be represented as current functionality.

#### Scenario: Foreign office search result
- **WHEN** a Super Admin finds an active Account without their membership
- **THEN** entry is disabled and tenant access remains denied

#### Scenario: Super Admin with read-only office role
- **WHEN** the administrator enters an own Account with role `user`
- **THEN** tenant writes remain forbidden rather than gain an effective admin role

### Requirement: Read-only support audit browsing
`GET /api/v1/admin/support/logs` SHALL require Super Admin and expose paginated stored events ordered by descending ID, optionally filtered by an existing Account ID. Resources SHALL omit credential/session material and expose safe actor, Account, action, time, IP, and metadata. The interface SHALL distinguish loading, retryable failure, and empty audit history. Audit browsing SHALL not claim that ordinary member switching generates support enter/exit events.

#### Scenario: Filter existing Account audit
- **WHEN** an administrator supplies an existing Account ID
- **THEN** the response contains only matching stored audit events

#### Scenario: No stored events
- **WHEN** the authorized audit query matches no rows
- **THEN** the page shows an empty result without claiming support operations took place
