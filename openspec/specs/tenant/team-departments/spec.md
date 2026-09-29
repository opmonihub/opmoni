# team-departments Specification

## Purpose
Permite que cada Account organize sua equipe em departamentos (Fiscal, Pessoal...) com membros vinculados, para que o Work possa referenciar departamento + responsável nas tarefas.

## Requirements

### Requirement: Departamento pertence ao Account com nome único e cor
The system SHALL associate every department with exactly one Account, storing name (unique per Account, trimmed) and color from the closed set (`neutral|primary|success|info|warning|error`).

#### Scenario: Criação do Fiscal
- **WHEN** an `admin` or `operador` creates a department named Fiscal with color success
- **THEN** the system stores it in the current Account and returns it with its color

#### Scenario: Nome duplicado com caixa diferente
- **WHEN** an authorized member creates "fiscal" while "Fiscal" exists in the same Account
- **THEN** the system trims and compares and rejects the request with a validation error

#### Scenario: User tenta criar departamento
- **WHEN** a `user` member attempts to create a department
- **THEN** the system responds 403 and creates nothing

#### Scenario: Isolamento entre escritórios
- **WHEN** a member of Account A lists departments
- **THEN** departments of Account B are never returned, and direct access to a foreign department id responds 404

### Requirement: Vínculo N:N de membros ao departamento
The system SHALL allow each department to link members of the same Account (a member MAY belong to several departments); every linked `member_id` MUST reference a user holding membership (`account_user`) in the current Account.

#### Scenario: Fiscal com três membros
- **WHEN** an authorized member saves the Fiscal department with three member ids of the current Account
- **THEN** the system stores the three links and returns the department with its members

#### Scenario: Membro de outro account no vínculo
- **WHEN** a department payload names a user who is not a member of the current Account
- **THEN** the system rejects the request with a validation error

#### Scenario: Remoção de vínculo mantém o usuário
- **WHEN** an authorized member removes a member from a department
- **THEN** the link is deleted and the user keeps its account membership and other department links

### Requirement: CRUD conforme nível do membro
The system SHALL allow `admin` and `operador` members to create, update and delete departments, while `user` members can only read departments in the current Account.

#### Scenario: Operador renomeia departamento
- **WHEN** an `operador` renames a department in the current Account
- **THEN** the change is persisted and the updated department is returned

#### Scenario: User tenta excluir departamento
- **WHEN** a `user` member attempts to delete a department
- **THEN** the system responds 403 and the department remains available

### Requirement: Auditoria de suporte
The system SHALL record department writes performed in support mode in the support audit log.

#### Scenario: Escrita em suporte auditada
- **WHEN** a super_admin in support mode creates a department
- **THEN** the write is recorded in the support log with resource, verb and identifiers
