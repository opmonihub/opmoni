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

### Requirement: ERP visual roles match the shared dashboard baseline
Equivalent ERP and office dashboard surfaces SHALL use the same semantic color roles, typography, native control proportions and comparable page-family spacing in light and dark themes. ERP pages SHALL have one outer gutter and a concise page identity; task headings and help SHALL add meaningful information rather than repeat navigation. A necessary contrast correction SHALL be measured and reusable for the equivalent semantic role instead of creating unexplained route-dependent appearance. Teleported editors and navigation between office and company areas SHALL retain the correct shared theme.

#### Scenario: Equivalent office and company surfaces
- **WHEN** equivalent controls, navigation and settings forms are opened with the same viewport, theme and root scale
- **THEN** their semantic roles, control proportions and hierarchy match, with any content-driven exception documented and no duplicate outer padding

#### Scenario: Theme follows an overlay and workspace transition
- **WHEN** an operator opens an ERP editor and then returns to an office page in either theme
- **THEN** the editor and resulting page use the shared semantic roles without a stale ERP-only palette or invisible focus state

### Requirement: Authorized destinations agree across navigation surfaces
ERP sidebar, search, local navigation and section-root resolution SHALL expose a coherent set of currently authorized destinations. Local settings/catalog navigation SHALL remain available within child pages and expose direct destinations without requiring a redundant category disclosure; narrow layouts SHALL keep these links reachable without unbounded wrapped rows. Navigation to a destination SHALL select its actual body area and applicable fiscal model. Root entry SHALL select a permitted child, while an explicitly denied child SHALL retain access-denied behavior. Missing or failed reads SHALL not imply permission or invent a destination.

#### Scenario: Fiscal destination selects its area and model
- **WHEN** an authorized operator follows Certificados, Eventos or a model-specific Fiscal destination through a link, reload or history
- **THEN** the matching area/model is selected and the list/create context agrees with that destination rather than resetting to NF-e documents

#### Scenario: Only cost-layer access is granted
- **WHEN** the current grants permit the existing stock cost-layer destination and no other stock area
- **THEN** sidebar, search and local navigation expose that destination and section entry selects it without granting another stock area

#### Scenario: Settings root has a restricted permitted child
- **WHEN** an operator can read establishments but cannot read company settings and enters the settings root
- **THEN** entry resolves to an authorized child rather than forcing the denied company form

#### Scenario: Company or grants change during navigation loading
- **WHEN** a destination registry or late response belongs to a former Company, delegated Account or authorization context
- **THEN** it is discarded and cannot restore inaccessible destinations or protected content in the current context

### Requirement: ERP sidebar permits one fully expanded group
At most one sidebar group SHALL fully disclose its children across all ERP menu regions. Opening another group SHALL close the preceding full disclosure. The current destination SHALL remain identifiable through the established pinned-active-child behavior when its group is folded. Manual folding SHALL survive navigation within the same owning module, including hash changes; entering another module SHALL open that permitted owner. Leaf destinations, revoked permissions and context changes SHALL clear stale expansion. Parent disclosure SHALL retain keyboard/focus behavior and SHALL not itself navigate or close the mobile drawer.

#### Scenario: Open groups across both menu regions
- **WHEN** an operator expands Vendas, then Compras, then Configurações
- **THEN** only Configurações fully exposes its children and any pinned current destination does not expose its siblings as a second expanded group

#### Scenario: Manual folding within the same module
- **WHEN** an operator folds the active group and changes a child or hash within that module
- **THEN** the fold remains respected while the current destination stays identifiable

#### Scenario: Navigate through mobile disclosure
- **WHEN** an operator expands a parent in the mobile drawer and then selects a child
- **THEN** expansion retains the drawer and keyboard focus, while destination selection performs the established close/focus-return behavior

