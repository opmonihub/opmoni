# platform-administration Specification

## Purpose

Provide a protected seven-page administrative workspace and global read data without requiring operational office membership.

## Requirements

### Requirement: Seven-page Super Admin workspace
Only Super Admin SHALL access Resumo `/admin`, Contas `/admin/contas`, Planos `/admin/planos`, Assinaturas `/admin/assinaturas`, Usuários `/admin/usuarios`, Suporte `/admin/suporte`, and Serpro `/admin/serpro`. They SHALL share one administrative shell/navigation; ordinary users SHALL receive an access-denied destination. Platform navigation SHALL remain available without an active own Account.

#### Scenario: No active office
- **WHEN** a Super Admin without an active membership visits an Admin page
- **THEN** platform navigation remains authorized while tenant business operations remain blocked

### Requirement: Platform APIs preserve existing response contracts
Platform reads/writes SHALL require authentication and their explicit server-side authorization; browser mutations SHALL retain origin/CSRF checks and protected responses SHALL be non-cacheable. Account lists SHALL retain `meta.page`; resource-paginated users, subscriptions, and support logs SHALL retain `meta.current_page`; plans SHALL retain their array response. The frontend SHALL normalize these existing shapes rather than assume a newly implemented uniform envelope.

#### Scenario: Ordinary member requests global users
- **WHEN** an authenticated non-Super Admin calls `/api/v1/admin/users`
- **THEN** the API returns 403 without global identity data

#### Scenario: Invalid list filters
- **WHEN** a user/subscription query provides an invalid documented filter
- **THEN** the request returns 422 rather than silently ignoring it

### Requirement: Summary uses persisted global data
The summary SHALL derive real Account, user, subscription, and plan totals from their existing list APIs and show up to five available recent audit rows. It SHALL not depend on an absent `/admin/summary` endpoint. Initial failure SHALL offer retry rather than successful zero counts; refresh failure SHALL retain the last successful result.

#### Scenario: Summary refresh fails
- **WHEN** one summary query fails after a successful load
- **THEN** the previous summary remains visible with failure feedback

### Requirement: Global user catalog is read only
`GET /api/v1/admin/users` SHALL provide paginated name/email search and `type=super|user` filtering with real Account-role links. It SHALL not expose password hashes, session identifiers, or activation secrets, and the Admin page SHALL not provide user creation, credential reset, promotion, or deletion actions.

#### Scenario: Multiple real memberships
- **WHEN** a listed user belongs to multiple Accounts
- **THEN** the resource shows the real links and roles independently of the global Super Admin flag
