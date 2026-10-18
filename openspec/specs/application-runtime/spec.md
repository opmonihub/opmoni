# application-runtime Specification

## Purpose

Preserve the private frontend-to-backend boundary, persistent development data, Account-aware background operations, and isolated verification resources.

## Requirements

### Requirement: Private versioned application ingress
Browser and server-rendered application calls SHALL use relative `/api/v1/*` through the private frontend proxy. The backend SHALL reject untrusted direct application ingress and honor only trusted client IP/protocol metadata. The proxy SHALL not accept browser-supplied internal authorization headers or expose its private key/origin in serialized state. Health and the authenticated provider-webhook flow SHALL retain their explicitly separate boundaries.

#### Scenario: Caller forges private forwarding headers
- **WHEN** an external request supplies ingress, client-IP, or protocol metadata
- **THEN** the trusted proxy boundary sanitizes them rather than granting caller-selected trust

### Requirement: Business transport preserves session and Account assertions
JSON, multipart uploads, and binary downloads SHALL use the existing authenticated session transport. Office-scoped requests SHALL include the displayed `X-Account-Id`; company-scoped ERP requests SHALL instead include the displayed `X-Company-Id` under their explicit company guard; state-changing browser calls SHALL include the current CSRF token. Non-JSON response failures SHALL remain visible rather than be downloaded as successful business artifacts.

#### Scenario: Multipart business upload
- **WHEN** an authorized operator uploads a client certificate
- **THEN** the proxy forwards the real file bytes while retaining session, CSRF, and Account assertion checks

### Requirement: Background work carries and restores Account context
Queues and schedules SHALL operate within explicit Account context for each tenant operation and restore the previous context on success or exception. Job timeouts SHALL stay below the reservation retry interval so one timeout is not mistaken for completed work. Restarting long-running workers after source changes SHALL load the updated behavior. NF-e science and XML-completion work SHALL identify its Account, client, source and key explicitly, be dispatched only after the associated durable transaction commits, and serialize conflicting work for the same client/source and logical note. Due-work scheduling SHALL enqueue bounded jobs at least once every five minutes rather than execute an unbounded multi-client network sweep inside the scheduler process.

#### Scenario: Different Accounts share a worker
- **WHEN** a failed job for Account A is followed by a job for Account B
- **THEN** the second job uses only Account B context and no previous selection leaks

#### Scenario: Parent transaction rolls back
- **WHEN** a transaction that recorded a summary or completion request rolls back
- **THEN** no external science or XML recovery is dispatched for that uncommitted request

#### Scenario: Concurrent due-work dispatch
- **WHEN** multiple scheduler or worker processes discover the same due logical note
- **THEN** one effective science/recovery operation owns that work and duplicate discovery cannot cause concurrent provider calls or reset a completed result

#### Scenario: Worker restarts after accepted science
- **WHEN** a worker stops after provider science acceptance but before enqueueing XML recovery
- **THEN** persisted acceptance is rediscovered by the due-work dispatcher and recovery resumes without blindly resending science

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

### Requirement: ERP work shares the runtime with explicit Company context
ERP SHALL execute in the existing application/database/runtime boundary with additive PostgreSQL data and explicit Company context. Company jobs and private artifacts SHALL carry company/establishment identifiers and restore previous context on success and exception. ERP certificates and passwords SHALL be encrypted and artifacts SHALL remain private and persistent. Domain-specific queues/timeouts SHALL remain bounded below their reservation intervals. Existing office background/capture context and gates SHALL remain unchanged; ERP work SHALL not create a second authentication runtime or reuse browser session selection in workers.

#### Scenario: Worker switches between company and office work
- **WHEN** a failed ERP job is followed by an office capture job on the same worker
- **THEN** both domain contexts are restored and neither job can access the previous tenant artifacts

#### Scenario: ERP runtime recreated
- **WHEN** the configured application containers are replaced
- **THEN** committed ERP certificates/XML remain in the configured persistent private storage and no public source XML directory is served

### Requirement: Unified ERP production release requires complete core acceptance
ERP APIs, public signup and background actions SHALL enforce an explicit disabled, preview or production release mode. Missing/unknown mode SHALL disable ERP operations. Preview SHALL require a non-empty valid company/actor allowlist in an isolated verification environment and SHALL NOT bypass authorization or enable production fiscal effects. Production release SHALL require complete implementation and acceptance of all nine ERP changes and every included source-core matrix item, with unchanged office regressions, real PostgreSQL/Redis process evidence, SSR/browser checks, private persistence/recovery checks and required scoped provider canaries. Partial module implementation or fake-provider success SHALL not authorize complete public launch. Disabling ERP SHALL preserve committed data and existing office operations.

