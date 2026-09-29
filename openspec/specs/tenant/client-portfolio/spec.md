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
The system SHALL provide server-side pagination, sorting, text search and filters for internal status, tax regime and fiscal-access deadline status.

#### Scenario: Pesquisa textual
- **WHEN** a member searches by part of a name, trade name, CPF or CNPJ
- **THEN** the result contains only matching clients from the current Account and includes pagination metadata

#### Scenario: Filtro de pendências
- **WHEN** a member filters clients whose certificate or procuração is expired or near expiration
- **THEN** the result contains only clients matching that deadline state

### Requirement: Página de clientes conectada à API real
The system SHALL present `/customers` in Portuguese with columns for Cliente, CPF/CNPJ, regime tributário, situação interna, certificado A1, procuração e-CAC and row actions, replacing all mock customer data.

#### Scenario: Carteira vazia
- **WHEN** an authenticated member opens `/customers` and the current Account has no clients
- **THEN** the page displays an empty state with an action to create the first client

#### Scenario: Falha de carregamento
- **WHEN** the client list request fails
- **THEN** the page displays an error state and an action to retry without showing stale mock data

#### Scenario: Visualização móvel
- **WHEN** the page is opened on a narrow viewport
- **THEN** client identity, situation and fiscal alerts remain accessible and other data remains available through details or actions
