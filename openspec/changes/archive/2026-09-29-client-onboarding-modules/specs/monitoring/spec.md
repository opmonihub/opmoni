## ADDED Requirements

### Requirement: Mapa fixo de obrigações sugeridas por regime
The system SHALL keep, in the obligation catalogue, one fixed map from tax regime to suggested monitoring obligations that is the same for every Account, SHALL include in it only obligations classified as `direct` or `derived`, and SHALL NOT let an Account edit the map. The map SHALL suggest PGDAS for Simples Nacional, PGMEI for MEI, no Simples Nacional routine for Lucro Presumido or Lucro Real, and no obligation for a natural person.

#### Scenario: Sugestão para Simples Nacional
- **WHEN** the suggested obligations for a Simples Nacional company are requested
- **THEN** PGDAS is suggested and no obligation classified as `unavailable` or `extinct` is suggested

#### Scenario: Sugestão para MEI
- **WHEN** the suggested obligations for a MEI company are requested
- **THEN** PGMEI is suggested and PGDAS is not

#### Scenario: Sugestão para Lucro Presumido ou Real
- **WHEN** the suggested obligations for a Lucro Presumido or Lucro Real company are requested
- **THEN** neither PGDAS, PGMEI nor any other Simples Nacional routine is suggested

#### Scenario: Mesma sugestão em todas as Accounts
- **WHEN** two Accounts request the suggestion for clients of the same tax regime
- **THEN** both receive the same list of obligations

### Requirement: Associação no cadastro do cliente
The system SHALL expose `GET /api/clients/{client}/monitoring-modules`, returning with 200 each obligation the integration serves with its slug, label, category, whether it is suggested for the client's tax regime and whether the client is already associated with it, and `POST /api/clients/{client}/monitoring-modules` with a list of obligation slugs, creating with 200 one client × obligation association per slug that does not exist yet and returning how many were associated and how many already existed. The association SHALL be created without source data, SHALL enter the next synchronization, SHALL NOT issue any provider call and SHALL NOT consume provider quota. The system SHALL respond 403 to a `user` member on the POST, 404 when the client belongs to another Account, and 422 for an unknown slug, a slug classified as `unavailable` or `extinct`, or a natural-person client. The system SHALL NOT remove an existing association through this endpoint.

#### Scenario: Confirmação dos módulos
- **WHEN** an `admin` or `operador` posts PGDAS and the e-CAC mailbox for a company client of the current Account
- **THEN** the system responds 200 with `associated` equal to 2, both associations exist without source data, and no provider call is recorded

#### Scenario: Associação repetida
- **WHEN** a member posts an obligation the client is already associated with
- **THEN** the system responds 200, counts it as already associated and creates no duplicate

#### Scenario: Obrigação não servida
- **WHEN** a member posts a slug classified as `unavailable` or `extinct`, or a slug that does not exist
- **THEN** the system responds 422 and creates no association

#### Scenario: User tenta associar
- **WHEN** a `user` member posts modules for a client
- **THEN** the system responds 403 and creates no association

#### Scenario: Cliente de outra Account
- **WHEN** a member addresses the modules endpoints with the id of a client of another Account
- **THEN** the system responds 404 and reveals no obligation or association of that client

#### Scenario: Leitura dos módulos
- **WHEN** any member of the current Account, including a `user`, requests the modules of a client
- **THEN** the system responds 200 with the served obligations, the suggestion for the client's regime and the current associations

#### Scenario: Associação entra na próxima execução
- **WHEN** a synchronization runs after the modules were confirmed and the client is eligible for the obligation's family
- **THEN** the client is synchronized for the associated obligations

#### Scenario: Acesso de suporte
- **WHEN** a super_admin in support access confirms the modules of a client
- **THEN** the associations are created as for an `admin`, and a support audit entry records the client id and the associated slugs
