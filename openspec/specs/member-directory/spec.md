# member-directory Specification

## Purpose
Permite que qualquer membro do Account veja quem pode executar tarefas (nome, papel e departamentos), sem expor email e sem abrir a gestão de membros.

## Requirements

### Requirement: Diretório legível por qualquer membro do tenant
The system SHALL expose a read-only member directory for the current Account listing every member ordered by name with `id`, `name`, `role` and its departments (`id`, `name`, `color`); the payload MUST NOT contain email or any credential.

#### Scenario: Operador lista a equipe
- **WHEN** an `operador` requests the member directory
- **THEN** the system returns all members of the current Account ordered by name with roles and departments and without email

#### Scenario: User lista a equipe
- **WHEN** a `user` member requests the member directory
- **THEN** the system returns the same directory payload (200), since reading is allowed for every tenant member

#### Scenario: Isolamento entre escritórios
- **WHEN** a member of Account A requests the directory
- **THEN** members of Account B are never returned

#### Scenario: Gestão de membros continua só-admin
- **WHEN** an `operador` attempts to invite a member via the members endpoint
- **THEN** the system responds 403 and creates nothing

### Requirement: Diretório alimenta a UI Equipe sem misturar gestão no payload
The system SHALL keep the member directory read-only payload (`id`, `name`, `role`, departments; no email) as the data source for the Equipe → Membros list for every tenant member. Invite, role update and remove MUST continue to use the separate members management endpoints authorized by `manageMembers`, not the directory endpoint.

#### Scenario: Directory permanece sem email
- **WHEN** any tenant member requests the member directory
- **THEN** the response includes name, role and departments and MUST NOT include email

#### Scenario: Gestão continua só-admin na API
- **WHEN** an `operador` attempts to invite a member via the members endpoint
- **THEN** the system responds 403 and creates nothing
