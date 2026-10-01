# fiscal-documents-ui Specification

## Purpose
Entrega ao escritório as duas telas do módulo fiscal: um painel que responde como a captura está indo na carteira, e uma tabela única onde NF-e e CT-e capturadas são consultadas, filtradas e baixadas.

## Requirements

### Requirement: Painel da captura
The system SHALL provide a fiscal dashboard at `/fiscal` showing captured document totals, per-model volume, documents over time, and the outcome of the most recent capture.

#### Scenario: Painel com dados
- **WHEN** an authorized member opens the fiscal dashboard for an account with captured documents
- **THEN** totals per model, the documents over time series and the last capture outcome are shown

#### Scenario: Painel sem documentos
- **WHEN** the account has no captured document yet
- **THEN** the panel renders its empty state and does not present zeros as if they were a measurement

### Requirement: Cobertura como leitura primária
The system SHALL lead the fiscal dashboard with the share of the portfolio that is capturable, counted and reported separately from the document totals, because on the first days most clients have no usable certificate.

#### Scenario: Nenhum cliente capturável
- **WHEN** no client of the account has a usable certificate
- **THEN** coverage reads as zero capturable and the dashboard states the reason per client instead of showing an empty document table as the only signal

#### Scenario: Cobertura parcial
- **WHEN** some clients are capturable and others are not
- **THEN** both counts are shown and the non-capturable clients are listed with their reason

### Requirement: Lista de atenção operacional
The system SHALL show a single attention list on the fiscal dashboard grouping, by reason, the clients and captures that need a human action: certificate absent, certificate expired, password not stored, capture blocked by improper consumption, and capture history interrupted.

#### Scenario: Cliente com certificado vencido
- **WHEN** a capturable client's certificate has expired
- **THEN** the client appears in the attention list under the expired reason and not in the capturable count

#### Scenario: Captura bloqueada
- **WHEN** a client is inside a block window after an improper-consumption rejection
- **THEN** the client appears in the attention list with the block and its remaining time

#### Scenario: Histórico interrompido
- **WHEN** a client's capture history is reported as interrupted
- **THEN** the client appears in the attention list and the dashboard states that the missed period cannot be recovered by continuing capture

#### Scenario: Nada em atenção
- **WHEN** no client is in any attention state
- **THEN** the panel reports that no client requires action

### Requirement: Tabela unificada de documentos
The system SHALL provide a single documents table at `/fiscal/documentos` listing captured NF-e and CT-e with the model, access key, issuer, recipient, total value, issuance date and event count per row.

#### Scenario: Listagem paginada
- **WHEN** an authorized member opens the documents table
- **THEN** the documents are listed in reverse issuance order, paginated, with the model shown per row

#### Scenario: Documento sem eventos
- **WHEN** a captured document has no event
- **THEN** the row shows an explicit absence of events rather than an empty or misleading value

#### Scenario: Documento de cliente removido
- **WHEN** a stored document belongs to a logically deleted client
- **THEN** the row remains retrievable and identifies the client by its retained non-secret identity

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

### Requirement: Filtros da tabela
The system SHALL allow filtering the documents table by model, client, issuer, recipient, date range and document kind, and SHALL keep the filter in the URL so the view is shareable and survives reload.

#### Scenario: Filtro por modelo
- **WHEN** a member filters by one or more models
- **THEN** only documents of those models are listed and the model filter offers the values present in the filtered result plus the currently selected ones, so a filter that matches nothing can still be removed

#### Scenario: Filtro preservado
- **WHEN** a member reloads a filtered view or opens it in another tab
- **THEN** the same filters are applied

#### Scenario: Combinação sem resultado
- **WHEN** a filter combination matches no document
- **THEN** the table reports that no document matches rather than an error

### Requirement: Detalhe e download do XML
The system SHALL let a member open a captured document's detail, showing its extracted metadata and the timeline of its events, and SHALL let them download the stored XML.

#### Scenario: Abertura do detalhe
- **WHEN** a member opens the detail of a captured document
- **THEN** its metadata, its events in chronological order and a preview of the stored XML are shown

#### Scenario: Download do XML
- **WHEN** a member downloads a captured document
- **THEN** the stored XML is returned as a file and no other client document is retrievable through the same request

#### Scenario: Download entre contas
- **WHEN** a member of another account addresses a document id or a client document download
- **THEN** the system responds as not found and reveals no metadata

### Requirement: Captura sob demanda
The system SHALL let an authorized member trigger a capture for a single client and report the resulting position, without blocking on the service response.

#### Scenario: Captura disparada
- **WHEN** an authorized member triggers capture for a client that is not blocked
- **THEN** the request is accepted for background processing and the member sees that it is queued

#### Scenario: Cliente bloqueado
- **WHEN** a member triggers capture for a client inside a block window
- **THEN** the system explains that the client is on hold and when capture may resume, and makes no outbound call

### Requirement: Permissões das telas fiscais
The system SHALL restrict viewing fiscal documents to members of the account that owns them, SHALL restrict triggering a capture to `admin` and `operador`, and SHALL hide capture actions from a member who may only read.

#### Scenario: Membro somente leitura
- **WHEN** a member whose role may only read opens the fiscal section
- **THEN** documents are visible and the capture trigger is absent or disabled

#### Scenario: Papel de captura
- **WHEN** an `admin` or `operador` member views a client's documents
- **THEN** the capture trigger is available for that client

### Requirement: Navegação da seção fiscal
The system SHALL expose the fiscal section in the main navigation as a group with the dashboard and the documents table, and SHALL mark the current page as active.

#### Scenario: Entrada no menu
- **WHEN** an authorized member views the main navigation
- **THEN** the fiscal group is present with its two pages

#### Scenario: Grupo expandido
- **WHEN** the member is on a fiscal page
- **THEN** the fiscal group is expanded and the current page is highlighted
