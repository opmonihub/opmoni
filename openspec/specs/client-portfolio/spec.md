# client-portfolio Specification

## Purpose

Manage the selected accounting office's real client portfolio, with safe registration, filtering, selection, and operational actions.

## Requirements

### Requirement: Tenant client CRUD and validation
The system SHALL provide client listing, detail, creation, update, and soft deletion under `/api/v1/clients`. Only current Account members SHALL read; only `admin` and `operador` SHALL write through those tenant CRUD routes. The separately authorized office-specific ERP trial registration SHALL have a narrow capability to create or explicitly reuse its sponsoring office's company Client within its atomic registration transaction; it SHALL grant no portfolio reading, general CRUD access or Account membership. CPF/CNPJ, person type, status, and tax regime SHALL be validated; individual clients SHALL use the not-applicable regime and SHALL NOT be enrolled as ERP Companies. Foreign client IDs SHALL return 404 and foreign related IDs SHALL be rejected. New Client creation, including trial registration, SHALL enforce the office client limit.

#### Scenario: Read-only member attempts creation
- **WHEN** a member with role `user` submits a client
- **THEN** the API returns 403 and creates no client

#### Scenario: Invalid registration
- **WHEN** a client payload has an invalid tax identifier or incompatible individual regime
- **THEN** the API returns 422 without partial creation

#### Scenario: Foreign client
- **WHEN** an Account member requests another office's client
- **THEN** the API returns 404 without exposing that client

#### Scenario: Existing portfolio Client enters an ERP trial
- **WHEN** a verified owner completes an office-linked registration whose CNPJ already matches an active company Client in that office
- **THEN** the existing Client is reused after explicit association confirmation without overwriting contact, fiscal, monitoring or certificate data

#### Scenario: Public trial supplies a foreign Client identifier
- **WHEN** a public signup includes a portfolio client identifier or office identifier instead of the server-resolved trial-link attribution
- **THEN** the request is rejected before any client or Company association and no foreign portfolio details are disclosed

### Requirement: Portfolio filters and real aggregates
Portfolio listing SHALL paginate within the selected Account and apply supported filters before pagination. Summary and analytics SHALL describe that Account's stored clients. Tags, tag assignment, saved filters, and client monitoring-module associations SHALL remain Account-scoped; related foreign identifiers SHALL not authorize cross-Account changes.

#### Scenario: Filtered empty portfolio
- **WHEN** a valid filter matches no clients
- **THEN** the response is a successful empty result with zero matching records

#### Scenario: Foreign tag assignment
- **WHEN** a write references a tag from another Account
- **THEN** validation rejects the relationship without changing the client

### Requirement: Selection snapshots and bulk deletion
Explicit and filtered client selections SHALL remain bound to their initiating user and Account. Filter-wide selection SHALL snapshot the selected clients and preserve exclusions rather than include later additions. Bulk deletion SHALL return a trackable operation and perform only authorized selected deletions in the original Account.

#### Scenario: Later client after selection
- **WHEN** a client is added after a filter-wide selection snapshot
- **THEN** it is not silently added to the earlier deletion set

#### Scenario: Selection reuse across offices
- **WHEN** a user attempts to reuse another user's or Account's selection
- **THEN** the operation is rejected without deleting foreign clients

### Requirement: CNPJ lookup and refresh failures
CNPJ lookup and refresh preview/application SHALL require an operational writer and preserve tax identifier normalization, including supported alphabetic characters. Lookup SHALL be rate limited. Provider failure SHALL be reported without applying an unconfirmed refresh or presenting fabricated company data.

#### Scenario: Company lookup fails
- **WHEN** the provider cannot complete the requested lookup
- **THEN** the API returns failure feedback and existing registration data remains available
