## ADDED Requirements

### Requirement: Probes reutilizam a conexão de plataforma com gate de ambiente
The system SHALL let homologation probes obtain access through the same platform credential, token reuse and authorization envelope as production synchronization, SHALL require homologation as the default target for probes, and SHALL NOT log consumer secrets, certificate passwords, access tokens or signed authorization documents during a probe.

#### Scenario: Autenticação compartilhada
- **WHEN** a homologation probe runs with a configured platform credential
- **THEN** token acquisition follows the same reuse rules as an ordinary provider call and at most one new token pair is obtained while tokens remain valid

#### Scenario: Prova de contrato em homologação
- **WHEN** a probe runs and the Account lacks a valid authorization term
- **THEN** the probe stops with the same class of term problem used for synchronization and performs no PGDAS call

#### Scenario: Falha sem vazamento de segredo
- **WHEN** authentication, signing or a provider call fails during a probe
- **THEN** the recorded outcome contains no consumer secret, certificate content, password, token or signed document
