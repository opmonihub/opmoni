## ADDED Requirements

### Requirement: Administrative resource ordering preserves platform boundaries
Account, global-user and subscription resource lists SHALL support validated ascending/descending ordering by their documented visible identity/status fields where applicable, plus record identifier, before server pagination. Identifier tie-breaking SHALL make equal values deterministic. Existing no-sort ordering, raw/resource response shapes, fixed summary/audit history order and read-only global-user behavior SHALL remain compatible. Unsupported sort fields or directions SHALL return field errors, and Super Admin authorization SHALL remain independently enforced.

#### Scenario: Sort a paginated global user list
- **WHEN** a Super Admin selects name ordering for matching global users
- **THEN** all matching pages follow that ordering with stable equal-name ties, the existing envelope remains intact and no user-write action is introduced

#### Scenario: Preserve fixed audit and summary order
- **WHEN** the administrative shell is standardized and resource-list sorting is added
- **THEN** recent summary events and support audit rows retain their canonical ordering and safe projections

#### Scenario: Invalid ordering and unauthorized caller
- **WHEN** an authorized caller supplies unsupported ordering or an ordinary member requests the same global resource
- **THEN** the authorized invalid query receives 422 field errors and the ordinary member remains forbidden without global data
