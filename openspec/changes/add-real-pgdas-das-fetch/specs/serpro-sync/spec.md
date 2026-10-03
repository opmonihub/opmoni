## ADDED Requirements

### Requirement: Probes de homologação para PGDAS
The system SHALL provide an opt-in integration probe that performs a real provider call for the obligation `declaracoes/pgdas` against the homologation environment, SHALL reuse the same envelope, authentication and synchronization path used by a normal run for one client, and SHALL NOT be included in the default PHPUnit suite.

#### Scenario: Probe com homologação configurada
- **WHEN** an operator runs the probe with homologation selected, a usable platform connection, a valid authorization term for the Account and an eligible company client
- **THEN** the system issues `PGDASD/CONSDECLARACAO13` for that client, parses the provider answer, projects monitoring fields, and reports success or a readable failure without exposing raw provider payloads, secrets or certificate contents

#### Scenario: Probe fora de homologação
- **WHEN** the fiscal environment is not homologation and no explicit override flag is supplied
- **THEN** the probe refuses to start before any provider call and names the environment mismatch

#### Scenario: Credencial ou termo ausente
- **WHEN** the platform connection is incomplete or the Account has no valid authorization term
- **THEN** the probe stops with a configuration reason and performs no provider call

#### Scenario: Cliente não elegível
- **WHEN** the chosen client lacks a valid power of attorney for family `00146`
- **THEN** the probe reports the client as not eligible and performs no billable provider call for PGDAS

### Requirement: Loop de validação repetível
The system SHALL expose a repeatable validation entry point — automated test group or console command — that an operator can run after changes to Serpro transport, catalog or monitoring projection, and SHALL produce a concise pass, skip or fail outcome per check without requiring the monitoring UI.

#### Scenario: Execução do loop após alteração
- **WHEN** an operator runs the validation loop with homologation and prerequisites satisfied
- **THEN** the output states whether declaration consultation, period projection and issued DAS fields were validated for the canary client, and lists any readable failure codes returned by the provider

#### Scenario: Cota ou limite do provedor
- **WHEN** the provider answers with a quota or throttle code during the loop
- **THEN** the loop marks the affected step as skipped with the provider reason rather than reporting a regression failure
