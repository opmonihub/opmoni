## Purpose

Envia a manifestação do destinatário de NF-e (ciência da emissão, 210210) em nome do cliente, com o certificado A1 dele e o XML assinado digitalmente, para que o Ambiente Nacional gere NSU próprio com o procNFe e destrave o XML completo — complementando a captura incremental (`fiscal-capture`) e a consulta pontual por chave no fluxo recomendado distNSU + ciência cedo + `consChNFe`.

## ADDED Requirements

### Requirement: Manifestação usa o certificado A1 do próprio cliente e XML assinado
The system SHALL send a recipient manifestation on behalf of a client using that client's own A1 certificate, presenting the certificate in the transport layer and signing the event XML digitally with that certificate, and SHALL NOT send an unsigned event or use a platform credential, another account's certificate or a power of attorney as a substitute.

#### Scenario: Evento assinado com o A1 do cliente
- **WHEN** a manifestation is due for a client with a current, unexpired A1 certificate and a stored password
- **THEN** the event XML is signed with that client's certificate and the request presents the same certificate in the transport layer

#### Scenario: Cliente sem certificado utilizável
- **WHEN** a manifestation is due for a client with no current certificate, an expired certificate or no stored password
- **THEN** the system performs no outbound call, records the manifestation as not sendable with that reason, and leaves any pending state unchanged

#### Scenario: Evento nunca sai sem assinatura
- **WHEN** the signature of the event XML fails for any reason
- **THEN** the system performs no outbound call, records the failure and retries later instead of sending an unsigned event

### Requirement: Só ciência da emissão automatizada
The system SHALL automate only the awareness of the issuance event (210210), SHALL NOT send the confirmation of operation (210200) automatically, and SHALL NOT send any manifestation after the published 90-day deadline from the document's authorization.

#### Scenario: Resumo capturado dentro do prazo
- **WHEN** a summary of a document issued against a client is stored and the document's authorization is within 90 days
- **THEN** the system queues the awareness of the issuance event for that document and client

#### Scenario: Documento fora do prazo
- **WHEN** the document's authorization is older than 90 days
- **THEN** the system does not queue or send any manifestation for it and records the deadline as missed rather than retrying

#### Scenario: Confirmação da operação nunca automática
- **WHEN** the manifestation pipeline runs for any client or document
- **THEN** only the awareness event (210210) is sent, and the confirmation of operation (210200) is never sent without an explicit product decision recorded outside this capability

### Requirement: Idempotência e rejeição 573 como estado conhecido
The system SHALL identify a manifestation by the client, the access key and the event type and sequence, SHALL treat a repeated manifestation as an overwrite rather than a duplicate send, and SHALL classify the rejection 573 (duplicate event, already manifested by another system) as a known state — recording the document as already manifested and proceeding to the single-document lookup instead of retrying the event.

#### Scenario: Reprocessamento do mesmo evento
- **WHEN** a manifestation is attempted again for an access key already manifested by the platform
- **THEN** no second event is sent and the stored manifestation state is updated in place

#### Scenario: Rejeição 573 do Ambiente Nacional
- **WHEN** the service rejects the event with status 573 because the document was already manifested by another system
- **THEN** the system records the document as already manifested, does not treat it as a transient failure, and the pending XML recovery may proceed through the existing single-document lookup

#### Scenario: Rejeição transitória
- **WHEN** the service rejects the event for a reason classified as transient
- **THEN** the system retries later within the consumption windows and does not mark the document as manifested

### Requirement: Gate de configuração
The system SHALL send manifestations only when the installation-level gate is enabled, SHALL default the gate to disabled, and SHALL keep every other capture behavior unchanged when the gate is disabled.

#### Scenario: Gate desligado
- **WHEN** the manifestation gate is disabled
- **THEN** no manifestation event is queued or sent, and capture, lookup and reconciliation behave exactly as before

#### Scenario: Gate ligado
- **WHEN** the manifestation gate is enabled
- **THEN** queued manifestations follow the other requirements of this capability

### Requirement: Janelas de consumo e orçamento
The system SHALL respect the service's consumption discipline when sending manifestations: SHALL NOT retry with a short backoff after a rejection, SHALL respect the client's existing block windows from improper-consumption rejections, and SHALL NOT spend the hourly lookup budget of a CNPJ on manifestation attempts, keeping the budget dedicated to single-document lookups.

#### Scenario: Cliente em bloqueio
- **WHEN** a manifestation is due for a client inside a block window
- **THEN** no outbound call is made and the manifestation waits until the block expires

#### Scenario: Orçamento preservado
- **WHEN** manifestations run for a client
- **THEN** the manifestation attempts do not debit the hourly lookup budget and the single-document lookups keep their full published limit

### Requirement: Recuperação do XML pela consulta pontual existente
The system SHALL recover the full XML of a manifested document through the existing single-document lookup by access key, respecting its published hourly limit and block windows, and SHALL NOT introduce a new download service for it.

#### Scenario: Resumo pendente após ciência
- **WHEN** a document has a stored summary, is already manifested, and has no full XML yet
- **THEN** the pending resynchronization attempts the single-document lookup for that access key within the hourly limit and defers the remainder

#### Scenario: Reconciliação de resumos pendentes
- **WHEN** the scheduled resynchronization runs for a client with several summaries awaiting their full XML
- **THEN** lookups are made one key at a time within the limit, the attempts per key are bounded, and repeated runs are safe

### Requirement: Auditoria e tenancy da manifestação
The system SHALL record, for every manifestation sent or classified, who or what requested it, the operation, the outcome and the date, SHALL scope every manifestation record to an Account with an explicit `account_id` carried by the job, and SHALL act only on clients of the Account the job carries.

#### Scenario: Job de outra Account
- **WHEN** a queued manifestation runs in a worker whose current tenant is empty or belongs to another Account
- **THEN** the job acts only on the client of the Account it carries, and does nothing if that client no longer exists in that Account

#### Scenario: Registro de auditoria
- **WHEN** a manifestation is sent, rejected or classified as already manifested
- **THEN** an audit entry records the client id, the access key, the event type, the outcome and the requesting member or job, without certificate material, password or event XML

#### Scenario: Acesso de suporte
- **WHEN** a super_admin in support access enables or runs anything that sends manifestations for a client
- **THEN** the support audit entry records the client and the operation, and the manifestation behaves as for an `admin`

### Requirement: Segredos da manifestação
The system SHALL NOT log the certificate password, the raw event XML, the signed envelope or any transport token, and SHALL store no signature key material outside the client's existing certificate storage.

#### Scenario: Falha de transporte registrada
- **WHEN** a manifestation fails at the transport or signature layer
- **THEN** the recorded failure message contains no certificate password, no raw XML and no token

#### Scenario: Auditoria sem material sensível
- **WHEN** any manifestation record or log line is written
- **THEN** it carries identifiers and outcomes only