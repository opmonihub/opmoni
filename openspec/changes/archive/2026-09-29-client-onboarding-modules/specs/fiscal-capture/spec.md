## ADDED Requirements

### Requirement: Todo cliente entra na captura
The system SHALL include every active client of every Account in the scheduled XML capture without any per-client opt-in, SHALL attempt capture only for clients with a usable certificate, and SHALL report every other client as not capturable with its reason (absent, expired, or password not stored) instead of excluding it silently.

#### Scenario: Cliente novo com certificado
- **WHEN** a client is created and a usable A1 certificate is uploaded, with no capture option ever selected
- **THEN** the client is captured by the scheduled capture like every other capturable client

#### Scenario: Cliente sem certificado utilizável
- **WHEN** the scheduled capture runs and a client has no certificate, an expired certificate or no stored password
- **THEN** no outbound call is made for that client and it is reported as not capturable with that reason

### Requirement: Captura imediata após upload do certificado
The system SHALL, after an authorized member uploads a usable A1 certificate for a client, queue a capture of that client for each document source enabled in the installation, without waiting for the next scheduled capture and without making the upload wait for the service. The queued job SHALL carry the client's Account explicitly and SHALL only act on a client of that Account. The system SHALL NOT queue a capture for a source whose client is inside a block window, and the one-hour block and the per-client and per-source lock SHALL still apply when the job runs. The upload response SHALL keep status 200 and SHALL report the capture outcome as `queued`, `blocked` with the block end, or `not_capturable` with the reason, and SHALL contain no certificate content, password or storage path.

#### Scenario: Upload de certificado utilizável
- **WHEN** an `admin` or `operador` uploads a valid certificate for a client that is not blocked
- **THEN** the system responds 200, reports the capture as `queued`, and a capture job for that client and its Account is queued for each enabled source

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
