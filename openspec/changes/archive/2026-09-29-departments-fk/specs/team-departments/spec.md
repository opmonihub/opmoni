## ADDED Requirements

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
