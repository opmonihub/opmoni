## Purpose

Expose Account-scoped fiscal coverage, stored documents and XML, queued capture, and guarded recovery operations in the integrated application.

## ADDED Requirements

### Requirement: Account fiscal coverage and document reads
The Fiscal pages SHALL use `/api/v1/fiscal/summary`, `/fiscal/clients`, and `/fiscal/documents` to show persisted coverage, client capture attention, and paginated documents. Filters SHALL be validated and restricted to the selected Account, including references to its deleted clients. Detail SHALL expose safe document metadata, events, and a bounded display preview rather than certificate secrets or storage paths.

#### Scenario: Foreign document
- **WHEN** a member requests a document owned by another Account
- **THEN** the API returns 404 without exposing XML or metadata

#### Scenario: Invalid document filter
- **WHEN** the requested model, date, client, or value filter is invalid
- **THEN** the API returns 422 rather than applying an unintended broader query

### Requirement: XML download preserves original bytes
`GET /api/v1/fiscal/documents/{id}/xml` SHALL authorize the document before private storage access and return the stored XML bytes as an attachment. Missing stored XML SHALL return 404 rather than an empty successful file. A missing or unreadable preview SHALL not turn the safe metadata view into a fabricated complete document.

#### Scenario: Stored file is missing
- **WHEN** an authorized document exists but its XML file is missing
- **THEN** download returns 404 and detail does not invent XML content

### Requirement: Capture acceptance differs from completion
`POST /api/v1/fiscal/clients/{client}/capture` SHALL require an operational writer, validate the source, and check its manual gate and provider cooldown before dispatch. Accepted work SHALL return 202 with `queued=true` and the client identifier, without claiming the provider already delivered documents. Source denial or cooldown SHALL return 409 with actionable feedback.

#### Scenario: Disabled capture source
- **WHEN** a member requests a disabled CT-e or NFS-e manual capture source
- **THEN** the API returns 409 before dispatch

#### Scenario: Provider has placed the client in cooldown
- **WHEN** a capture is requested before the stored unblock time
- **THEN** the API returns 409 and communicates the wait time

### Requirement: Capture and recovery preserve tenant and provider state
Capture, reconciliation, and manifested-document recovery SHALL carry explicit Account context and serialize work for the same client/source. Delivered readable entries SHALL be stored with their Account; failed entries SHALL retain recoverable gap state rather than be counted as successful documents. Cooldowns and lookup budgets SHALL prevent repeated immediate provider calls. Failed work SHALL restore the previous tenant context.

#### Scenario: Background job carries the wrong Account
- **WHEN** a capture job references a client outside its declared Account
- **THEN** the job cannot capture or store that client's documents

#### Scenario: Partially unreadable provider batch
- **WHEN** a batch contains readable documents and a failed entry
- **THEN** readable documents remain stored and the failed entry remains represented for recovery

### Requirement: Source rollout and manifestation are explicit
CT-e, NFS-e, and manifestation SHALL retain their separate feature/configuration gates. The CT-e scheduled gate SHALL remain a separate operator decision from the manual gate; neither a fake test nor a single-client canary SHALL be represented as approval for fleet-wide production scheduling. Manifestation and its recovery SHALL run only when enabled, with explicit client/Account identifiers. A queued or locally stored result SHALL not claim live-provider acceptance.

#### Scenario: Feature remains disabled
- **WHEN** manifestation or an additional capture source has not been enabled
- **THEN** the UI and operational response do not claim the corresponding external action succeeded
