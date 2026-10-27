## MODIFIED Requirements

### Requirement: Existing list navigation state survives same-authority round trips
Real portfolio, Fiscal, Monitoring, Work, office-commercial, Admin and company ERP list surfaces SHALL retain their supported search, filters, sorting field/direction, page size and selected page when the user leaves and returns within the same authority and browser application session. Explicit valid URL parameters and browser history SHALL take precedence over remembered state. A changed filter or supported ordering SHALL reset pagination once, and server metadata SHALL bound the restored page. Invalid query values SHALL use existing validated defaults. Remembered state SHALL NOT restore bulk selections, financial commands, credentials or mutation drafts, and SHALL be discarded on authority changes or logout.

#### Scenario: Return to a filtered list
- **WHEN** an office administrator filters Clientes ERP, visits Planos and returns under the same authority
- **THEN** the prior list criteria are restored, the server query agrees with them, and counts remain authoritative

#### Scenario: An explicit URL conflicts with remembered criteria
- **WHEN** valid direct URL or history parameters specify criteria different from the remembered list state
- **THEN** the displayed controls and requested data follow those explicit parameters without duplicate reset-driven queries

#### Scenario: A remembered page no longer exists
- **WHEN** current server pagination reports fewer pages than the restored selection
- **THEN** the list recovers to a valid server page and does not present obsolete rows or a fabricated successful empty state

#### Scenario: Another authority opens the same list
- **WHEN** the actor, session, Account, Company or effective permissions change
- **THEN** previous private list state and results are cleared before the new authority can display or act on them

#### Scenario: Return to an explicitly sorted list
- **WHEN** an authorized operator changes supported ordering, visits a detail and returns under the same authority
- **THEN** the controls and authoritative query retain that ordering, explicit valid URL/history criteria take precedence, and bulk selections or mutation drafts are not restored

#### Scenario: Ordering changes while an earlier read is pending
- **WHEN** a new supported ordering supersedes a pending list read
- **THEN** pagination resets once and the older response cannot replace the new ordered result or clear its loading state
