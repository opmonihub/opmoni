## Purpose

Captura, de forma incremental e idempotente, os documentos fiscais eletrônicos que terceiros emitem contra o CNPJ dos clientes do escritório — NF-e e CT-e no escopo inicial — mantendo o cursor de posição, o controle de consumo do serviço público e a visibilidade sobre quem a carteira ainda não permite capturar.

## ADDED Requirements

### Requirement: Captura usa o certificado A1 do próprio cliente
The system SHALL query the distribution service of the national environment on behalf of a client using that client's own A1 certificate and the client's own CNPJ, and SHALL NOT accept a power of attorney, a platform credential or any third party's certificate as a substitute.

#### Scenario: Captura de cliente com certificado
- **WHEN** a capture runs for a client that has a current, unexpired A1 certificate
- **THEN** the request presents that certificate in the transport layer and identifies the client's own CNPJ

#### Scenario: Cliente sem certificado utilizável
- **WHEN** a capture is due for a client that has no current certificate, an expired certificate, or a certificate whose password is not stored
- **THEN** the system performs no outbound call, leaves the capture position unchanged and reports the client as not capturable

#### Scenario: Rejeição por CNPJ sem correspondência
- **WHEN** the service rejects a capture because the queried CNPJ base differs from the certificate's CNPJ base
- **THEN** the system records it as a credential mismatch for that client and does not treat it as a transient failure

### Requirement: Cursor incremental por cliente e fonte
The system SHALL keep one capture position per client and per document source and SHALL advance it only with the value returned by the service, never by incrementing a locally stored value.

#### Scenario: Avanço do cursor
- **WHEN** a batch of documents is returned for a client
- **THEN** the stored position becomes the last position reported in that response

#### Scenario: Resposta sem documentos
- **WHEN** the service reports that no document was located
- **THEN** the stored position is left untouched and the client is put on hold for one hour

#### Scenario: Posição à frente do serviço
- **WHEN** the service reports that the supplied position is greater than the highest it holds
- **THEN** the system marks the client's position as requiring reconciliation and does not discard the stored value

### Requirement: Persistência antes do avanço
The system SHALL persist every document of a batch before advancing the capture position, so that an interruption between the two leaves the batch recoverable rather than lost.

#### Scenario: Falha no meio do lote
- **WHEN** persisting a batch fails partway through
- **THEN** the stored position does not advance and the already stored documents remain

### Requirement: Idempotência por chave de acesso
The system SHALL identify a captured document by the client's document access key together with its event identifier, SHALL treat a repeated capture of the same document as an overwrite rather than a new row, and SHALL validate the access key check digit before accepting it.

#### Scenario: Reprocessamento do mesmo documento
- **WHEN** a document already stored is captured again
- **THEN** its stored copy and metadata are updated in place and no duplicate row is created

#### Scenario: Resumo e documento completo do mesmo documento
- **WHEN** a summary record and later a full authorized document arrive for the same access key
- **THEN** they are stored as two distribution records of one document and both remain retrievable by the access key

#### Scenario: Chave com dígito verificador inválido
- **WHEN** a document arrives whose access key fails check digit validation
- **THEN** the system rejects it, stores nothing and reports the rejection

### Requirement: Decodificação de lote comprimido
The system SHALL decode each document in a returned batch from its compressed base64 payload, SHALL tolerate surrounding whitespace in the encoded payload, and SHALL accept both the documented compression and the alternative form observed in production.

#### Scenario: Lote com documentos válidos
- **WHEN** a batch returns compressed entries
- **THEN** each entry is decoded to a single well-formed XML document attributed to its reported position

#### Scenario: Payload corrompido
- **WHEN** an entry cannot be decoded
- **THEN** the system records the failure for that entry, does not advance past it and does not abort the remaining entries of the batch

### Requirement: Controle de consumo do serviço
The system SHALL stop querying a client for one hour after the service reports no document located or rejects for improper consumption, SHALL NOT retry with a short backoff after such a rejection, and SHALL adopt the position reported inside an improper-consumption rejection when the service supplies one.

#### Scenario: Rejeição por consumo indevido
- **WHEN** the service rejects a capture for improper consumption
- **THEN** the client is blocked for one hour, the reported position is stored if present, and no further attempt occurs before the block expires

#### Scenario: Nova tentativa antes do fim do bloqueio
- **WHEN** a capture is due for a client still inside its block window
- **THEN** the system makes no outbound call and keeps the block

#### Scenario: Consulta por chave ou por posição específica
- **WHEN** the system performs a single-document lookup
- **THEN** it respects the published hourly limit of lookups per CNPJ and defers the remainder instead of consuming further attempts

### Requirement: Interrupção prolongada de sincronização
The system SHALL stop querying a client that has not been captured for more than the published continuity window, SHALL report that client's history as interrupted, and SHALL NOT silently resume, because the service does not generate positions retroactively for the interrupted period.

#### Scenario: Cliente dentro da janela
- **WHEN** a client's last successful capture is within the continuity window
- **THEN** capture proceeds normally

#### Scenario: Cliente além da janela
- **WHEN** a client's last successful capture is older than the continuity window
- **THEN** the system makes no outbound call and reports the client as having an interrupted history

### Requirement: Reconciliação de posições faltantes
The system SHALL provide a scheduled reconciliation that detects positions missing from a client's stored sequence and recovers them within published lookup limits, and SHALL be safe to run repeatedly.

#### Scenario: Lacuna detectada
- **WHEN** reconciliation finds a position missing from the stored sequence
- **THEN** the system attempts to recover that document within the lookup limit and stops after the configured attempt count

#### Scenario: Reconciliação sem lacuna
- **WHEN** reconciliation finds no missing position
- **THEN** no recovery lookup is performed and the client's position is left unchanged

### Requirement: Cobertura da carteira
The system SHALL report, per account, how many clients are capturable and which clients are not, distinguishing the reason — absent, expired, or password not stored — so that an empty result is never ambiguous between "nothing to capture" and "cannot capture".

#### Scenario: Carteira parcialmente capturável
- **WHEN** some clients of the account have a usable certificate and others do not
- **THEN** both the capturable and the not-capturable counts are returned with the reason per client

### Requirement: Documentos abstraídos e gerados por evento
The system SHALL store distribution records together with the event they belong to, identified by the event type and sequence, and SHALL support documents, events and manifests as separate records of the same access key.

#### Scenario: Evento de um documento conhecido
- **WHEN** an event arrives for an access key already stored
- **THEN** the event is stored as its own record linked to that access key

#### Scenario: Documento emitido pelo próprio cliente
- **WHEN** the service reports that a document is unavailable to its own issuer
- **THEN** the system records that as a distinct reason and does not treat it as a capture failure

### Requirement: Módulos fora do escopo
The system SHALL NOT emit documents, SHALL NOT send a recipient manifestation, and SHALL NOT render a printable document from the government service.

#### Scenario: Documento capturado
- **WHEN** a document is captured
- **THEN** no manifestation event is sent and no fiscal document is printed on the office's behalf

#### Scenario: Decisão de manifestar
- **WHEN** a member wishes to confirm receipt of a captured document
- **THEN** the system does not offer to send the manifestation in this version