Separately authorized pre-release fiscal canaries SHALL use only a non-public operator harness with explicit actor/company/model/environment/key scope, current company permissions and enabled provider gates. They SHALL retain normal durable attempts and audits and SHALL NOT enable public ERP mode. Missing approval or empty/invalid scope SHALL block the canary; ordinary preview/API/queued operations SHALL not acquire this exception.

#### Scenario: Release configuration missing
- **WHEN** ERP routes or work execute without a valid release mode
- **THEN** the operation is blocked without publishing a partial ERP or changing office availability

#### Scenario: Preview scope is empty
- **WHEN** preview is selected with no valid allowlist
- **THEN** all preview work is blocked and clearing the list cannot imply production mode

#### Scenario: One core acceptance item remains missing
- **WHEN** all but one included core module or required provider verification is complete
- **THEN** complete ERP release remains blocked and the missing evidence is reported explicitly

#### Scenario: ERP must be disabled after deployment
- **WHEN** an operator disables the ERP release gate
- **THEN** new ERP operations stop while committed ERP history and existing office access/capture remain preserved

#### Scenario: Authorized canary precedes public release
- **WHEN** an operator runs an explicitly approved fully scoped fiscal canary while public ERP is disabled
- **THEN** only the non-public harness can execute the authorized effect with normal company permissions and durable evidence, and public ERP operations remain disabled

### Requirement: Production science rollout preserves its authorized scope
Production science SHALL remain disabled during schema migration, historical classification and fake-provider verification. After verification, rollout SHALL first target a single explicitly selected eligible client/note before enabling the authorized automatic policy more widely. Activation SHALL require both the enabled science gate and an explicit rollout mode of `pilot` or `fleet`; missing, disabled or unknown mode SHALL block outgoing science and its recovery. Pilot mode SHALL require a non-empty valid Account/client/key allowlist, enforced at dispatch and execution. An absent, empty or malformed pilot scope SHALL block all work and SHALL NOT select fleet. Fleet activation SHALL require an explicit mode change after successful authorized canary evidence, never omission or clearing of the pilot list. The only automated recipient event SHALL be `210210`; rollout SHALL NOT enable CT-e, NFS-e, conclusive recipient events, or destructive cursor resets. Runtime configuration for backend, queue and scheduler SHALL agree, and inspection commands SHALL not reveal certificate material, passwords or private ingress keys. A lack of an eligible live canary SHALL be reported as unverified rather than worked around with an expired or wrong-recipient note.

#### Scenario: No eligible historical canary
- **WHEN** existing historical summaries are outside the science deadline or lack verifiable authorization
- **THEN** no event is sent to force acceptance and rollout reports the live check as unavailable while retaining actionable processing states

#### Scenario: Independent source gates
- **WHEN** NF-e science is enabled after its authorized checks
- **THEN** CT-e and NFS-e keep their previous gate values and no conclusive recipient event is enabled

#### Scenario: Pilot scope missing or empty
- **WHEN** the science gate is enabled in pilot mode but the pilot allowlist is absent, empty or malformed
- **THEN** no science or associated recovery is dispatched or executed and the system reports invalid pilot configuration without activating fleet

#### Scenario: Rollout mode omitted
- **WHEN** the science gate is enabled but the rollout mode is missing or unknown
- **THEN** outgoing science and its recovery remain blocked instead of using an unrestricted default

#### Scenario: Pilot list cleared
- **WHEN** an operator clears the pilot list without explicitly selecting fleet mode
- **THEN** the rollout blocks work rather than broadening scope

### Requirement: Completion concurrency verification uses isolated independent processes
Claims about concurrent science registration, due-work claims, retry budgets, idempotent completion and worker-crash recovery SHALL be verified with independent PostgreSQL/Redis processes using the dedicated test database and isolated Redis stores. Provider traffic in those tests SHALL remain mocked; tests SHALL not modify development certificates, documents, queues or sessions. Successful fake tests SHALL not be represented as proof of live fiscal acceptance.

#### Scenario: Duplicate processing claim test
- **WHEN** independent test workers attempt to claim the same logical completion work
- **THEN** the test verifies the resulting single effective operation without accessing development resources or making real provider calls
