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

### Requirement: Departamentos padrão na criação da Account
The system SHALL create, for every new Account, the departments Fiscal (`success`), Pessoal (`info`), Contábil (`primary`) and Societário (`warning`) in that Account, with no linked members; these default departments SHALL behave like any other department and MAY be renamed, recolored or deleted by authorized members; creating the defaults SHALL NOT affect departments of other Accounts.

#### Scenario: Account nova nasce com quatro departamentos
- **WHEN** a new Account is created
- **THEN** listing departments in that Account returns Fiscal, Pessoal, Contábil and Societário with their colors and zero members

#### Scenario: Padrões isolados por Account
- **WHEN** Account A and Account B are created
- **THEN** each Account has its own four default departments and a member of Account A never sees the departments of Account B

#### Scenario: Operador renomeia departamento padrão
- **WHEN** an `operador` renames the default Societário department to Legalização
- **THEN** the system responds 200 and returns the renamed department

#### Scenario: Admin exclui departamento padrão
- **WHEN** an `admin` deletes the default Contábil department
- **THEN** the system responds 204 and Contábil is no longer listed in the Account

### Requirement: Exclusão libera etapas e tasks para Sem departamento
The system SHALL never block deleting a department because of its usage; deleting a department SHALL clear the department reference of every blueprint step and task of the same Account that pointed to it, keeping the steps and tasks otherwise unchanged; steps and tasks without department SHALL be presented as "Sem departamento".

#### Scenario: Excluir departamento com tarefa aberta
- **WHEN** an authorized member deletes the Pessoal department while an A fazer task and a blueprint step reference it
- **THEN** the system responds 204, the task and the step remain with no department and keep their status, title and due date

#### Scenario: Task sem departamento no payload
- **WHEN** a member fetches a task whose department was deleted
- **THEN** the task payload carries `department_id` null and `department` null, and the interface shows "Sem departamento"

#### Scenario: User tenta excluir departamento em uso
- **WHEN** a `user` member attempts to delete a department referenced by tasks
- **THEN** the system responds 403 and the department and its references remain unchanged
