## ADDED Requirements

### Requirement: Fixture canário PGDAS em homologação
The system SHALL treat one documented company client CNPJ as the integration canary for PGDAS homologation probes, and SHALL validate that a successful probe yields monitoring projection consistent with the requirement on collection-slip status derived from synchronized data — periods with declaration transmission, and when present, an issued DAS with issue timestamp and paid flag.

#### Scenario: Canário com declaração e DAS emitido
- **WHEN** a homologation probe succeeds for the canary client and the provider returns at least one assessment period with a declaration and an issued DAS
- **THEN** the projected monitoring row for `declaracoes/pgdas` includes that period with declaration metadata, slip number, slip issue timestamp and slip paid flag matching the provider response, and does not require an extra provider call to display slip status

#### Scenario: Canário sem períodos
- **WHEN** a homologation probe succeeds but the provider returns no assessment periods for the requested calendar year
- **THEN** the projection records the cause `sem_declaracao` for that client and the probe reports that outcome explicitly rather than failing silently

#### Scenario: Extrato ou declaração detalhada pendente de serviço
- **WHEN** product maintenance requires an extrato or a detailed declaration document beyond what `CONSDECLARACAO13` returns
- **THEN** the probe and documentation state which additional provider service is required before that slice is considered covered, and the monitoring UI is not changed until the service is implemented
