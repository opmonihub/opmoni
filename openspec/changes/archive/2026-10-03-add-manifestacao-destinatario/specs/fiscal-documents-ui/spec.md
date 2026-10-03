## ADDED Requirements

### Requirement: Estado de completude do XML na tabela
The system SHALL show, on each row of the documents table at `/fiscal/documentos`, the completeness state of the row's stored XML as one of resumo aguardando XML or XML completo, derived from the stored distribution records of the row's access key, so the office can tell apart a document whose full XML is still unlocked from one that is fully stored. The state SHALL be returned as a non-secret code (`summary_awaiting_xml`, `complete`) and SHALL NOT reveal manifestation details, certificate material or XML content.

#### Scenario: Linha com apenas resumo
- **WHEN** a member opens the documents table and a row's access key has only a summary record stored
- **THEN** the row shows resumo aguardando XML with the semantic warning colour

#### Scenario: Linha com XML completo
- **WHEN** a row's access key has the full authorized document stored
- **THEN** the row shows XML completo with the neutral colour

#### Scenario: Linha sem estado de completude aplicável
- **WHEN** a row's access key is not an NF-e distributed summary awaiting its full XML (a CT-e document, a key whose records are events only, or a document issued by the client's own CNPJ)
- **THEN** the row carries no completeness code (null) and the table renders no completeness state for it

#### Scenario: Resposta sem segredos
- **WHEN** the documents listing is requested
- **THEN** each row carries only the completeness code and no XML content, manifestation payload or certificate material

#### Scenario: Documentos de outra Account
- **WHEN** a member requests the documents listing
- **THEN** only the completeness states of documents of clients of the current Account are returned