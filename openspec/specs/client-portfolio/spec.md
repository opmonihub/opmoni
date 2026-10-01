# client-portfolio Specification

## Purpose
Permite que cada Account mantenha uma carteira isolada de clientes pessoa jurídica ou física, com cadastro fiscal, consulta, filtros e operações completas no painel operacional.

## Requirements

### Requirement: Cliente pertence à carteira do Account
The system SHALL associate every client with exactly one Account and SHALL enforce CPF/CNPJ uniqueness within that Account while allowing the same document in different Accounts.

#### Scenario: Documento duplicado na mesma carteira
- **WHEN** a member attempts to create a second active client with the same normalized CPF or CNPJ in the current Account
- **THEN** the system rejects the request with a validation error and creates no duplicate

#### Scenario: Documento compartilhado entre escritórios
- **WHEN** two different Accounts register the same normalized CPF or CNPJ
- **THEN** each Account receives its own isolated client record

### Requirement: Cadastro por tipo de pessoa
The system SHALL support pessoa jurídica identified by a valid CNPJ and pessoa física identified by a valid CPF, storing normalized documents and rejecting invalid check digits.

#### Scenario: Pessoa jurídica válida
- **WHEN** an authorized member confirms a valid consulted CNPJ and the required client fields
- **THEN** the system creates a pessoa jurídica client in the current Account

#### Scenario: Pessoa física válida
- **WHEN** an authorized member submits a valid CPF, name and contact data manually
- **THEN** the system creates a pessoa física client with tax regime `not_applicable`

#### Scenario: Documento inválido
- **WHEN** a member submits a CPF or CNPJ with invalid check digits
- **THEN** the system responds with a validation error and creates nothing

### Requirement: Situações cadastral e operacional independentes
The system SHALL maintain an internal active/inactive status independently from the official CNPJ registration status returned by the external source.

#### Scenario: Empresa baixada mantida na carteira
- **WHEN** a CNPJ lookup reports an official status other than active and the member confirms the registration
- **THEN** the system allows the client to be saved, displays the official warning and preserves the separately selected internal status

#### Scenario: Cliente inativado pelo escritório
- **WHEN** an authorized member marks an internally active client as inactive
- **THEN** the official registration status remains unchanged and the table shows the client as internally inactive

### Requirement: CRUD conforme nível do membro
The system SHALL allow `admin` and `operador` members to create, update, activate, deactivate and logically delete clients, while `user` members can only read clients in the current Account.

#### Scenario: Operador altera cliente
- **WHEN** an `operador` updates a client in the current Account
- **THEN** the changes are persisted and the updated client is returned

#### Scenario: User tenta excluir cliente
- **WHEN** a `user` member attempts to delete a client
- **THEN** the system responds 403 and the client remains available

#### Scenario: Exclusão lógica
- **WHEN** an authorized member deletes a client
- **THEN** the client is omitted from ordinary reads, its historical record is retained and it no longer consumes the active client limit

### Requirement: Listagem operacional da carteira
The system SHALL provide server-side pagination, sorting, text search and filters for internal status, tax regime and fiscal-access deadline status. The procuração e-CAC deadline status used for filtering and sorting SHALL be the state derived from the client's authorized service families, as defined in the requirement on the derived procuração e-CAC below, and SHALL NOT read member-typed procuração data.

#### Scenario: Pesquisa textual
- **WHEN** a member searches by part of a name, trade name, CPF or CNPJ
- **THEN** the result contains only matching clients from the current Account and includes pagination metadata

#### Scenario: Filtro de pendências
- **WHEN** a member filters clients whose certificate or procuração is expired or near expiration
- **THEN** the result contains only clients matching that deadline state, with the procuração state derived from the authorized service families

#### Scenario: Ordenação pela procuração
- **WHEN** a member sorts the list by procuração e-CAC
- **THEN** clients are ordered by the earliest expiration of the service families that make up their procuração, and clients without a confirmed family are placed together at one end

### Requirement: Página de clientes conectada à API real
The system SHALL present `/customers` in Portuguese with columns for Cliente, CPF/CNPJ, regime tributário, situação interna, certificado A1, procuração e-CAC and row actions, replacing all mock customer data. The procuração e-CAC column SHALL show the derived state and the earliest expiration among the service families that make it up, and SHALL NOT offer any action to register, edit or remove a procuração.

