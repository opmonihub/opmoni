# client-fiscal-access Specification

## Purpose
Centraliza o certificado digital A1 e a procuração e-CAC de cada cliente, protegendo segredos e tornando vencimentos e ausências visíveis na carteira do escritório.

## Requirements

### Requirement: Upload seguro de certificado A1
The system SHALL accept a password-protected PFX/P12 certificate only when its password unlocks a parseable certificate, SHALL extract its non-secret metadata and SHALL never persist or log the supplied password.

#### Scenario: Certificado válido
- **WHEN** an authorized member uploads a valid PFX/P12 with the correct password for a client in the current Account
- **THEN** the system stores an encrypted private copy and returns only safe metadata including subject, serial and validity dates

#### Scenario: Senha incorreta
- **WHEN** the supplied password cannot unlock the uploaded certificate
- **THEN** the system responds with a validation error and stores neither a database record nor a file

#### Scenario: Arquivo inválido
- **WHEN** the upload exceeds the allowed size or is not a valid PFX/P12 certificate
- **THEN** the system rejects it without replacing the current valid certificate

### Requirement: Arquivo privado e resposta sem segredos
The system SHALL encrypt certificate contents before storage on a non-public disk and SHALL not expose certificate contents, passwords, encryption material or internal storage paths through the API.

#### Scenario: Consulta do cliente com certificado
- **WHEN** a member requests a client that has an A1 certificate
- **THEN** the response contains deadline status and safe metadata but no file contents, password or storage path

#### Scenario: Tentativa entre Accounts
- **WHEN** a member of another Account addresses the certificate endpoint using the client id
- **THEN** the system responds 404 and reveals no certificate metadata

### Requirement: Substituição e remoção do certificado
The system SHALL permit `admin` and `operador` members to replace or remove the current certificate while retaining non-secret historical metadata and deleting obsolete encrypted file contents.

#### Scenario: Substituição bem-sucedida
- **WHEN** an authorized member uploads a new valid certificate for a client that already has one
- **THEN** the new certificate becomes current, the old encrypted file is removed and its safe metadata remains historical

#### Scenario: Remoção de certificado
- **WHEN** an authorized member removes the current certificate
- **THEN** its encrypted file is deleted and the client deadline status becomes not registered

### Requirement: Controle da procuração e-CAC
The system SHALL allow `admin` and `operador` members to register, update or remove a client's procuração e-CAC using start date, expiration date and optional notes without storing a procuração file in this version.

#### Scenario: Procuração cadastrada
- **WHEN** an authorized member provides a valid start date and an expiration date on or after it
- **THEN** the procuração metadata is associated with the client and its deadline status is returned

#### Scenario: Datas incoerentes
- **WHEN** the expiration date precedes the start date
- **THEN** the system responds with a validation error and preserves the previous procuração data

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
