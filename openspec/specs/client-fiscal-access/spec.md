# client-fiscal-access Specification

## Purpose
Centraliza o certificado digital A1 de cada cliente, protegendo segredos e tornando vencimentos e ausências visíveis na carteira do escritório. A procuração e-CAC é lida do provedor por família de serviço autorizada — não há cadastro manual.

## Requirements

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

#### Scenario: Senha inacessível em tempo de chamada
- **WHEN** the stored password cannot be decrypted for an authorized outbound call
- **THEN** the system fails that call without a partial write and reports the client as needing its certificate uploaded again

### Requirement: Arquivo privado e resposta sem segredos
The system SHALL encrypt certificate contents before storage on a non-public disk and SHALL not expose certificate contents, passwords, encryption material or internal storage paths through the API.

#### Scenario: Consulta do cliente com certificado
- **WHEN** a member requests a client that has an A1 certificate
- **THEN** the response contains deadline status and safe metadata but no file contents, password or storage path

#### Scenario: Tentativa entre Accounts
- **WHEN** a member of another Account addresses the certificate endpoint using the client id
- **THEN** the system responds 404 and reveals no certificate metadata

### Requirement: Substituição e remoção do certificado
The system SHALL permit `admin` and `operador` members to replace or remove the current certificate while retaining non-secret historical metadata and deleting obsolete encrypted file contents and their stored password.

#### Scenario: Substituição bem-sucedida
- **WHEN** an authorized member uploads a new valid certificate for a client that already has one
- **THEN** the new certificate and its encrypted password become current, the old encrypted file and its password are removed, and its safe metadata remains historical

#### Scenario: Remoção de certificado
- **WHEN** an authorized member removes the current certificate
- **THEN** its encrypted file and password are deleted and the client deadline status becomes not registered

### Requirement: Estados derivados de validade
The system SHALL derive the certificate state as not registered, valid, expiring within 30 calendar days, or expired using the application date. The system SHALL derive the procuração e-CAC state in the same four states exclusively from the authorized service families the provider confirmed for the client, and SHALL NOT derive it from any date, code or note typed by a member.

#### Scenario: Vencimento em trinta dias
- **WHEN** a certificate, or the earliest expiration among the authorized service families that make up a client's procuração e-CAC, falls between today and 30 calendar days from today inclusive
- **THEN** its state is returned and displayed as expiring soon with the expiration date

#### Scenario: Item vencido
- **WHEN** the expiration date of the certificate, or of any authorized service family that makes up the client's procuração e-CAC, is earlier than today
- **THEN** its state is returned and displayed as expired

#### Scenario: Procuração sem família confirmada
- **WHEN** the provider has not confirmed, as established, any authorized service family that the client's procuração e-CAC depends on
- **THEN** the procuração state is returned as not registered, even if the client once had procuração data typed by a member

### Requirement: Remoção do cliente elimina segredos ativos
The system SHALL delete active encrypted certificate contents when a client is logically deleted while retaining only non-secret metadata required for history and support auditing.

#### Scenario: Cliente com certificado é excluído
- **WHEN** an authorized member logically deletes a client with a current certificate
- **THEN** the encrypted file is removed and cannot be retrieved through any application endpoint

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

### Requirement: Probes respeitam procuração e-CAC do cliente canário
The system SHALL evaluate the canary client's power of attorney exclusively from provider-confirmed authorized service families before issuing a PGDAS homologation probe, and SHALL treat family `00146` as the gate shared by PGDAS and DEFIS.

#### Scenario: Família 00146 confirmada
- **WHEN** the provider has confirmed family `00146` as established for the canary client
- **THEN** the PGDAS probe may proceed for that client

#### Scenario: Procuração ausente ou expirada
- **WHEN** family `00146` is not established or is expired for the canary client
- **THEN** the probe skips the provider call, reports the client as not eligible, and names procuração e-CAC as the correction

#### Scenario: Pessoa física
- **WHEN** the configured canary identifier belongs to an individual taxpayer
- **THEN** the probe refuses with an ineligibility reason and performs no PGDAS call, consistent with synchronization rules for company clients only
