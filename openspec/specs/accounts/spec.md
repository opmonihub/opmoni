# accounts Specification

## Purpose
Define os escritórios como unidades isoladas de operação, com membros em três níveis e criação restrita à plataforma.

## Requirements

### Requirement: Níveis de membro por conta
The system SHALL support exactly three membership roles per account: `admin`, `operador` and `user`, and every other spec SHALL state only its exceptions to the matrix below.

- `admin` SHALL read and write every tenant resource, including members, the subscription and the SERPRO enablement of the account.
- `operador` SHALL read and write every tenant resource except members, the subscription and the SERPRO enablement, which respond 403.
- `user` SHALL read every tenant resource and SHALL write only its own saved client filters; every other write SHALL respond 403.

#### Scenario: Operador tenta gerenciar membros
- **WHEN** a member with role `operador` requests member management in their account
- **THEN** the system responds 403

#### Scenario: Admin gerencia membros
- **WHEN** a member with role `admin` invites or removes a member of their account
- **THEN** the membership is created or removed

#### Scenario: Operador escreve recurso operacional
- **WHEN** an `operador` creates, updates or deletes a client, department, template, process or task of the current account
- **THEN** the write is applied

#### Scenario: User lê recurso do tenant
- **WHEN** a `user` reads any tenant resource
- **THEN** the system responds 200

#### Scenario: User tenta escrever
- **WHEN** a `user` attempts any write other than its own saved client filters
- **THEN** the system responds 403 and nothing changes

#### Scenario: User salva o próprio filtro
- **WHEN** a `user` creates or deletes one of its own saved client filters
- **THEN** the write is applied

### Requirement: Criação de contas restrita ao super_admin
The system SHALL allow account creation only to super_admins, either via the global panel or the resulting membership of the initial onboarding.

#### Scenario: Usuário comum tenta criar conta
- **WHEN** a regular user requests account creation
- **THEN** the system responds 403 and no account is created

### Requirement: Suspensão em vez de exclusão
The system SHALL support suspending and reactivating accounts instead of deleting them; a suspended account blocks all tenant access but preserves its data.

#### Scenario: Conta suspensa
- **WHEN** an account is suspended and a member requests any tenant resource
- **THEN** the system responds 403 until the account is reactivated
