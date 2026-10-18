## Purpose

Provide company-tenanted ERP access and explicit accounting-office delegation within the existing identity, session and application boundary.

## ADDED Requirements

### Requirement: Companies and establishments are independent operating tenants
The system SHALL represent Company separately from accounting-office Account and office Client. Each establishment SHALL belong to one company; company memberships SHALL reference existing global identities and be unique per user/company. Matching an email or CNPJ SHALL NOT grant access or overwrite an existing identity. Company setup SHALL preserve existing office identities, memberships, clients, subscriptions and activation links.

#### Scenario: Company-only identity
- **WHEN** a company owner has no office membership
- **THEN** the owner can enter an authorized company without creating a dummy office or changing office membership

#### Scenario: Foreign establishment
- **WHEN** an authorized company operation references an establishment from another company
- **THEN** the API returns 422 without reading protected foreign details or changing either company

### Requirement: Company permissions are checked on every operating action
Company reads and writes SHALL require an authenticated identity, active company and current direct membership or valid explicit office delegation. Company roles and granular action permissions SHALL be enforced server-side; viewer/read-only access SHALL not mutate. Owner-only membership, ownership and delegation actions SHALL not become ordinary operating permissions. Platform administration SHALL NOT grant implicit company operating access. Foreign resources SHALL return 404 and foreign related identifiers SHALL return 422.

#### Scenario: Revoked direct membership
- **WHEN** a user submits an operation after their company membership was removed
- **THEN** the operation returns 403 even when a stale interface still displays the old role

#### Scenario: Platform administrator has no company authorization
- **WHEN** a Super Admin requests company operating data without membership or valid delegation
- **THEN** the request returns 403 and no protected company data

### Requirement: One office delegation is explicit and revocable
A company owner SHALL explicitly authorize at most one active accounting-office delegation, with a company-selected permission allowlist and read-only default. Delegated operations SHALL additionally require an active office and current real office membership; read-only office members SHALL not mutate. Delegation SHALL exclude company ownership, membership and subscription administration. Company, actor and delegated Account SHALL remain attributable in audits. Replacing/revoking delegation SHALL immediately block subsequent operations and queued effects for the former office without deleting valid previously delivered history.

#### Scenario: Delegation replacement
- **WHEN** two requests attempt to authorize different offices for the same company
- **THEN** at most one active delegation remains and no request creates an unrestricted office or platform bypass

#### Scenario: Revoked office has queued work
- **WHEN** a queued delegated action executes after its delegation was revoked
- **THEN** it is denied before an external or business effect and the former office cannot resume it using cached permission

### Requirement: Company selection is session-local and asserted by the client
POST `/api/v1/erp/company/switch` SHALL select only a company authorized for the current identity and explicit office context where delegated. Company-scoped calls SHALL include X-Company-Id matching the session selection; missing assertion SHALL return 422 and mismatch SHALL return 409 `company_context_changed`. Identity, company listing/selection, guarded registration/creation and platform endpoints SHALL use explicit access/setup guards without requiring an already selected company. Payload company_id/account_id fields SHALL not select context and SHALL return 422 on ordinary company mutations. Delegated selection SHALL bind its authorized office/delegation; changing office SHALL invalidate that delegated company selection. A successful switch SHALL clear stale protected UI state and refresh CSRF without extending the authenticated deadline. Failed switches SHALL preserve the previous authorized selection.

#### Scenario: Another tab changed company
- **WHEN** a tab displaying company A submits after another tab selected company B
- **THEN** the stale request receives 409 and performs no operation in B

#### Scenario: Switch fails
- **WHEN** selection is denied or its session write fails
- **THEN** the prior selected company and view remain intact and the user receives retryable feedback

#### Scenario: First company selection
- **WHEN** an authenticated user with no selected company submits the guarded company-switch request for an authorized membership
- **THEN** selection succeeds without requiring a previous X-Company-Id and subsequent operating requests require the new company assertion

#### Scenario: Office changes during delegated company use
- **WHEN** a user selects another office while a tab retains delegated ERP context from the former office
- **THEN** the delegated company selection is invalidated and the stale ERP tab cannot operate under either office until authorized context is selected again

### Requirement: ERP shell shares the established presentation and session
The ERP SHALL use `/erp/*` within the existing Nuxt application and existing theme/components, with company-aware navigation and action guards. The context menu SHALL distinguish Offices from Companies and show only authorized destinations. Company-only users SHALL enter ERP after login; users with active office memberships SHALL retain default-office entry. SSR and late responses SHALL remain bound to the requesting session/domain/tenant; failed loads SHALL not present demonstration records or fabricated zero metrics.

#### Scenario: Company-only server render
- **WHEN** a valid company-only session directly reloads an ERP page
- **THEN** SSR renders only that authorized company and does not redirect into an office loop or expose another session

#### Scenario: Late office result after company switch
- **WHEN** an office request resolves after the user entered a company workspace
- **THEN** its results are discarded and cannot appear as company data

### Requirement: Shared ERP mutation and decimal contracts are consistent
New ERP JSON resources SHALL use data envelopes and paginated lists SHALL expose meta.current_page, last_page, per_page and total. Money and fractional quantities SHALL be lossless decimal strings. Field validation SHALL return 422; conflicts SHALL return 409; protected responses SHALL be non-cacheable. Posted business and external-effect actions SHALL bind an Idempotency-Key to company, actor, operation and canonical payload; repeated equal requests SHALL yield the original outcome and unequal payload reuse SHALL return 409. Office API envelopes SHALL remain unchanged.

#### Scenario: Duplicate financial operation
- **WHEN** the same authorized actor repeats an equal posting request with the same key
- **THEN** the original effective result is returned without a second stock, money or provider effect

#### Scenario: Reused key changes its payload
- **WHEN** an actor reuses a posting key with a different canonical payload
- **THEN** the API returns 409 without changing the previously accepted result
