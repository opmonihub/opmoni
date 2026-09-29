## ADDED Requirements

### Requirement: Coluna de status de certificado na tabela XML
The system SHALL show, on each row of the documents table at `/fiscal/documentos`, the current certificate status of the row's client as one of sem certificado, vencido, sem senha armazenada, a vencer or válido, derived with the same rules as the capture coverage, and SHALL return that status in the documents listing as a non-secret code (`missing`, `expired`, `password_missing`, `expiring`, `valid`) without certificate content, password or storage path. Clients that have no document yet SHALL remain visible, with the same reason, in the fiscal dashboard attention list.

#### Scenario: Cliente com certificado válido
- **WHEN** a member opens the documents table and a row's client has a usable certificate expiring more than 30 days from today
- **THEN** the row's certificate column shows válido

#### Scenario: Cliente com certificado vencido ou sem senha
- **WHEN** a row's client has an expired certificate or a certificate without a stored password
- **THEN** the row's certificate column shows vencido or sem senha armazenada, using the semantic error or warning colour

#### Scenario: Cliente sem certificado
- **WHEN** a row's client has no current certificate
- **THEN** the row's certificate column shows sem certificado

#### Scenario: Resposta sem segredos
- **WHEN** the documents listing is requested
- **THEN** each row carries only the status code for the client's certificate and no certificate content, password or storage path

#### Scenario: Documentos de outra Account
- **WHEN** a member requests the documents listing
- **THEN** only documents and certificate statuses of clients of the current Account are returned
