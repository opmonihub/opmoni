## Purpose

Provide the platform administrator with a complete, protected administrative workspace and real global summary and identity data, independent of operational Account membership.

## ADDED Requirements

### Requirement: Seven-page Super Admin navigation
The system SHALL expose Resumo (`/admin`), Contas (`/admin/contas`), Planos (`/admin/planos`), Assinaturas (`/admin/assinaturas`), Usuários (`/admin/usuarios`), Suporte (`/admin/suporte`), and Serpro (`/admin/serpro`) under a Super Admin submenu in this order. A shared panel SHALL provide the current page title, navigation tabs, and one nested page body. The submenu and administrative search destinations SHALL be visible only to Super Admin. The summary SHALL match its route exactly; child pages SHALL keep the parent active. Selecting a child SHALL close mobile navigation. Expanded, collapsed/popover, desktop, and mobile navigation SHALL reach the same routes.

#### Scenario: Navigate from Account administration
- **WHEN** a Super Admin opens `/admin/contas` and selects Planos from the submenu
- **THEN** `/admin/planos` renders inside the shared administrative panel with Planos active and no duplicated panel or navbar

#### Scenario: Collapsed sidebar and mobile selection
- **WHEN** the sidebar is collapsed or the mobile menu is open
- **THEN** all seven destinations remain reachable and selecting a mobile destination closes the menu

### Requirement: Platform authorization independent of tenant selection
All administrative APIs and support/credential APIs SHALL require an authenticated session and current Super Admin authorization on the server. Unauthenticated calls SHALL return 401, authenticated non-Super Admin calls SHALL return 403 without platform data, and invalid write origins/CSRF SHALL be rejected by the existing access contract. Super Admin SHALL be able to use every Admin page without an active membership, including after their own office is suspended. Direct navigation and focus refresh SHALL preserve that access. An ordinary user visiting an Admin route SHALL receive an access-denied view with a return action; unauthenticated visitors SHALL follow the existing login/onboarding rules. Responses SHALL be non-cacheable.

#### Scenario: Super Admin without an office
- **WHEN** a Super Admin with no active memberships visits `/admin/usuarios` and refreshes window focus
- **THEN** the administrative page remains accessible and is not redirected to unavailable operational access

#### Scenario: Direct unauthorized API request
- **WHEN** an ordinary Account admin requests a platform list or submits an administrative mutation
- **THEN** the server returns 403 without data or mutation regardless of frontend visibility

### Requirement: Stable platform API contracts
New endpoints SHALL use `/api/v1` through the existing application ingress and session transport. New paginated lists SHALL return `{data,meta:{page,per_page,total,last_page}}` with 15 records per page, positive page validation, and descending ID order. Existing Account lists SHALL retain `meta.page`, `meta.per_page`, and `meta.total` and add `last_page`. New detail and mutation resources SHALL use `{data:resource}` unless preserving an existing response contract. Validation SHALL return field errors with 422; unknown authorized resource IDs SHALL return 404. Identity SHALL preserve its existing top-level envelope. Lists SHALL trim search text, bound it to 255 characters, validate documented filters, and treat wildcard characters as literal user input.

#### Scenario: List validation and pagination
- **WHEN** an administrator submits a negative page, unsupported status, or overlength search
- **THEN** the API returns 422 instead of silently ignoring invalid filters

### Requirement: Real global summary
`GET /api/v1/admin/summary` SHALL return `{data:{totals:{accounts,plans,subscriptions,users},recent_support_logs}}`, containing unfiltered platform counts and the five most recent support events ordered by timestamp and ID descending. `/admin` SHALL render four linked metric cards and an audit table with date, Super Admin, action, and Account columns. The table SHALL allow column visibility changes and distinguish no audit events from failed loading.

#### Scenario: Populated summary
- **WHEN** a Super Admin loads the summary
- **THEN** counts reflect persisted platform data, each metric links to its respective list, and no more than five recent audit events are displayed

### Requirement: Read-only global user catalog
`GET /api/v1/admin/users` SHALL support `page`, `q` matching name/email, and `type=super|user` with omission meaning all. It SHALL return each user's ID, name, email, `is_super_admin`, and `account_links` with role and Account ID/name/status. It SHALL never expose password hashes, remember tokens, activation material, or session identifiers. `/admin/usuarios` SHALL show ID, name/email, type, and Account-role badges, with search, type filtering, column visibility, count, and pagination. No creation, update, deletion, or promotion operation SHALL be introduced by this page.

#### Scenario: Users with multiple memberships
- **WHEN** a matching user belongs to multiple Accounts
- **THEN** the list shows each real Account-role link and distinguishes the global Super Admin flag from membership roles

### Requirement: Reliable administrative screen states
Every page SHALL distinguish initial loading, initial failure with retry, successful empty results, and successful data. A refresh failure SHALL preserve the last successful result and report the failure. Failed summary loading SHALL not render zero counts as successful data. Search/filter changes SHALL reset pagination, stale responses SHALL not replace newer results, and a list refresh SHALL recover an out-of-range page. Mutations SHALL show busy/field-error states and prevent duplicate submissions. UI labels and feedback SHALL be in Brazilian Portuguese and preserve the mapped Nuxt UI presentation.

#### Scenario: Older search returns last
- **WHEN** a slower response for an older search arrives after the latest search result
- **THEN** the latest search result remains displayed

#### Scenario: Refresh fails after successful loading
- **WHEN** refreshing a populated administrative list fails
- **THEN** the previous data remains visible with explicit failure feedback and a retry action
