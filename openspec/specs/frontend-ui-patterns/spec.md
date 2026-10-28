# frontend-ui-patterns Specification

## Purpose

Provide a consistent, accessible interface across office and company workflows through common page compositions, capable operational lists and predictable editors without weakening domain behavior or authority boundaries.

## Requirements

### Requirement: Equivalent tasks share a coherent interface composition
Equivalent list, settings, record-detail and reporting tasks SHALL share the established dashboard reference's heading, action, card, field, spacing and control hierarchy. Operational widths and scroll ownership SHALL remain appropriate to the task. Domain-specific exceptions SHALL preserve meaningful business information and existing light/dark contrast improvements rather than introduce an unrelated module-specific visual system.

#### Scenario: Move between equivalent list tasks
- **WHEN** an authorized operator moves between office, administrative and company resource lists
- **THEN** headings, search/filter controls, record actions and result feedback follow the same recognizable composition while the current authority and domain identity remain explicit

#### Scenario: A financial table needs more width than a preference form
- **WHEN** an operator compares financial columns and then edits a preference
- **THEN** each uses the common hierarchy with an appropriate content measure rather than forcing the table into the preference form's narrow reading width

### Requirement: Operational list controls describe their actual result scope
Operational lists SHALL expose useful supported search, filters, ordering and result navigation through coherent controls. Applied criteria, ordering direction and result scope SHALL be visible. A paginated or incrementally loaded subset SHALL NOT be presented as a globally ordered or filtered dataset unless the authoritative query applies those criteria before pagination. Unsupported capabilities SHALL not be represented by working-looking controls.

#### Scenario: Sort a server-paginated resource
- **WHEN** an operator selects a supported ordering field on a resource with more than one server page
- **THEN** the current authoritative query orders the matching result set before selecting the page and the control reflects that ordering

#### Scenario: A complete local directory is searched
- **WHEN** an operator searches a bounded directory whose authorized complete dataset is loaded
- **THEN** local search and ordering operate on that complete directory without suggesting a server-wide bulk operation

#### Scenario: A request fails after criteria change
- **WHEN** a newly ordered or filtered read fails after a previous successful read
- **THEN** the interface distinguishes failure from a successful empty result and labels any retained results as belonging to the previous confirmed query

### Requirement: Selection retains explicit record and authority ownership
Selection SHALL use stable domain identities and communicate whether it covers the current page, loaded records, eligible filtered leaves or an authoritative all-matching snapshot. Changes to Account, Company, actor, session or effective authority SHALL invalidate protected selection and prevent late results from restoring it. Existing snapshot exclusions and group-to-eligible-leaf behavior SHALL remain intact; ordinary list navigation SHALL not restore prior bulk selections.

#### Scenario: Sort selected records
- **WHEN** records are reordered under a selection policy that permits retaining their selection
- **THEN** selection remains attached to the same authorized record identities rather than the previous row positions

#### Scenario: Select an entire filtered portfolio
- **WHEN** the operator selects all matching portfolio clients and another client is added later
- **THEN** the existing selection snapshot and exclusions remain authoritative instead of silently adding the later client

#### Scenario: Authority changes during selection resolution
- **WHEN** an all-matching or grouped selection is resolving and the current authority changes
- **THEN** the old selection cannot populate the new context or authorize an action there

### Requirement: Bulk presentation preserves existing operation semantics
Bulk controls SHALL expose only useful operations authorized by the existing domain contract. The interface SHALL identify the selected scope, pending work and confirmed outcomes, including partial failures for independent per-record commands. It SHALL NOT imply atomicity, create new financial/fiscal/administrative/licence powers, resend uncertain operations or broaden permissions through shared presentation.

#### Scenario: Independent task updates partly fail
- **WHEN** an existing multi-task operation confirms some changes and fails others
- **THEN** the interface distinguishes those outcomes, preserves recoverable failures and does not claim that the entire batch committed or rolled back atomically

#### Scenario: A resource has no authorized mass mutation
- **WHEN** an operator opens a read-only administrative catalog or a financial list without a defined mass-mutation contract
- **THEN** common list styling does not introduce a bulk mutation action or broader write authority

