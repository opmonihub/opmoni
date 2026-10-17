# platform-serpro-connection Specification

## Purpose

Protect the platform SERPRO singleton credential and certificate lifecycle, with restricted writes and authentication-only connectivity diagnostics.

## Requirements

### Requirement: Connection metadata has explicit read authorization
`GET /api/v1/serpro/connection` SHALL return safe `{data:{configured,consumer_key_hint,contracting_document,certificate_subject,certificate_serial,certificate_not_before,certificate_not_after,updated_at}}` metadata. Reading SHALL permit Super Admin or a member of the currently selected active Account; writing and connectivity SHALL require Super Admin. An absent connection SHALL return 200 with `configured=false`; failed loading SHALL not be shown as unconfigured success. Complete keys, secrets, private keys, PKCS#12 bytes/passwords, and provider tokens SHALL not be returned.

#### Scenario: Unconfigured platform
- **WHEN** an authorized caller reads before any credential is stored
- **THEN** the API returns successful unconfigured metadata

#### Scenario: Nonmember requests metadata
- **WHEN** a non-Super Admin has no active selected membership
- **THEN** the metadata read is denied

### Requirement: Atomic singleton credential configuration and rotation
`PUT /api/v1/serpro/connection` SHALL configure or rotate the single credential and return 200. Initial configuration SHALL require key, secret, and a usable certificate source. Omitted or blank supported credential fields SHALL preserve existing values on rotation. PKCS#12 upload SHALL enforce the 2 MiB limit and parse its document/metadata before replacement; caller-supplied contracting document SHALL be prohibited. Validation failure SHALL preserve the saved connection.

#### Scenario: Failed replacement
- **WHEN** a replacement certificate or password is invalid
- **THEN** the API returns validation failure and preserves the current usable credential

#### Scenario: Competing initial writes
- **WHEN** two attempts insert the platform connection
- **THEN** the singleton constraint prevents duplicate persisted credentials

### Requirement: Certificate sources remain explicit in the current interface
Configuration SHALL support a dedicated platform file upload or reuse of the stored contracting-office e-CNPJ with `use_account_certificate`. The current reuse path SHALL not be advertised as an arbitrary Account selector or a platform contracting-certificate upload API. The absence of a reusable certificate SHALL fail with validation feedback rather than select another source silently. Office certificate management SHALL retain its tenant-admin contract.

#### Scenario: Both certificate sources submitted
- **WHEN** a request includes a new container and asks to reuse the office certificate
- **THEN** the API returns 422 rather than guessing the intended source

### Requirement: Connectivity is safe authentication only
`POST /api/v1/serpro/connectivity` SHALL require Super Admin, be rate limited to six attempts per minute, and test provider authentication rather than taxpayer synchronization. TLS and mutual-certificate authentication SHALL remain enabled; failures SHALL produce named safe verdicts for local configuration/certificate, credentials, or provider problems without returning tokens or sensitive provider payloads. Private temporary certificate material SHALL be cleaned on success and failure.

#### Scenario: Local certificate unusable
- **WHEN** the configured credential cannot materialize its certificate
- **THEN** the diagnostic returns a safe local failure and does not claim provider success

#### Scenario: Provider unavailable
- **WHEN** the authentication provider times out or rejects service availability
- **THEN** the diagnostic reports provider failure without starting fiscal or monitoring synchronization