### Requirement: ERP settings and administration expose coherent tasks
Settings SHALL group fields by meaningful task, use value types appropriate to their contracts and preserve named validation errors and unsaved values. Unrelated same-company card updates and query/hash changes that do not change the editor entity SHALL NOT overwrite dirty fields or recreate the editor. Confirmed saves SHALL reconcile their own fields, while conflicting updates to dirty fields SHALL retain the draft for explicit review. Changed Company, delegated Account, session or effective authority SHALL retain existing private-state invalidation and stale-response protection. Fiscal classification codes SHALL remain strings, including leading zeroes; rates SHALL retain exact decimal semantics. Unknown legacy values SHALL remain visible until an explicit compatible migration or correction. Administration and operational editors SHALL open on deliberate actions, use reachable primary/cancel controls and expose detailed grants on demand without changing owner-only or delegated access restrictions. Descriptions SHALL use concise Brazilian Portuguese and retain warnings that explain actual consequences.

#### Scenario: Save leading-zero tax defaults
- **WHEN** an authorized operator saves defaults such as CST `00`, PIS `01` or CSOSN `101`
- **THEN** save/reload preserves the string code and rejected values remain editable with field feedback rather than being coerced to numbers

#### Scenario: Existing numeric legacy setting is ambiguous
- **WHEN** a stored classification cannot be unambiguously interpreted under the current regime and code contract
- **THEN** it remains visible as requiring review and is not silently padded, reclassified or supplied to an operation as a confirmed code

#### Scenario: Read-only administration and failed save
- **WHEN** an operator has consultation only or a permitted edit fails
- **THEN** the interface retains consultation or entered corrections respectively, does not offer an unauthorized mutation, and never changes owner protections or shows fabricated success

#### Scenario: Tax-profile save preserves company-profile edits
- **WHEN** the operator edits company fields without saving and successfully saves a default product tax profile in the adjacent card
- **THEN** the tax profile updates without replacing the unsaved company fields

#### Scenario: Non-entity URL change preserves the editor
- **WHEN** only query or hash changes within the same company settings editor
- **THEN** the unchanged editor remains mounted with its existing unsaved fields, while entity or authority changes still invalidate the old private state

### Requirement: Operational workspaces initialize each logical read once
Opening a company operational workspace SHALL select its authorized requested area and initialize each required list and reference dataset at most once for that entry. Component mounting and destination selection SHALL NOT both request the same logical read. Later explicit refreshes and changes of area, filters or authority SHALL retain their established reads and permission checks.

#### Scenario: Open purchases directly
- **WHEN** an authorized user enters Compras with no hash or with the returns hash
- **THEN** the permitted destination is selected and each required purchases, returns or reference dataset is initialized once for that entry

#### Scenario: Open sales through a saved area link
- **WHEN** an authorized user enters Vendas with a valid area hash
- **THEN** the matching authorized area and necessary reference data load once without first loading a redundant default-area dataset

### Requirement: Administrative provisioning separates subscription from owner access
An eligible office administrator SHALL be able to provision a new Company for an active portfolio company Client without inventing an owner identity. The Company SHALL remain explicitly awaiting verified owner setup until its intended responsible person creates or authenticates their identity using a revocable, single-use setup continuation. Subscription assignment SHALL grant neither direct Company membership to the office administrator nor implicit accounting delegation. A Company with an existing owner SHALL retain that owner and require the owner's explicit authorization before a new office association. Pending owner setup SHALL not expose operating Company data to the office. Setup SHALL be recoverable without repeating subscription activation, invoices or trial allocation.

#### Scenario: Office admin attempts to operate a newly provisioned Company
- **WHEN** an office admin has only commercial administration and no direct Company membership or explicit delegation
- **THEN** Company selection and operating access are denied even when its subscription is active

#### Scenario: Verified owner completes access setup
- **WHEN** the intended responsible person completes valid one-time setup for an unclaimed office-provisioned Company
- **THEN** that identity becomes its owner without creating an office Account, changing the assigned subscription or issuing another invoice

#### Scenario: Unrelated identity attempts owner setup
- **WHEN** an identity different from the intended responsible person attempts setup or a consumed continuation is reused by a foreign actor
- **THEN** access is denied without revealing or changing Company ownership
