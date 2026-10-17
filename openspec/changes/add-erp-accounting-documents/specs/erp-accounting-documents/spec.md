## Purpose

Deliver verified company-issued fiscal documents to an explicitly authorized accounting office and Client without duplicate accounting rows or renewed provider issuance.

## ADDED Requirements

### Requirement: Delivery requires explicit current company office Client mapping
An owner-authorized single active delegation and office-approved Company/establishment-to-Account/Client mapping SHALL govern delivery. Matching CNPJ alone SHALL not create a grant or select a destination. Pending work SHALL recheck company/office status, delegation and Client identity before publication. Revocation SHALL prevent new delivery and ERP access by the former office, while valid previously delivered accounting history remains preserved.

#### Scenario: Former office still has pending handoff
- **WHEN** a delivery job runs after its office delegation was replaced
- **THEN** no new document is published to the former office and the job exposes the revoked authorization outcome

#### Scenario: Mapping uses foreign Client
- **WHEN** an office mapping request supplies a Client from another Account
- **THEN** validation returns 422 and no delivery mapping or artifact access is created

### Requirement: Delivery is recoverable and converges with captured documents
Authorized artifacts SHALL produce durable delivery intent and idempotent publication. An accounting logical note SHALL remain unique per Account/Client/source/document key regardless of ERP or capture origin; correlated events SHALL attach once to its timeline. Conflicting environment/issuer/hash/protocol evidence SHALL enter attention without overwriting accepted artifacts. Provider reissuance SHALL not be used to retry an accounting projection. Company and Account contexts SHALL be restored after success or failure.

#### Scenario: Capture and issued handoff race
- **WHEN** capture and ERP delivery publish the same logical note concurrently
- **THEN** one accounting document/count survives with attributable origins and no duplicate XML publication

#### Scenario: Handoff storage fails
- **WHEN** destination artifact persistence is unavailable
- **THEN** delivery remains retryable and no ready marker or provider reissuance is claimed

### Requirement: Company and office delivery views preserve permission boundaries
Delivery status and retry interfaces SHALL expose only authorized company/office metadata, reasons and recorded outcomes. Existing office Fiscal envelopes SHALL remain compatible. Downloads SHALL use private authorized artifacts; read failure SHALL not appear as zero deliveries. Retrying a delivery SHALL not reactivate a revoked grant or confer platform support access.

#### Scenario: Delegated reader attempts unauthorized retry
- **WHEN** a read-only delegated user requests an operational handoff retry
- **THEN** the action is denied and read-only status remains available under its normal scope

#### Scenario: Delivery list read fails
- **WHEN** the service cannot load company delivery states
- **THEN** the UI presents retryable failure rather than an empty successful list
