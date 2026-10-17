## Purpose

Protect each client's A1 certificate lifecycle and expose only safe metadata for operational fiscal use.

## ADDED Requirements

### Requirement: Authorized A1 certificate lifecycle
Upload/replacement and removal under `/api/v1/clients/{client}/certificate` SHALL require a real `admin` or `operador` membership in the selected Account. Upload SHALL validate the PKCS#12 container and supplied password before replacement. Metadata SHALL identify the current certificate and its validity without exposing passwords, private keys, container bytes, or private storage paths.

#### Scenario: Invalid container or password
- **WHEN** the submitted A1 cannot be inspected with its password
- **THEN** validation fails and the previously current certificate remains current

#### Scenario: Foreign client certificate
- **WHEN** an operator references another Account's client certificate endpoint
- **THEN** the API returns 404 and does not read or change its vault

### Requirement: Encrypted certificate and transactional replacement
Certificate containers and passwords SHALL be encrypted at rest. Replacement SHALL make only the new certificate current, clear superseded secret references, and clean the old file after the database commit. Rollback SHALL remove a newly staged file without deleting the previously committed certificate; removal SHALL clear the active secret references.

#### Scenario: Parent transaction rolls back
- **WHEN** a replacement succeeds internally but the parent request rolls back
- **THEN** the new staged file is removed and the original certificate remains usable

#### Scenario: Old-file cleanup failure
- **WHEN** deletion of the superseded file fails after replacement commits
- **THEN** the committed replacement is preserved
