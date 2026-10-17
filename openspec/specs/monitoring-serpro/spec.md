# monitoring-serpro Specification

## Purpose

Provide office-scoped SERPRO authorization, monitoring projections, controlled searches, synchronization history, and protected obligation artifacts.

## Requirements

### Requirement: Office certificate and authorization term
Members SHALL read safe office-certificate metadata and authorization-term state for the selected Account. Only an Account `admin` SHALL upload or remove its e-CNPJ through `/api/v1/serpro/account-certificate`; uploads SHALL be limited and validated. Term issuance/renewal SHALL be an internal integration operation rather than a public member write endpoint. The absent term SHALL be represented as a readable absent state.

#### Scenario: Operator attempts office certificate replacement
- **WHEN** a real `operador` membership uploads an office e-CNPJ
- **THEN** the request returns 403 without replacement

#### Scenario: Office has no authorization term
- **WHEN** a member reads the authorization-term endpoint
- **THEN** the result communicates absence rather than falsely claiming authorization

### Requirement: Account enablement and monitoring reads
Monitoring overview, obligation/client associations, declaration details, and stored artifacts SHALL remain bound to the selected Account and real membership. Changing Account integration enablement SHALL additionally require Super Admin. Association writes SHALL require `admin` or `operador` and SHALL not themselves start a provider synchronization. Foreign clients and artifacts SHALL not be exposed.

#### Scenario: Association without search
- **WHEN** an operator associates clients with a monitoring obligation
- **THEN** only the associations are persisted and no synchronization is implicitly started

#### Scenario: Non-platform member changes enablement
- **WHEN** an ordinary Account admin attempts to enable or disable SERPRO integration
- **THEN** the write is denied

### Requirement: Synchronization runs retain scope and history
Manual synchronization and resynchronization SHALL require an operational writer, Account enablement, and eligibility. Only one active run SHALL be admitted per Account. Resynchronization SHALL create a new run linked to its predecessor rather than rewrite history. Runs, items, and safe call metadata SHALL expose progress/failure without credentials or cross-Account data; abandoned work SHALL be terminated by the watchdog.

#### Scenario: Another run is active
- **WHEN** an operator requests a second active run for the same Account
- **THEN** the API returns a conflict and does not start overlapping work

#### Scenario: Provider synchronization fails
- **WHEN** a provider request cannot complete
- **THEN** the run records failure rather than projecting a successful result

### Requirement: Manual search quotas and obligation scheduling
On-demand client/obligation search SHALL enforce the monthly quota for that pair and report blocked pairs with 422. Members SHALL read quota and schedule state; `admin` and `operador` SHALL replace the Account's obligation schedule. Scheduled synchronization SHALL use the configured due obligations and explicit Account context without consuming the manual-search quota.

#### Scenario: Monthly search quota exhausted
- **WHEN** the client/obligation pair exceeds its monthly allowance
- **THEN** the API returns 422 identifying the blocked request without dispatching another search

### Requirement: Mailbox awareness requires explicit confirmation
Opening a mailbox message SHALL require an authorized POST with `ciencia=true`; a GET, prefetch, or ordinary list rendering SHALL not perform the provider action that acknowledges the message. Declaration artifact downloads SHALL validate the Account, client, obligation, period, and allowed artifact before returning bytes.

#### Scenario: No acknowledgement consent
- **WHEN** a message detail request omits explicit awareness confirmation
- **THEN** the provider acknowledgement request is not made
