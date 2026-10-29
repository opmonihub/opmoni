## MODIFIED Requirements

### Requirement: Tenant client CRUD and validation
The system SHALL provide client listing, detail, creation, update, and soft deletion under `/api/v1/clients`. Only current Account members SHALL read; only `admin` and `operador` SHALL write through those tenant CRUD routes. The separately authorized office-specific ERP trial registration SHALL have a narrow capability to create or explicitly reuse its sponsoring office's company Client within its atomic registration transaction; it SHALL grant no portfolio reading, general CRUD access or Account membership. CPF/CNPJ, person type, status, and tax regime SHALL be validated; individual clients SHALL use the not-applicable regime and SHALL NOT be enrolled as ERP Companies. Foreign client IDs SHALL return 404 and foreign related IDs SHALL be rejected. New Client creation, including trial registration, SHALL enforce the office client limit.

ERP commercial registration SHALL explicitly link one selected-office Client to its authorized Company without turning a portfolio-only Client into an ERP registration implicitly. Company inspection SHALL expose legal/trade name, CNPJ, regime, state registration, contact person, email, phone, WhatsApp and address with unknown values labeled. A real selected-office administrator with an active office subscription and current Company-management relationship SHALL edit descriptive/contact/address fields; CNPJ, ownership, global-user identity, tax-provider snapshots and fiscal operating configuration SHALL remain unchanged. Updates to office Client and linked Company projections SHALL be atomic and reject stale revisions rather than overwrite concurrent edits.

#### Scenario: Read-only member attempts creation
- **WHEN** a member with role `user` submits a client
- **THEN** the API returns 403 and creates no client

#### Scenario: Invalid registration
- **WHEN** a client payload has an invalid tax identifier or incompatible individual regime
- **THEN** the API returns 422 without partial creation

#### Scenario: Foreign client
- **WHEN** an Account member requests another office's client
- **THEN** the API returns 404 without exposing that client

#### Scenario: Existing portfolio Client enters an ERP trial
- **WHEN** a verified owner completes an office-linked registration whose CNPJ already matches an active company Client in that office
- **THEN** the existing Client is reused after explicit association confirmation without overwriting contact, fiscal, monitoring or certificate data

#### Scenario: Public trial supplies a foreign Client identifier
- **WHEN** a public signup includes a portfolio client identifier or office identifier instead of the server-resolved trial-link attribution
- **THEN** the request is rejected before any client or Company association and no foreign portfolio details are disclosed

## ADDED Requirements

### Requirement: ERP portfolio selection is bounded and duplicate safe
The ERP portfolio picker SHALL search and paginate only company Clients of the selected office. Only active, undeleted and not-yet-registered Clients SHALL be selectable. Inline addition SHALL add one eligible Client without requiring row selection; checkboxes SHALL permit a bounded explicit set and a tri-state header SHALL select only eligible rows on the current filtered page. The UI SHALL identify already registered Clients and disable repeated additions. The server SHALL validate every submitted identifier, authorize the complete bounded request before effects and enforce registration uniqueness under concurrency. Select-all SHALL NOT silently include unseen pages or future Clients.

#### Scenario: Add several selected Clients
- **WHEN** an administrator submits a bounded set of eligible selected Client identifiers
- **THEN** each is registered once in a single recoverable batch, already registered same-office entries are reported as unchanged, and the UI refreshes the affected rows/counts

#### Scenario: Foreign identifier is mixed into a batch
- **WHEN** a submitted batch includes a foreign, inactive, deleted or non-company Client
- **THEN** validation refuses the batch without registering the valid subset or exposing the foreign Client

#### Scenario: Header selection changes with search
- **WHEN** the user selects all on one filtered page and changes the search
- **THEN** the header reflects only the visible eligible selection, retained explicit selections remain identifiable, and submission includes only explicit authorized identifiers

#### Scenario: Save contact changes
- **WHEN** an administrator saves valid contact and address edits through the Company modal
- **THEN** its Client/Company projections update together and a failed save preserves the draft without changing ownership, credentials or subscription terms
