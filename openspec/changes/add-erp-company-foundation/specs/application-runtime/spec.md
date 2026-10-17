## MODIFIED Requirements

### Requirement: Business transport preserves session and Account assertions
JSON, multipart uploads, and binary downloads SHALL use the existing authenticated session transport. Office-scoped requests SHALL include the displayed `X-Account-Id`; company-scoped ERP requests SHALL instead include the displayed `X-Company-Id` under their explicit company guard; state-changing browser calls SHALL include the current CSRF token. Non-JSON response failures SHALL remain visible rather than be downloaded as successful business artifacts.

#### Scenario: Multipart business upload
- **WHEN** an authorized operator uploads a client certificate
- **THEN** the proxy forwards the real file bytes while retaining session, CSRF, and Account assertion checks

## ADDED Requirements

### Requirement: ERP work shares the runtime with explicit Company context
ERP SHALL execute in the existing application/database/runtime boundary with additive PostgreSQL data and explicit Company context. Company jobs and private artifacts SHALL carry company/establishment identifiers and restore previous context on success and exception. ERP certificates and passwords SHALL be encrypted and artifacts SHALL remain private and persistent. Domain-specific queues/timeouts SHALL remain bounded below their reservation intervals. Existing office background/capture context and gates SHALL remain unchanged; ERP work SHALL not create a second authentication runtime or reuse browser session selection in workers.

#### Scenario: Worker switches between company and office work
- **WHEN** a failed ERP job is followed by an office capture job on the same worker
- **THEN** both domain contexts are restored and neither job can access the previous tenant artifacts

#### Scenario: ERP runtime recreated
- **WHEN** the configured application containers are replaced
- **THEN** committed ERP certificates/XML remain in the configured persistent private storage and no public source XML directory is served
