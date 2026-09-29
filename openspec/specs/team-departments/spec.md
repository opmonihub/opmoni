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
