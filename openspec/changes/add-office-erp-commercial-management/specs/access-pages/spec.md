## MODIFIED Requirements

### Requirement: Business navigation resolves the selected Account
The authenticated shell SHALL expose Clients, Fiscal, Monitoring, Work, Team, business Settings, and office ERP commercial management routes through the existing access/session transport. Their data SHALL come from the selected Account; demonstration home widgets and remaining sample inbox/settings handlers SHALL stay visibly distinct from integrated business functionality. Account switching/logout SHALL clear previous Account state before business navigation continues.

Commercial pages SHALL use `/gestao-erp` and its children inside the office shell, without a Company selection or a Super Admin-only route. Navigation and global search SHALL agree with server-confirmed read/maintenance/new-sale capabilities. Loss of selling eligibility SHALL preserve the maintenance entry for offices with commercial history, showing the reason new sales are unavailable. Old `/admin/erp/*` commercial links SHALL be removed from primary navigation; an old Super Admin bookmark SHALL redirect only when the current office is actually authorized, otherwise explain that an authorized office must be selected without choosing one implicitly. Ordinary users SHALL NOT gain access to the old privileged paths or APIs.

#### Scenario: Business page after office switch
- **WHEN** a member switches offices and opens the client portfolio
- **THEN** the displayed clients belong only to the newly selected office

#### Scenario: Ordinary office admin opens an ERP retail plan
- **WHEN** an authorized office administrator opens `/gestao-erp/planos` or finds it through global search
- **THEN** that Account's commercial workspace is reachable without Super Admin or a current Company

#### Scenario: Loss of new-sale eligibility leaves historical business
- **WHEN** an office with existing contracts loses resale eligibility
- **THEN** the maintenance navigation remains available and new-sale actions show the actual blocked capability

#### Scenario: Old admin bookmark has no authorized office
- **WHEN** a Super Admin without an authorized current office opens an old ERP administration bookmark
- **THEN** the interface explains the missing office context and does not silently select or disclose a tenant

## ADDED Requirements

### Requirement: Company owner invitations resolve before workspace selection
The ERP owner-invitation page SHALL support first access or authenticated acceptance without a selected office or Company, preserve an allowlisted local invitation continuation through login, and apply origin/CSRF, expiry, single-use and rate protections. It SHALL distinguish invalid/expired invitations from retryable service failure, require explicit review of seller and commercial terms, and keep raw tokens out of logs, persisted UI state, analytics and referrers. Normal login destinations SHALL remain unchanged outside this bounded invitation continuation. Acceptance SHALL not imply accounting access; any delegation SHALL require a separate explicit owner authorization.

#### Scenario: Intended owner has no memberships yet
- **WHEN** the authenticated intended owner returns from login to a valid invitation
- **THEN** the invitation can be accepted without an unavailable-workspace redirect loop or an initial tenant selection

#### Scenario: Invitation validation service fails
- **WHEN** the invitation cannot be validated because the service is unavailable
- **THEN** retryable failure is shown without declaring the invitation expired or rendering protected terms from another invitation
