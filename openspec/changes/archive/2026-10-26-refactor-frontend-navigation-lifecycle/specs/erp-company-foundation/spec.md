## MODIFIED Requirements

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

## ADDED Requirements

### Requirement: Operational workspaces initialize each logical read once
Opening a company operational workspace SHALL select its authorized requested area and initialize each required list and reference dataset at most once for that entry. Component mounting and destination selection SHALL NOT both request the same logical read. Later explicit refreshes and changes of area, filters or authority SHALL retain their established reads and permission checks.

#### Scenario: Open purchases directly
- **WHEN** an authorized user enters Compras with no hash or with the returns hash
- **THEN** the permitted destination is selected and each required purchases, returns or reference dataset is initialized once for that entry

#### Scenario: Open sales through a saved area link
- **WHEN** an authorized user enters Vendas with a valid area hash
- **THEN** the matching authorized area and necessary reference data load once without first loading a redundant default-area dataset
