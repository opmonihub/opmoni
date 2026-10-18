## MODIFIED Requirements

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

## ADDED Requirements

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
