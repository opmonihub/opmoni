## MODIFIED Requirements

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

## ADDED Requirements

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
