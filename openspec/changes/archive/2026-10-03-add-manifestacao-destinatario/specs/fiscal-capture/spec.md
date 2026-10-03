## MODIFIED Requirements

### Requirement: Módulos fora do escopo
The system SHALL NOT emit documents and SHALL NOT render a printable document from the government service. Sending a recipient manifestation SHALL no longer be prohibited by this capability — it SHALL follow the `fiscal-manifestacao` capability and its configuration gate — while this capability itself SHALL NOT send the confirmation of operation (210200) automatically.

#### Scenario: Documento capturado
- **WHEN** a document is captured
- **THEN** no fiscal document is printed on the office's behalf

#### Scenario: Manifestação do destinatário
- **WHEN** a summary is stored and the manifestation gate is enabled
- **THEN** the sending of the recipient manifestation follows the `fiscal-manifestacao` capability, and this capability performs no additional outbound call for it beyond queuing

#### Scenario: Decisão de manifestar
- **WHEN** a member wishes to confirm receipt of a captured document
- **THEN** the system offers no manual confirmation of operation (210200), because automating it would prevent the issuer from cancelling the document