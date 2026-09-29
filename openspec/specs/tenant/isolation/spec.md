# isolation Specification

## Purpose
Garante que os dados de um escritório nunca sejam visíveis ou alteráveis a partir de outro escritório ou por quem não pertence a ele.

## Requirements

### Requirement: Escopo de recursos por conta atual
The system SHALL return and accept writes only for resources (clients, SERPRO monitorings, documents, processes) belonging to the requester's current account.

#### Scenario: Listagem isolada
- **WHEN** a member of account A lists clients while a member of account B owns clients
- **THEN** only account A's clients are returned

#### Scenario: Acesso direto a recurso alheio
- **WHEN** a member of account A requests a specific resource of account B by id
- **THEN** the system responds 404 as if the resource did not exist

### Requirement: Conta fixa para usuário comum
The system SHALL resolve a regular user's current account exclusively from their own membership; requests targeting any other account are rejected.

#### Scenario: Troca de conta por usuário comum
- **WHEN** a regular user attempts to switch to an account they do not belong to
- **THEN** the system responds 403 and the current account is unchanged

### Requirement: Rotas administrativas exigem super_admin
The system SHALL reject with 403 any request to global admin or support routes from a non-super_admin user, regardless of their account role.

#### Scenario: Admin de conta tenta rota global
- **WHEN** an account-level `admin` (not super_admin) requests a global admin route
- **THEN** the system responds 403
