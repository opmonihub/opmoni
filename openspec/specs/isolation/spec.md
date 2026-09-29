# isolation Specification

## Purpose
Garante que os dados de um escritório nunca sejam visíveis ou alteráveis a partir de outro escritório ou por quem não pertence a ele.

## Requirements

### Requirement: Escopo de recursos por conta atual
The system SHALL return and accept writes only for tenant resources belonging to the requester's current account. This rule SHALL apply to every resource that carries an account, including clients, certificates, departments, templates, processes, tasks, fiscal documents, SERPRO monitorings, runs and authorization terms, and other specs SHALL NOT restate it. A resource of another account SHALL respond 404, as if it did not exist, and a payload referencing another account's resource SHALL respond 422.

#### Scenario: Listagem isolada
- **WHEN** a member of account A lists any tenant resource while account B owns resources of the same kind
- **THEN** only account A's resources are returned

#### Scenario: Acesso direto a recurso alheio
- **WHEN** a member of account A requests a specific resource of account B by id
- **THEN** the system responds 404 and reveals no data of that resource

#### Scenario: Referência a recurso alheio no payload
- **WHEN** a payload of account A names a client, member or department of account B
- **THEN** the system responds 422 and writes nothing

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
