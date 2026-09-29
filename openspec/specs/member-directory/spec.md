# member-directory Specification

## Purpose
Permite que qualquer membro do Account veja quem pode executar tarefas (nome, papel e departamentos), sem expor email e sem abrir a gestão de membros.

## Requirements

### Requirement: Diretório legível por qualquer membro do tenant
The system SHALL expose a read-only member directory for the current Account listing every member ordered by name with `id`, `name`, `role` and its departments (`id`, `name`, `color`); the payload MUST NOT contain email or any credential. The directory SHALL be the data source of Equipe › Membros for every member, and invite, role update and removal SHALL stay on the separate members management endpoints, which only an `admin` may call.

#### Scenario: Qualquer membro lista a equipe
- **WHEN** an `admin`, `operador` or `user` requests the member directory
- **THEN** the system responds 200 with all members of the current Account ordered by name, with roles and departments and without email

#### Scenario: Gestão de membros continua só-admin
- **WHEN** an `operador` attempts to invite a member via the members endpoint
- **THEN** the system responds 403 and creates nothing