### Requirement: Expansion reflects record details and real hierarchy
Additional facts about one record SHALL be disclosed within that record's accessible expansion. Grouped records SHALL preserve meaningful aggregate headers and eligible leaf operations. A parent/child tree SHALL represent genuine hierarchical data rather than artificial nesting added solely for appearance. Expansion and selection SHALL remain independently operable by keyboard and pointer, with displaced narrow-screen information and actions still reachable.

#### Scenario: Inspect an ERP subscription on a phone
- **WHEN** an authorized operator expands a compact subscription row
- **THEN** the owning record's secondary facts and allowed contract action become available without requiring an independent duplicate mobile workflow

#### Scenario: Select a Work group
- **WHEN** an operator selects a grouped client/process header
- **THEN** only its eligible task leaves participate in the existing action and synthetic group identifiers are not submitted as task identifiers

#### Scenario: Expand actual child records
- **WHEN** a record with authorized parent/child data is expanded
- **THEN** its children appear with a coherent hierarchy and only records that actually have children offer that hierarchical expansion

### Requirement: Bounded record forms use accessible modal editors
Bounded creation/editing forms SHALL use a responsive modal with a clear task title, reachable submit/cancel controls, field validation and visible feedback. This SHALL include the commercial plan editor, administrative plan/subscription editors and company-catalog create/edit host. Long workflows SHALL retain an appropriate page composition. Notification, message, document-inspection and filter containers SHALL not be converted solely because they are drawers or have a sheet-related name.

#### Scenario: Edit a plan
- **WHEN** an authorized operator opens a commercial or administrative plan editor
- **THEN** a modal presents the existing permitted fields and save/recovery behavior, and closing returns focus to a meaningful surviving opener

#### Scenario: Close a dirty catalog form
- **WHEN** an operator tries to dismiss a catalog editor with unsaved changes
- **THEN** the existing discard review retains the draft until the operator explicitly discards it or returns to editing

#### Scenario: Invalid modal submission
- **WHEN** submitted values fail field validation
- **THEN** entered values remain available, errors identify their controls and keyboard focus reaches the relevant error or invalid control

#### Scenario: Context changes during a modal save
- **WHEN** the selected Account/Company or effective authority changes while a save is pending
- **THEN** private drafts and feedback follow the existing authority-loss contract and a late result cannot repopulate another context's modal

### Requirement: Domain feedback remains truthful through visual standardization
Loading, confirmed-empty, absent, denied, stale, failed, successful and unknown-operation states SHALL remain distinguishable. Shared layout changes SHALL retain exact amounts, current field-level errors, consequential action explanations, busy/dismissal guards and existing operation-identity recovery. Shorter copy SHALL not hide authorization failures or financial effects, and unrelated same-context updates SHALL not erase protected editor drafts.

#### Scenario: Receipt outcome is uncertain
- **WHEN** a receipt command may have reached the server but its outcome is unknown
- **THEN** the interface retains the existing command's recovery path rather than treating a modal close or request cancellation as a reversal or permission to submit again

#### Scenario: Optional owner setup follows enrollment
- **WHEN** enrollment and the first invoice are confirmed before optional owner access setup
- **THEN** the interface keeps the completed commercial result distinct from the remaining access step

### Requirement: Reference alignment preserves complete cross-device operation
The shared composition SHALL keep primary tasks, supported filters, record details, allowed actions and validation/recovery paths reachable in light and dark desktop/mobile layouts. Equivalent tasks SHALL retain recognizable reference alignment without hiding domain controls to mimic a simpler demo. Keyboard focus, accessible names, expansion state and modal focus containment/return SHALL remain effective.

#### Scenario: Work at the supplied compact panel width
- **WHEN** an operator uses a 1046px-wide content panel or a phone layout
- **THEN** navigation, list controls, expansions and form actions remain usable with intentional containment of any necessary financial-table scrolling

#### Scenario: A reference example has only mock behavior
- **WHEN** a product screen adopts a reference composition
- **THEN** its existing real reads, commands and authority checks remain effective, and unrelated demonstration surfaces are not silently promoted into real business capabilities
