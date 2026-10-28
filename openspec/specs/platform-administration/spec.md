# platform-administration Specification

## Purpose

Provide a protected seven-page administrative workspace and global read data without requiring operational office membership.

## Requirements

### Requirement: Seven-page Super Admin workspace
Only Super Admin SHALL access Resumo `/admin`, Contas `/admin/contas`, Planos `/admin/planos`, Assinaturas `/admin/assinaturas`, Usuários `/admin/usuarios`, Suporte `/admin/suporte`, and Serpro `/admin/serpro`. They SHALL share one administrative shell with one panel/navbar, matching title/tabs, and no Account-only wrapper. Ordinary users SHALL receive an access-denied destination. Platform navigation SHALL remain available without an active own Account, including a suspended own office and direct navigation/focus refresh, without redirect loops. Privilege loss SHALL be revalidated. Navigation SHALL preserve the current nine destinations and order: Resumo, Contas, Planos, Assinaturas, Planos ERP, Assinaturas ERP, Usuários, Suporte, Serpro. Summary matches exactly, child pages keep the Admin parent active, and collapsed/search/mobile navigation SHALL reach the same destinations with mobile selection closing the menu. No tenant destination or role SHALL be broadened.

#### Scenario: No active office
- **WHEN** a Super Admin without an active membership visits an Admin page
- **THEN** platform navigation remains authorized while tenant business operations remain blocked

#### Scenario: Shared Account shell
- **WHEN** a Super Admin directly opens Contas and navigates through the seven original pages
- **THEN** each renders one matching panel/title/tabs and both existing ERP destinations remain available in their current positions

#### Scenario: Collapsed and mobile navigation
- **WHEN** the administrator selects a destination through collapsed sidebar, search or mobile navigation
- **THEN** the correct page becomes active and mobile selection closes the navigation

#### Scenario: Focus after privilege change
- **WHEN** current authorization changes before focus refresh
- **THEN** guards enforce the refreshed privilege without a loop or implicit tenant access

### Requirement: Platform APIs preserve existing response contracts
Platform reads/writes SHALL require authentication and explicit server-side authorization; browser mutations SHALL retain origin/CSRF checks and protected responses SHALL be non-cacheable. Account lists SHALL retain `{data,meta:{page,per_page,total}}` and add `last_page`; resource-paginated users, subscriptions and support logs SHALL retain `meta.current_page`; Plan lists SHALL remain raw arrays and Plan detail/mutation SHALL retain raw resources. Identity SHALL remain top-level, Account create SHALL retain `{data,first_access}` and reissue SHALL retain `{first_access}`. The frontend SHALL use shared types/adapters for these distinct shapes. New detail/summary/platform-vault endpoints SHALL require Super Admin independently of tenant selection; existing GET `/api/v1/serpro/connection` SHALL retain its selected-active-member read exception. Unauthenticated calls SHALL return 401, forbidden calls 403, unknown authorized IDs 404, and invalid input 422 with field errors. Lists SHALL validate positive bounded pages, trim/bound search to 255 characters, treat wildcard characters literally, retain deterministic ordering and eagerly load required safe relations.

#### Scenario: Ordinary member requests global users
- **WHEN** an authenticated non-Super Admin calls `/api/v1/admin/users`
- **THEN** the API returns 403 without global identity data

#### Scenario: Invalid list filters
- **WHEN** a user/subscription query provides an invalid documented filter
- **THEN** the request returns 422 rather than silently ignoring it

#### Scenario: Existing envelope compatibility
- **WHEN** callers list Plans, Accounts, users, subscriptions or logs and issue/reissue owner access
- **THEN** each existing raw/resource/pagination/issuance shape remains intact and only Account last_page is added

#### Scenario: Selected member metadata exception
- **WHEN** a non-Super Admin member of the currently selected active Account reads connection metadata
- **THEN** the existing safe read remains permitted without granting new platform endpoints or credential writes

### Requirement: Summary uses persisted global data
GET `/api/v1/admin/summary` SHALL return `{data:{totals:{accounts,plans,subscriptions,users},recent_support_logs}}` using persisted global counts and at most five safe stored audit rows ordered timestamp DESC then ID DESC. The Admin summary SHALL consume this implemented dedicated endpoint rather than compose list requests. Four linked metric cards and date/actor/action/Account columns with column visibility SHALL reflect the response. Initial failure SHALL offer retry rather than successful zero counts; refresh failure SHALL retain the last successful result. Empty history SHALL remain distinguishable from failed loading. Audit rows SHALL use the same safe projection as the log reader; this summary ordering SHALL not change the canonical audit-list ID DESC order.

#### Scenario: Summary refresh fails
- **WHEN** a summary request fails after a successful load
- **THEN** the previous summary remains visible with failure feedback

#### Scenario: Populated and empty summary
- **WHEN** a Super Admin requests the summary without tenant selection
- **THEN** persisted counts and up to five safely projected timestamp/ID-descending events are returned, and actual zero counts or empty events remain valid successful data

### Requirement: Global user catalog is read only
GET `/api/v1/admin/users` SHALL provide paginated name/email search and `type=super|user` filtering with real Account-role links. It SHALL not expose password hashes, session identifiers or activation secrets, and the Admin page SHALL not provide user creation, credential reset, promotion or deletion actions. The page SHALL show ID, name/email, global type, real Account-role badges, column controls, count and pagination.

#### Scenario: Multiple real memberships
- **WHEN** a listed user belongs to multiple Accounts
- **THEN** the resource shows the real links and roles independently of the global Super Admin flag

### Requirement: Reliable administrative screen states
All seven original Admin pages SHALL distinguish initial loading, retryable initial failure, successful empty and successful populated results. Refresh failures SHALL preserve prior success with explicit feedback. Search/filter changes SHALL reset pagination; stale responses SHALL not replace newer results, and refresh SHALL recover an out-of-range page. Mutations SHALL preserve field inputs on validation errors, show busy state and prevent duplicate submission. Components SHALL remain responsive and SSR-safe, and UI copy SHALL be Brazilian Portuguese.

#### Scenario: Older search completes last
- **WHEN** an older list query returns after the latest query
- **THEN** the latest result remains displayed

#### Scenario: Last page disappears
- **WHEN** refreshing a list finds the selected page beyond the available range
- **THEN** the page recovers to an available page without showing the stale page as current success

### Requirement: Administrative resource ordering preserves platform boundaries
Account, global-user and subscription resource lists SHALL support validated ascending/descending ordering by their documented visible identity/status fields where applicable, plus record identifier, before server pagination. Identifier tie-breaking SHALL make equal values deterministic. Existing no-sort ordering, raw/resource response shapes, fixed summary/audit history order and read-only global-user behavior SHALL remain compatible. Unsupported sort fields or directions SHALL return field errors, and Super Admin authorization SHALL remain independently enforced.

#### Scenario: Sort a paginated global user list
- **WHEN** a Super Admin selects name ordering for matching global users
- **THEN** all matching pages follow that ordering with stable equal-name ties, the existing envelope remains intact and no user-write action is introduced

#### Scenario: Preserve fixed audit and summary order
- **WHEN** the administrative shell is standardized and resource-list sorting is added
- **THEN** recent summary events and support audit rows retain their canonical ordering and safe projections

#### Scenario: Invalid ordering and unauthorized caller
- **WHEN** an authorized caller supplies unsupported ordering or an ordinary member requests the same global resource
- **THEN** the authorized invalid query receives 422 field errors and the ordinary member remains forbidden without global data
