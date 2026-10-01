## MODIFIED Requirements

### Requirement: Task é etapa de um processo
The system SHALL represent each task with its Account, process (whose client it inherits — tasks carry NO direct client link), title, an optional reference to a department of the same Account (`department_id`, nullable), optional description, status, due date resolved from the step's fixed day-of-month capped at month length, priority snapshot, optional assignee (a member of the same Account), completion timestamp and order; task payloads SHALL return `department_id` and `department` (`{id, name, color}` with the department's current name, or `null`).

#### Scenario: Tasks do PGDAS 03/2026 da empresa X
- **WHEN** the (PGDAS, client X, 2026-03) process is generated from a two-step blueprint with due days 3 and 5
- **THEN** two tasks are created in order with due dates 2026-03-03 and 2026-03-05 and initial status A fazer

#### Scenario: Dia 31 em fevereiro
- **WHEN** a step declares due day 31 and the reference month is February 2026
- **THEN** the task due date resolves to 2026-02-28

#### Scenario: Responsável fora do account
- **WHEN** a task assignment names a user who is not a member of the current Account
- **THEN** the system rejects the request with a validation error

#### Scenario: Task mostra o nome atual do departamento
- **WHEN** the Fiscal department is renamed to Tributário after a task referencing it was generated
- **THEN** the task payload returns `department.name` Tributário

### Requirement: Listagem com filtros operacionais
The system SHALL provide task listing filterable by process, client (via the process), status, assignee, department (`department_id` of the current Account), priority and due-date range, ordered by due date (tasks without due date last) and then by order; the same department filter SHALL apply to the board and the calendar feed; a `department_id` filter that does not belong to the current Account SHALL respond 422.

#### Scenario: Filtrar por responsável e status
- **WHEN** a member filters tasks by assignee self and status Em progresso
- **THEN** only matching tasks of the current Account are returned

#### Scenario: Filtrar por departamento
- **WHEN** a member filters tasks by the `department_id` of Pessoal
- **THEN** only tasks of the current Account referencing Pessoal are returned, and tasks without department are omitted

#### Scenario: Filtro com departamento de outra Account
- **WHEN** a member filters tasks by a `department_id` that belongs to a different Account
- **THEN** the system responds 422
