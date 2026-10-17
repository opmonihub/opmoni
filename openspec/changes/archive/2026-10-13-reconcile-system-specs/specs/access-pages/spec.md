## MODIFIED Requirements

### Requirement: Platform Account page preserves access and commercial boundaries
`/admin/contas` SHALL be visible only to Super Admin and SHALL provide name/ID search, active/suspended filtering, pagination, creation, rename, suspension/reactivation, and owner-link reissue. The creation modal SHALL request office name, owner name, and owner email, with field errors and a loading state. A newly issued activation URL SHALL be shown in a temporary copy action; list refresh or modal reopening SHALL not reveal it again. Suspension SHALL require confirmation. The page SHALL distinguish loading, failed-load with retry, empty installation, and empty filtered results. A separate commercial editor SHALL expose safe contact and subscription/plan metadata; password assignment and access to tenant operational data SHALL remain subject to their separate contracts.

#### Scenario: New office requires first access
- **WHEN** a Super Admin creates a valid office
- **THEN** the page offers the newly generated owner link for copying and refreshes the Account list

#### Scenario: No results match the filter
- **WHEN** a successful filtered Account query returns no records
- **THEN** the page offers clearing the filters rather than showing a service error

## ADDED Requirements

### Requirement: Business navigation resolves the selected Account
The authenticated shell SHALL expose Clients, Fiscal, Monitoring, Work, Team, and business Settings routes through the existing access/session transport. Their data SHALL come from the selected Account; demonstration home widgets and remaining sample inbox/settings handlers SHALL stay visibly distinct from integrated business functionality. Account switching/logout SHALL clear previous Account state before business navigation continues.

#### Scenario: Business page after office switch
- **WHEN** a member switches offices and opens the client portfolio
- **THEN** the displayed clients belong only to the newly selected office

## RENAMED Requirements

- FROM: `### Requirement: Platform Account page supports only foundational operations`
- TO: `### Requirement: Platform Account page preserves access and commercial boundaries`
