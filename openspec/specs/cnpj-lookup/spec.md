# cnpj-lookup Specification

## Purpose
Fornece consulta segura e resiliente de dados públicos de CNPJ para preencher e atualizar clientes sem expor o navegador diretamente ao provedor externo.

## Requirements

### Requirement: Consulta CNPJ pelo backend
The system SHALL validate and normalize a CNPJ before consulting CNPJ.ws from the backend, and SHALL never require the browser to call the external service directly.

#### Scenario: Consulta válida
- **WHEN** an authorized member submits a valid CNPJ that is not available in a fresh cache entry
- **THEN** the backend consults CNPJ.ws and returns a normalized registration preview

#### Scenario: CNPJ inválido
- **WHEN** a member submits a malformed CNPJ or one with invalid check digits
- **THEN** the system responds with a validation error without calling CNPJ.ws

### Requirement: Resposta externa minimizada
The system SHALL return and persist only the registration, activity, address and contact fields needed by the client form, excluding partners and the unfiltered upstream payload.

#### Scenario: Provedor retorna sócios
- **WHEN** CNPJ.ws includes partner records in a successful response
- **THEN** no partner document, partner record or raw upstream response is returned to the frontend or persisted with the client

### Requirement: Cache e limite da API pública
The system SHALL cache normalized successful lookups and SHALL prevent outbound traffic from exceeding the public provider limit of three requests per minute.

#### Scenario: Consulta repetida
- **WHEN** the same CNPJ is requested again while its cached result is fresh
- **THEN** the cached preview is returned without consuming another outbound request

#### Scenario: Limite atingido
- **WHEN** no fresh cache exists and the outbound rate limit is exhausted
- **THEN** the system responds 429 with a recoverable message and does not create or modify a client

#### Scenario: Provedor indisponível
- **WHEN** CNPJ.ws times out or returns a server error and no fresh cache is available
- **THEN** the system returns a recoverable service error and preserves existing client data

### Requirement: Regime tributário híbrido
The system SHALL derive MEI or Simples Nacional when indicated by CNPJ.ws and SHALL require manual selection of Lucro Presumido, Lucro Real or Outro when the company is not opted into either regime.

#### Scenario: Empresa optante pelo Simples
- **WHEN** the normalized lookup reports Simples Nacional and not MEI
- **THEN** the preview fixes the regime as Simples Nacional

#### Scenario: Empresa fora do Simples
- **WHEN** the normalized lookup reports neither MEI nor Simples Nacional
- **THEN** the form requires the member to select Lucro Presumido, Lucro Real or Outro before saving

### Requirement: Atualização cadastral confirmada
The system SHALL allow a member to request a fresh registration preview for an existing CNPJ and SHALL require confirmation before replacing locally stored registration fields.

#### Scenario: Prévia contém alterações
- **WHEN** a new lookup differs from the stored company registration fields
- **THEN** the page shows the differences and leaves stored data unchanged until the member confirms

#### Scenario: Atualização confirmada
- **WHEN** the member confirms the registration refresh
- **THEN** the normalized fields and source timestamps are updated while internal status and fiscal-access records remain unchanged
