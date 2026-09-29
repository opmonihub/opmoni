# client-fiscal-access Specification

## Purpose
Centraliza o certificado digital A1 e a procuração e-CAC de cada cliente, protegendo segredos e tornando vencimentos e ausências visíveis na carteira do escritório.

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

### Requirement: Controle da procuração e-CAC
The system SHALL allow `admin` and `operador` members to register, update or remove a client's procuração e-CAC using start date, expiration date, the Serpro-issued procuração code and optional notes, SHALL keep the Serpro integration state of that procuração current, and SHALL still not require a procuração file in this version.

#### Scenario: Procuração cadastrada
- **WHEN** an authorized member provides a valid start date and an expiration date on or after it
- **THEN** the procuração metadata is associated with the client and its deadline status is returned

#### Scenario: Datas incoerentes
- **WHEN** the expiration date precedes the start date
- **THEN** the system responds with a validation error and preserves the previous procuração data

#### Scenario: Código de procuração do SERPRO registrado
- **WHEN** an authorized member provides the procuração code issued by the Serpro service for a client
- **THEN** the code is associated with the client, is returned as non-secret metadata, and the client's eligibility to be acted upon is reported as pending until the Serpro side confirms it

#### Scenario: Confirmação da procuração pelo SERPRO
- **WHEN** the Serpro service confirms that the procuração of a client is established
- **THEN** the stored integration state for that client becomes established and the client becomes eligible to be acted upon

#### Scenario: Procuração recusada pelo SERPRO
- **WHEN** the Serpro service rejects a procuração submitted for a client
- **THEN** the client is marked as not eligible, no data is requested on its behalf, and the rejection is presented as a readable reason

#### Scenario: Procuração não aplicável a pessoa física
- **WHEN** a member registers a procuração for a client that is a natural person and the Serpro service does not accept that authorization form
- **THEN** the system keeps the client's eligibility unchanged, does not present the integration as established, and does not discard the stored dates

#### Scenario: Remoção da procuração
- **WHEN** an authorized member removes a client's procuração
- **THEN** the client immediately stops being eligible to be acted upon and previously synchronized data is retained rather than deleted

#### Scenario: Procuração expira
- **WHEN** a client's procuração expiration date is earlier than the current date
- **THEN** the client is reported as not eligible, the monitoring view marks it as not covered by the integration, and its previously synchronized data is retained and labelled as out of date

#### Scenario: Segredo da procuração não exposto
- **WHEN** a member requests a client that has a procuração with a Serpro code
- **THEN** the response contains the dates, the code and the integration state but no procuração file, no stored credential and no internal storage path

### Requirement: Estados derivados de validade
The system SHALL derive certificate and procuração states as not registered, valid, expiring within 30 calendar days, or expired using the application date.

#### Scenario: Vencimento em trinta dias
- **WHEN** a certificate or procuração expires between today and 30 calendar days from today inclusive
- **THEN** its state is returned and displayed as expiring soon with the expiration date

#### Scenario: Item vencido
- **WHEN** its expiration date is earlier than today
- **THEN** its state is returned and displayed as expired

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
