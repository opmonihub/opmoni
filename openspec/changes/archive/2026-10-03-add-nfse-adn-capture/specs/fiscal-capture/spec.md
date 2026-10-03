## ADDED Requirements

### Requirement: Fonte ADN NFS-e para contribuintes
The system SHALL capture NFS-e and related DF-e from the national NFS-e Data Environment (ADN) contribuintes distribution API as a separate document source from NF-e and CT-e distribution, using the client's own A1 certificate on the transport layer and the same incremental cursor, gap, reconciliation and lookup-budget rules as other sources.

#### Scenario: Captura incremental por NSU
- **WHEN** a capture runs for source `nfse_adn` with a usable certificate and the installation has NFS-e capture enabled
- **THEN** the system requests the next batch from the ADN starting at the stored position for that client and source, persists every decoded document before advancing the position, and stores the position returned by the service without locally incrementing it

#### Scenario: Fonte desligada na instalação
- **WHEN** NFS-e capture is disabled in installation configuration
- **THEN** no outbound ADN call is made for that source, no job is queued for it on certificate upload, and the dispatcher reports the source as unavailable for this version

#### Scenario: Resposta de negócio com status HTTP enganoso
- **WHEN** the ADN returns a business outcome that means no document or a classified rejection while using an HTTP status that would normally mean transport failure
- **THEN** the system classifies the outcome from the response body, applies the same cursor, block and gap rules as for an explicit success response, and does not treat it as an unclassified network error

### Requirement: Chaves de acesso da NFS-e nacional
The system SHALL accept and persist NFS-e national access keys of fifty digits with their own check-digit validation, SHALL store them in the same idempotent identity as other fiscal documents, and SHALL NOT truncate or reject a valid fifty-digit key because other models use forty-four digits.

#### Scenario: Documento NFS-e com chave válida de 50 dígitos
- **WHEN** the ADN delivers a well-formed NFS-e whose access key has fifty digits and a valid check digit
- **THEN** the system stores the document under model `nfse` and source `nfse_adn` with the full key and attributes the reported NSU to that delivery

#### Scenario: Chave NFS-e com dígito verificador inválido
- **WHEN** a delivered payload exposes an access key of fifty digits that fails check-digit validation
- **THEN** the system rejects the entry, stores nothing for that position, and records the failure according to the gap rules without advancing past an unreadable entry

### Requirement: Probe operacional da ADN NFS-e
The system SHALL provide a synchronous operator command that performs a single ADN contribuintes lookup for one client and one NSU using that client's certificate, prints only safe metadata (HTTP status, top-level JSON fields, item counts, NSU list), and SHALL NOT log or print certificate passwords, raw XML or full taxpayer-identifying payload.

#### Scenario: Probe bem-sucedido
- **WHEN** an authorized operator runs the probe for a capturable client and a valid NSU parameter
- **THEN** the command completes with a classified summary of the response and makes no database writes unless an explicit save-fixture option is used to write an anonymized fixture file

#### Scenario: Cliente sem certificado utilizável
- **WHEN** the probe is run for a client without a usable certificate
- **THEN** the command performs no outbound call and exits with a clear not-capturable reason

## MODIFIED Requirements

### Requirement: Idempotência por chave de acesso
The system SHALL identify a captured document by the client's document access key together with its event identifier, SHALL treat a repeated capture of the same document as an overwrite rather than a new row, and SHALL validate the access key check digit before accepting it. Access keys SHALL be either forty-four digits for NF-e, NFC-e and CT-e family documents or fifty digits for NFS-e national documents.

#### Scenario: Reprocessamento do mesmo documento
- **WHEN** a document already stored is captured again
- **THEN** its stored copy and metadata are updated in place and no duplicate row is created

#### Scenario: Resumo e documento completo do mesmo documento
- **WHEN** a summary record and later a full authorized document arrive for the same access key
- **THEN** they are stored as two distribution records of one document and both remain retrievable by the access key

#### Scenario: Chave com dígito verificador inválido
- **WHEN** a document arrives whose access key fails check digit validation for its length
- **THEN** the system rejects it, stores nothing and reports the rejection

### Requirement: Captura imediata após upload do certificado
The system SHALL, after an authorized member uploads a usable A1 certificate for a client, queue a capture of that client for each document source enabled in the installation, without waiting for the next scheduled capture and without making the upload wait for the service. The queued job SHALL carry the client's Account explicitly and SHALL only act on a client of that Account. The system SHALL NOT queue a capture for a source whose client is inside a block window, and the one-hour block and the per-client and per-source lock SHALL still apply when the job runs. The upload response SHALL keep status 200 and SHALL report the capture outcome as `queued`, `blocked` with the block end, or `not_capturable` with the reason, and SHALL contain no certificate content, password or storage path. Enabled sources SHALL include ADN NFS-e when NFS-e capture is enabled in installation configuration.

#### Scenario: Upload de certificado utilizável
- **WHEN** an `admin` or `operador` uploads a valid certificate for a client that is not blocked
- **THEN** the system responds 200, reports the capture as `queued`, and a capture job for that client and its Account is queued for each enabled source including `nfse_adn` when NFS-e capture is enabled

#### Scenario: Cliente em bloqueio
- **WHEN** a valid certificate is uploaded for a client inside a block window for a source
- **THEN** no capture is queued for that source, the response reports `blocked` with the block end, and no outbound call is made before the block expires

#### Scenario: Certificado vencido
- **WHEN** the uploaded certificate is valid as a file but already expired
- **THEN** the certificate is stored, no capture is queued and the response reports `not_capturable` with the expired reason

#### Scenario: Job de outra Account
- **WHEN** the queued capture runs in a worker whose current tenant is empty or belongs to another Account
- **THEN** the job acts only on the client of the Account it carries, and does nothing if that client no longer exists in that Account

#### Scenario: Acesso de suporte
- **WHEN** a super_admin in support access uploads a certificate that queues a capture
- **THEN** the upload and the capture behave as for an `admin`, and a support audit entry records the client id and the queued sources without certificate material

#### Scenario: User tenta enviar certificado
- **WHEN** a `user` member uploads a certificate
- **THEN** the system responds 403, stores nothing and queues no capture
