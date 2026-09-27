## MODIFIED Requirements

### Requirement: Upload seguro de certificado A1
The system SHALL accept a password-protected PFX/P12 certificate only when its password unlocks a parseable certificate, SHALL extract its non-secret metadata, SHALL store the supplied password encrypted at rest so the certificate remains usable for an authorized outbound call, and SHALL never return the stored password through the API, never log it and never expose it in error messages.

#### Scenario: Certificado válido
- **WHEN** an authorized member uploads a valid PFX/P12 with the correct password for a client in the current Account
- **THEN** the system stores an encrypted private copy together with the encrypted password and returns only safe metadata including subject, serial and validity dates

#### Scenario: Senha incorreta
- **WHEN** the supplied password cannot unlock the uploaded certificate
- **THEN** the system responds with a validation error and stores neither a database record nor a file nor a password

#### Scenario: Arquivo inválido
- **WHEN** the upload exceeds the allowed size or is not a valid PFX/P12 certificate
- **THEN** the system rejects it without replacing the current valid certificate

#### Scenario: Substituição de certificado
- **WHEN** an authorized member uploads a new valid certificate for a client that already has one
- **THEN** the new certificate and its encrypted password become current and no password of the replaced certificate remains stored

#### Scenario: Senha inacessível em tempo de chamada
- **WHEN** the stored password cannot be decrypted for an authorized outbound call
- **THEN** the system fails that call without a partial write and reports the client as needing its certificate uploaded again

## ADDED Requirements

### Requirement: Uso temporário do certificado fora do upload
The system SHALL materialize a client's stored certificate only for the duration of an authorized outbound call, SHALL restrict the materialized file to the service account, and SHALL delete it and discard the decrypted password from memory when the call ends, whether it succeeded or failed.

#### Scenario: Certificado materializado para uma chamada
- **WHEN** an authorized outbound call requires the client's certificate
- **THEN** the certificate is written to an ephemeral location readable only by the service account and passed to the transport layer from that path

#### Scenario: Chamada concluída com sucesso
- **WHEN** the outbound call completes
- **THEN** the materialized certificate file no longer exists and the decrypted password is no longer referenced

#### Scenario: Chamada interrompida por falha
- **WHEN** the outbound call fails or throws
- **THEN** the materialized certificate file is still deleted and the decrypted password is still discarded

#### Scenario: Chamadas concorrentes do mesmo cliente
- **WHEN** two authorized outbound calls for the same client overlap
- **THEN** each uses its own materialized file and neither reads the other's

### Requirement: Certificado sem senha armazenada
The system SHALL report a client whose current certificate has no stored password as requiring the certificate to be uploaded again, SHALL exclude it from any capability that depends on using the certificate, and SHALL preserve its retained non-secret metadata and history.

#### Scenario: Certificado anterior a esta versão
- **WHEN** a client holds a certificate stored before passwords were persisted
- **THEN** the client is reported as requiring a new upload and any capability that needs the certificate is skipped for that client

#### Scenario: Re-upload regulariza o cliente
- **WHEN** an authorized member uploads the same client's certificate again with its password
- **THEN** the stored password is present and the client is no longer reported as requiring a new upload

#### Scenario: Validade preservada
- **WHEN** a client is reported as requiring a new upload
- **THEN** its subject, serial, validity dates and history remain readable and the requirement is presented separately from expiry
