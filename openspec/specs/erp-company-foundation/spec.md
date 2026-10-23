# erp-company-foundation Specification

## Purpose

Provide company-tenanted ERP access and explicit accounting-office delegation within the existing identity, session and application boundary.

## Requirements

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

The expanded sidebar SHALL contain all authorized context controls without clipping them or overlapping navigation, including identities with both office and company access. Collapsed and mobile navigation SHALL preserve access to the same authorized destinations. ERP pages SHALL retain one page header and use the established dashboard's page gutter, filter alignment, semantic theme, and native control sizes. Internal migration paths and development-change identifiers SHALL NOT be shown as business guidance.

#### Scenario: Company-only server render
- **WHEN** a valid company-only session directly reloads an ERP page
- **THEN** SSR renders only that authorized company and does not redirect into an office loop or expose another session

#### Scenario: Late office result after company switch
- **WHEN** an office request resolves after the user entered a company workspace
- **THEN** its results are discarded and cannot appear as company data

#### Scenario: Office and company controls share the expanded sidebar
- **WHEN** an authorized user with office and company memberships opens the expanded ERP sidebar
- **THEN** both context controls remain inside its visible header area and neither control overlaps the first navigation destination

#### Scenario: Responsive page alignment
- **WHEN** an ERP catalog or settings page is opened at narrow and desktop widths
- **THEN** its content has one outer page gutter, related filters and table frames align, and its header, navigation, and supported actions remain reachable

#### Scenario: Product-facing settings guidance
- **WHEN** a user opens an ERP settings section
- **THEN** descriptions explain the supported business task in Brazilian Portuguese and do not expose template filenames, source partial paths, or internal change numbers

### Requirement: Shared ERP mutation and decimal contracts are consistent
New ERP JSON resources SHALL use data envelopes and paginated lists SHALL expose meta.current_page, last_page, per_page and total. Money and fractional quantities SHALL be lossless decimal strings. Field validation SHALL return 422; conflicts SHALL return 409; protected responses SHALL be non-cacheable. Posted business and external-effect actions SHALL bind an Idempotency-Key to company, actor, operation and canonical payload; repeated equal requests SHALL yield the original outcome and unequal payload reuse SHALL return 409. Office API envelopes SHALL remain unchanged.

#### Scenario: Duplicate financial operation
- **WHEN** the same authorized actor repeats an equal posting request with the same key
- **THEN** the original effective result is returned without a second stock, money or provider effect

#### Scenario: Reused key changes its payload
- **WHEN** an actor reuses a posting key with a different canonical payload
- **THEN** the API returns 409 without changing the previously accepted result

### Requirement: ERP lists preserve authoritative state in a consistent presentation
ERP operational and catalog lists SHALL share the established table-header, row-rule, filter, and counted-footer presentation where the same list role applies. Specialized import, print, report, and accounting-detail structures SHALL retain their supported behavior. Pagination SHALL use confirmed server metadata and existing server queries rather than paginate or total only the current client page. A page render SHALL NOT itself trigger a page change. Loading SHALL prevent duplicate page transitions.

Wide populated tables SHALL remain horizontally accessible inside their frame without forcing whole-page horizontal overflow. Confirmed empty-state guidance SHALL fit the visible frame without requiring horizontal scrolling. Unknown, loading, failed, stale, and confirmed-empty reads SHALL remain distinguishable; service failures SHALL NOT be presented as zero records.

#### Scenario: Server-paginated list changes page
- **WHEN** a user chooses a valid page in a confirmed, idle list
- **THEN** one existing server-page request is admitted with the selected page and current filters, and the footer uses server totals and page size

#### Scenario: Paging while loading
- **WHEN** the current list request is pending or the requested page is outside its confirmed page range
- **THEN** pagination does not admit a duplicate or invalid transition and does not clear confirmed records

#### Scenario: Empty finance list on mobile
- **WHEN** a narrow-screen finance query succeeds with zero matching records
- **THEN** the full empty-state explanation is readable in the visible frame without horizontal scrolling

#### Scenario: Failed list refresh
- **WHEN** a list refresh fails after an earlier confirmed read
- **THEN** the user sees an actionable failure and any retained records are identified as previously confirmed data rather than a new successful response

### Requirement: ERP settings feedback preserves authority and reference compatibility
Settings validation failures SHALL identify rejected named fields while preserving the user's entered values. Network or form-level failures SHALL remain visible. Duplicate submissions SHALL remain prevented, and late results SHALL NOT display field data, errors, or success feedback after the requesting Company, Account, session, or authorization context changes. Read-only users SHALL retain consultation without gaining save actions.

Settings reference controls SHALL preserve stored identifiers, supported editing, and unavailable selections. A labeled selector SHALL be introduced only when its source and identifier namespace are verified for that exact field. Unknown legacy identifiers SHALL NOT be mapped to same-number native records, replaced with a default, or cleared because a lookup failed. An unresolved reference-contract dependency SHALL remain explicitly reported rather than be presented as a completed migration.

#### Scenario: Current-context field validation failure
- **WHEN** a current authorized settings save returns validation errors for named fields
- **THEN** those fields show the corresponding errors, entered values remain available for correction, and no success feedback appears

#### Scenario: Authority changes during settings save
- **WHEN** a settings response arrives after Company, Account, session, or effective write authority changed
- **THEN** it cannot repopulate the new context or show stale validation or success feedback, and current read-only restrictions remain effective

#### Scenario: Legacy reference has no verified label source
- **WHEN** a settings field contains a legacy identifier and no compatible lookup contract is established
- **THEN** the existing identifier and supported edit behavior remain unchanged, no native record is inferred from that number, and the missing contract remains a tracked limitation

### Requirement: ERP decimal display preserves exact business values
Read-only catalog decimal values SHALL use Brazilian Portuguese grouping and decimal separators without altering their stored or submitted values. Formatting SHALL preserve meaningful fractional digits and values beyond the safe integer range; it SHALL NOT round a decimal string by converting it to a floating-point number. Empty values SHALL be distinguishable from zero, and non-canonical values SHALL retain their supplied display rather than become fabricated numeric amounts. A currency symbol SHALL only be added when its currency context is known.

#### Scenario: Price and exact fractional value
- **WHEN** the catalog displays `12.5000` as a price and `12345678901234567890.1234` as an exact decimal
- **THEN** the user sees `12,50` and `12.345.678.901.234.567.890,1234`, while editing or submitting retains the original canonical decimal strings

#### Scenario: Missing decimal value
- **WHEN** a read-only decimal value is absent
- **THEN** it is displayed as unavailable rather than a fabricated zero amount
