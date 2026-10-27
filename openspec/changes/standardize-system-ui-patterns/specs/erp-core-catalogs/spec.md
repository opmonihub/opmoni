## ADDED Requirements

### Requirement: Catalog ordering applies to the authorized result set
Company catalogs SHALL support ascending/descending ordering by resource-allowlisted visible identity fields and record identifier. Authorized search/status filters and ordering SHALL apply before server pagination with a stable identifier tie-breaker. Omitted ordering SHALL preserve the existing default and response envelope. Invalid fields or directions SHALL return field validation errors without allowing arbitrary query expressions, changing archived-reference behavior or broadening Company/establishment scope.

#### Scenario: Order contacts across catalog pages
- **WHEN** an authorized operator orders matching company contacts by their visible name
- **THEN** every returned page follows that ordering across the matching company/establishment result set with stable ordering for equal names

#### Scenario: A catalog uses a different visible identity
- **WHEN** an authorized operator orders a vehicle catalog by its visible registration identity
- **THEN** the documented identity ordering is applied instead of assuming that every catalog exposes a name field

#### Scenario: Reject an unsupported ordering request
- **WHEN** an authorized catalog request contains an unsupported sort field or direction
- **THEN** it returns 422 field errors without executing that requested ordering or modifying stored records

#### Scenario: Legacy caller omits ordering
- **WHEN** an existing catalog caller submits its supported search/page query without ordering
- **THEN** its default result ordering, pagination envelope and authorized scope remain compatible

#### Scenario: Ordering does not select a CFOP default
- **WHEN** an operator changes catalog ordering or a caller omits an explicit CFOP selection
- **THEN** the existing explicit/default CFOP resolution contract remains authoritative and list position does not select a default

#### Scenario: Catalog ordering does not bypass authority
- **WHEN** an actor lacks the current company or establishment catalog read right
- **THEN** adding ordering criteria does not grant access, disclose another company's records or authorize catalog writes
