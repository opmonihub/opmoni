# accounts Specification

## Purpose
Define os escritórios como unidades isoladas de operação, com membros em três níveis e criação restrita à plataforma.

## Requirements

### Requirement: Níveis de membro por conta
The system SHALL support exactly three membership roles per account: `admin`, `operador` and `user`. An `admin` manages everything in the account including members and subscription; an `operador` creates, reads, updates and deletes the account's operational resources but cannot manage members; a `user` reads resources and performs only their own actions.

#### Scenario: Operador tenta gerenciar membros
- **WHEN** a member with role `operador` requests member management in their account
- **THEN** the system responds 403

#### Scenario: Admin gerencia membros
- **WHEN** a member with role `admin` invites or removes a member of their account
- **THEN** the membership is created or removed

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
