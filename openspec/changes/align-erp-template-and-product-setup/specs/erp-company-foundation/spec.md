## ADDED Requirements

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
Settings SHALL group fields by meaningful task, use value types appropriate to their contracts and preserve named validation errors and unsaved values. Fiscal classification codes SHALL remain strings, including leading zeroes; rates SHALL retain exact decimal semantics. Unknown legacy values SHALL remain visible until an explicit compatible migration or correction. Administration and operational editors SHALL open on deliberate actions, use reachable primary/cancel controls and expose detailed grants on demand without changing owner-only or delegated access restrictions. Descriptions SHALL use concise Brazilian Portuguese and retain warnings that explain actual consequences.

#### Scenario: Save leading-zero tax defaults
- **WHEN** an authorized operator saves defaults such as CST `00`, PIS `01` or CSOSN `101`
- **THEN** save/reload preserves the string code and rejected values remain editable with field feedback rather than being coerced to numbers

#### Scenario: Existing numeric legacy setting is ambiguous
- **WHEN** a stored classification cannot be unambiguously interpreted under the current regime and code contract
- **THEN** it remains visible as requiring review and is not silently padded, reclassified or supplied to an operation as a confirmed code

#### Scenario: Read-only administration and failed save
- **WHEN** an operator has consultation only or a permitted edit fails
- **THEN** the interface retains consultation or entered corrections respectively, does not offer an unauthorized mutation, and never changes owner protections or shows fabricated success