#### Scenario: Carteira vazia
- **WHEN** an authenticated member opens `/customers` and the current Account has no clients
- **THEN** the page displays an empty state with an action to create the first client

#### Scenario: Falha de carregamento
- **WHEN** the client list request fails
- **THEN** the page displays an error state and an action to retry without showing stale mock data

#### Scenario: Visualização móvel
- **WHEN** the page is opened on a narrow viewport
- **THEN** client identity, situation and fiscal alerts remain accessible and other data remains available through details or actions

#### Scenario: Coluna de procuração somente leitura
- **WHEN** an `admin` or `operador` member views the procuração e-CAC column of a client
- **THEN** the column shows sem procuração, válida, a vencer or vencida with the earliest expiration date, and no register, edit or remove action is offered

### Requirement: Procuração e-CAC derivada das famílias autorizadas
The system SHALL return, for each client, a procuração e-CAC summary derived from the authorized service families the provider confirmed, restricted to the families required by the monitoring obligations associated with the client, or to all confirmed families when no associated obligation requires one. The summary SHALL contain the state (`missing`, `valid`, `expiring`, `expired`), the earliest expiration date and the families that compose it, and SHALL NOT contain member-typed dates, codes, notes, tokens or any provider credential. The precedence SHALL be: `expired` when any considered family has expired; otherwise `missing` when any required family has no established confirmation, or when no family is confirmed at all; otherwise `expiring` when the earliest expiration falls within 30 calendar days; otherwise `valid`.

#### Scenario: Todas as famílias exigidas em vigor
- **WHEN** every family required by a client's associated obligations is established and the earliest one expires more than 30 days from today
- **THEN** the client's procuração summary is `valid` with that earliest date and the list of families

#### Scenario: Uma família exigida sem confirmação
- **WHEN** a client's associated obligations require two families and the provider confirmed only one of them
- **THEN** the procuração summary is `missing` and names the family that is not confirmed

#### Scenario: Família vencida
- **WHEN** any family considered for a client has an expiration date earlier than today
- **THEN** the procuração summary is `expired` with that date

#### Scenario: Cliente sem módulo que exija família
- **WHEN** a client has no associated obligation requiring a family and the provider confirmed no family for it
- **THEN** the procuração summary is `missing`

#### Scenario: Resposta sem segredos
- **WHEN** a member of the current Account requests a client or the client list
- **THEN** the response carries the summary and no token, certificate content, password or storage path

#### Scenario: Cliente de outra Account
- **WHEN** a member requests a client that belongs to another Account
- **THEN** the system responds 404 and reveals no procuração data

### Requirement: Etapa de módulos no cadastro do cliente
The system SHALL, after a company client is created, present a modules step in the same creation flow listing the monitoring obligations the integration serves, with the obligations suggested for the client's tax regime already selected, and SHALL let the member select or deselect any of them before confirming. Confirming SHALL create the client × obligation associations described in the monitoring capability. Skipping the step SHALL keep the client saved with no association. The step SHALL be available only to `admin` and `operador` members and SHALL NOT be offered for a natural-person client. The step SHALL present no option for XML capture, because every client is included in capture.

#### Scenario: Cliente do Simples Nacional
- **WHEN** an `operador` saves a company client with tax regime Simples Nacional
- **THEN** the modules step opens with PGDAS and the other obligations mapped to Simples Nacional selected, and the member can deselect any of them before confirming

#### Scenario: Membro ajusta a sugestão
- **WHEN** the member deselects a suggested obligation, selects one that was not suggested and confirms
- **THEN** only the obligations selected at confirmation are associated with the client

#### Scenario: Etapa pulada
- **WHEN** the member closes the modules step without confirming
- **THEN** the client remains saved with no monitoring association and the list shows it

#### Scenario: Pessoa física
- **WHEN** a member saves a natural-person client
- **THEN** the creation flow ends without a modules step

#### Scenario: Falha ao confirmar os módulos
- **WHEN** the association request fails
- **THEN** the step shows a recoverable error with a retry action, keeps the member's selection and does not discard the saved client
