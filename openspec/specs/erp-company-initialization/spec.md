# erp-company-initialization Specification

## Purpose

Give newly created ERP companies usable native operating settings and baseline catalogs, with versioned initialization and safe additive reconciliation for existing companies.

## Requirements

### Requirement: Company initialization supplies a versioned operating preset
Successful native company provisioning SHALL initialize an attributable versioned preset together with company, owner membership, initial establishment and existing subscription state. The initial operating preset SHALL supply America/Sao_Paulo, day/month/year dates, 24-hour display, January fiscal-year start, BRL through the native ISO currency contract, the existing FIFO preference and overselling disabled. It SHALL not create users, office memberships, fiscal credentials, an assumed tax regime/rate/classification or production authorization. A failed required initialization step SHALL leave no partially provisioned company.

#### Scenario: Provision a new company
- **WHEN** an authorized native company-creation flow completes
- **THEN** its native operating preset and required baseline references are available, its owner and subscription retain the established access contract, and no demonstration identity or tax treatment is imported

#### Scenario: Initialization fails before commit
- **WHEN** a required native baseline record cannot be created during company provisioning
- **THEN** provisioning fails atomically with actionable feedback and no partially usable company or ownership grant remains

### Requirement: Baseline catalogs have real native consumers
Initialization SHALL provide or safely reuse a company-owned `UN / Unidade` base unit, a native default unit preference, a default counter-sale customer and a commercial numbering/layout selection with localized labels. The initial UN SHALL use whole units and no invented conversion. A new company's initial commercial sale scheme SHALL use blank prefix, start 1 and four digits, within existing uniqueness and allocation safeguards. Defaults SHALL be used by their actual product/commercial/print flows, remain explicitly changeable by authorized operators and retain their same-company scope. Default customer data SHALL not invent tax identity, address or municipality. Commercial layouts SHALL not claim fiscal artifact validity.

#### Scenario: First basic product uses the initialized unit
- **WHEN** an operator opens product creation in a newly initialized company
- **THEN** the verified native default unit is available and can satisfy the unit requirement without a manual legacy-ID bootstrap

#### Scenario: Default customer is insufficient for a fiscal operation
- **WHEN** the initialized counter-sale customer lacks facts required by a selected fiscal model
- **THEN** the operation requests the actual required recipient facts and does not fabricate them from a default customer

#### Scenario: Commercial document consumes its defaults
- **WHEN** a new company's permitted sale is created and its commercial representation is rendered
- **THEN** numbering and localized layout use the configured native defaults and the result is not represented as a DANFE or provider-authorized invoice

### Requirement: Existing-company reconciliation is previewed and additive
An authorized direct company owner SHALL be able to preview initialization reconciliation and explicitly apply supported missing entries for that selected company. Preview SHALL distinguish create, reuse, skip and conflict, including changes relative to effective fallback settings. Application SHALL preserve existing choices, nulls, false, zero, empty values and nested settings unless an explicit compatible change is approved. It SHALL not reset numbering, reactivate conflicting archived records, relabel customized units or alter balances, credentials, identities or posted history. Existing active numbering behavior SHALL remain unchanged unless an explicit reviewed configuration change is requested.

#### Scenario: Existing company has customized settings and units
- **WHEN** the owner previews and applies missing-only reconciliation
- **THEN** existing fiscal/commercial preferences, nested settings and customized unit semantics remain intact, while only agreed missing entries are created

#### Scenario: Preview becomes stale
- **WHEN** another authorized change alters a relevant preference, default or catalog record before application
- **THEN** application detects the changed preconditions and requests a new review rather than overwrite the newer choice

### Requirement: Initialization is idempotent and safe under concurrency
Repeated or concurrent initialization SHALL produce at most one effective preset/default selection per company scope and SHALL not duplicate baseline records. Stable native identity and version SHALL distinguish preset-owned entries from similarly named user entries. A retry after a lost response SHALL reconcile with the original committed result; it SHALL not reapply numbering or business effects.

#### Scenario: Concurrent baseline initialization
- **WHEN** independent processes initialize the same company concurrently
- **THEN** they converge on one valid native baseline without duplicate units, default customers, schemes or layouts

#### Scenario: Retry after response loss
- **WHEN** a committed initialization response is lost and the same operation is retried
- **THEN** the effective result is reused without resetting preferences or allocating another commercial document number

### Requirement: Setup preserves authority and reference compatibility
Reconciliation preview/application SHALL enforce authenticated direct ownership, active selected Company, session assertions and current authorization at effect time. Delegated office or platform status alone SHALL not grant reconciliation. Native currency/unit/customer references SHALL not be inferred from legacy numeric IDs or display names. Lookup/storage failures SHALL be reported and preserve prior data; manifests, responses and audit metadata SHALL omit secrets.

#### Scenario: Reconciliation has stale company context
- **WHEN** an owner applies a preview after the session switched companies
- **THEN** the request returns the established company-context conflict and changes neither company's setup

#### Scenario: Platform or delegated operator lacks direct ownership
- **WHEN** an operator without direct company-owner authority calls reconciliation
- **THEN** the request is denied without revealing or modifying protected setup records

#### Scenario: Legacy currency namespace is unresolved
- **WHEN** an existing opaque currency identifier has no verified mapping
- **THEN** it is preserved and reported as unresolved rather than converted to a same-number native record or silently replaced with BRL
