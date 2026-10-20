# platform-support-access Specification

## Purpose

Provide platform Account search and audit browsing while preserving the accepted requirement for real membership before entering office operations.

## Requirements

### Requirement: Support entry uses real membership only
The support page SHALL search platform Accounts through bounded paginated server name search and the additive generic detail API for numeric ID exactly once, never automatically scanning all pages. It SHALL enable entry only for active real memberships through ordinary Account switching, retaining the actual membership role and absolute session deadline. Foreign support enter/exit APIs, support identity projection, implicit membership, effective support admin role and impersonation banner SHALL not be introduced. Entry, ordinary membership switching, focus identity changes and invalidation SHALL clear stale Account-bound client state under the existing access contract. Platform access SHALL remain available without an own office.

#### Scenario: Foreign office search result
- **WHEN** a Super Admin finds an active Account without their membership
- **THEN** entry is disabled and tenant access remains denied

#### Scenario: Super Admin with read-only office role
- **WHEN** the administrator enters an own Account with role user
- **THEN** tenant writes remain forbidden rather than gain an effective admin role

#### Scenario: Numeric search and stale query
- **WHEN** numeric Account search is issued and an older name query later returns
- **THEN** detail is requested only once and the latest search remains displayed without downloading all Account pages

#### Scenario: Focus after ordinary member switching
- **WHEN** a shared-session tab detects a changed real selected Account on focus
- **THEN** previous Account-bound state is cleared before navigation continues with the refreshed real identity

### Requirement: Read-only support audit browsing
GET `/api/v1/admin/support/logs` SHALL require Super Admin and expose existing stored rows ordered ID DESC, with positive bounded page validation, optional existing positive Account ID filter and preserved meta.current_page resource pagination. Stored rows SHALL remain untouched. Metadata SHALL be restricted to explicit safe descriptors: resource as a known short identifier and resource_id as a positive integer; additional scalar action descriptors SHALL require an inventoried safe writer justification. Arbitrary nested payloads and harmless-key secret values SHALL be dropped, not accepted through a key-name blacklist. Nested actor SHALL contain only id/name/email and Account only id/name/status, except demonstrably safe compatibility fields explicitly justified; tenant settings/certificates/activation material SHALL never appear. Safe action/time/trusted-IP fields SHALL remain. The interface SHALL retain loading/retry/empty states, Account filtering, columns/count/pagination and latest-request protection. Summary SHALL reuse the safe row projection with its separately specified timestamp DESC, ID DESC top-five order. Browsing SHALL not claim ordinary switching currently generates foreign enter/exit or write lifecycle events.

#### Scenario: Filter existing Account audit
- **WHEN** an administrator supplies an existing Account ID
- **THEN** the response contains only matching stored audit events in descending ID order

#### Scenario: No stored events
- **WHEN** the authorized audit query matches no rows
- **THEN** the page shows an empty result without claiming support operations took place

#### Scenario: Secret under harmless key
- **WHEN** stored metadata includes a token under an arbitrary benign name or nested Account settings
- **THEN** the reader omits it and returns only explicitly safe descriptors and minimal nested projections without rewriting the stored event

#### Scenario: Invalid audit pagination
- **WHEN** page or Account filter is nonpositive, unbounded or invalid
- **THEN** the API returns validation errors rather than silently broadening the query
