## Purpose

Preserve the private frontend-to-backend boundary, persistent development data, Account-aware background operations, and isolated verification resources.

## ADDED Requirements

### Requirement: Private versioned application ingress
Browser and server-rendered application calls SHALL use relative `/api/v1/*` through the private frontend proxy. The backend SHALL reject untrusted direct application ingress and honor only trusted client IP/protocol metadata. The proxy SHALL not accept browser-supplied internal authorization headers or expose its private key/origin in serialized state. Health and the authenticated provider-webhook flow SHALL retain their explicitly separate boundaries.

#### Scenario: Caller forges private forwarding headers
- **WHEN** an external request supplies ingress, client-IP, or protocol metadata
- **THEN** the trusted proxy boundary sanitizes them rather than granting caller-selected trust

### Requirement: Business transport preserves session and Account assertions
JSON, multipart uploads, and binary downloads SHALL use the existing authenticated session transport. Tenant requests SHALL include the displayed `X-Account-Id`; state-changing browser calls SHALL include the current CSRF token. Non-JSON response failures SHALL remain visible rather than be downloaded as successful business artifacts.

#### Scenario: Multipart business upload
- **WHEN** an authorized operator uploads a client certificate
- **THEN** the proxy forwards the real file bytes while retaining session, CSRF, and Account assertion checks

### Requirement: Background work carries and restores Account context
Queues and schedules SHALL operate within explicit Account context for each tenant operation and restore the previous context on success or exception. Job timeouts SHALL stay below the reservation retry interval so one timeout is not mistaken for completed work. Restarting long-running workers after source changes SHALL load the updated behavior.

#### Scenario: Different Accounts share a worker
- **WHEN** a failed job for Account A is followed by a job for Account B
- **THEN** the second job uses only Account B context and no previous selection leaks

### Requirement: Private material remains outside public serving
Client certificates and fiscal XML SHALL reside in private Account-separated storage outside Git and public file serving; certificate bytes/passwords SHALL be encrypted. Deployment SHALL preserve the configured private storage across recreation. The existing local source bind mount SHALL not be claimed as proof of a deployed persistent-volume policy.

#### Scenario: Application container is replaced
- **WHEN** the configured runtime is recreated
- **THEN** committed certificates and XML remain available through the persistent private storage contract

### Requirement: Verification isolates development resources
Regular tests SHALL use disposable SQLite/array stores; process-level integration verification SHALL use the dedicated `opmoni_test` database and isolated Redis stores with destructive-fixture guards. Provider fakes SHALL not be reported as live-provider acceptance. A missing external credential SHALL be reported as an explicit skipped live check.

#### Scenario: Concurrency claim
- **WHEN** database or session race guarantees are evaluated
- **THEN** evidence comes from independent PostgreSQL/Redis processes rather than SQLite feature tests
