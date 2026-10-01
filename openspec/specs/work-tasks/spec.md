# work-tasks Specification

## Purpose
Permite que cada Account execute as etapas de cada processo (um por cliente por mês), com ciclo de vida, responsável, prazo em dia fixo, prioridade e bloqueio de cascata, visíveis no calendário, na visão por cliente e no board de tarefas.

## Requirements

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

### Requirement: Ciclo de vida da task
The system SHALL support task statuses A fazer (`todo`), Em progresso (`doing`), Concluída (`done`) and Dispensada (`dismissed`); moving to Concluída SHALL stamp the completion time; moving to Dispensada SHALL stamp the completion time with a required reason; leaving Concluída or Dispensada SHALL clear the stamp.

#### Scenario: Avançar para em progresso
- **WHEN** an `admin` or `operador` moves a task from A fazer to Em progresso
- **THEN** the status is persisted and no completion time is set

#### Scenario: Concluir task
- **WHEN** an authorized member moves a task to Concluída
- **THEN** the system stores the completion timestamp

#### Scenario: Dispensar task com motivo
- **WHEN** an authorized member moves a task to Dispensada with a reason
- **THEN** the system stores the status, the reason and the completion timestamp

#### Scenario: Dispensar sem motivo recusado
- **WHEN** an authorized member moves a task to Dispensada without a reason
- **THEN** the system rejects the transition and the task is unchanged

#### Scenario: Reabrir task concluída
- **WHEN** an authorized member moves a Concluída task back to Em progresso
- **THEN** the completion timestamp is cleared

#### Scenario: User tenta alterar task
- **WHEN** a `user` member attempts to change a task
- **THEN** the system responds 403 and the task is unchanged

### Requirement: Bloqueio de cascata
The system SHALL, for processes whose template has cascade enabled, refuse advancing a task beyond A fazer while any earlier-order task of the same process is neither Concluída nor Dispensada; templates with cascade disabled SHALL allow any order.

#### Scenario: Furar sequência bloqueado
- **WHEN** an authorized member tries to move step 2 to Em progresso while step 1 is still A fazer in a cascade process
- **THEN** the system rejects the transition and step 2 stays A fazer

#### Scenario: Etapa dispensada libera a seguinte
- **WHEN** step 1 is Dispensada and an authorized member advances step 2 in a cascade process
- **THEN** the transition succeeds

#### Scenario: Sem cascata libera ordem
- **WHEN** the template has cascade disabled and step 2 advances while step 1 is A fazer
- **THEN** the transition succeeds

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

### Requirement: Feed do calendário (só com prazo)
The system SHALL provide a calendar feed over a date range returning only tasks that have a due date, each entry carrying task, process, client (via process) and assignee presentation data.

#### Scenario: Tasks sem prazo ficam fora
- **WHEN** the calendar feed is requested for March 2026 and one task has no due date
- **THEN** that task is omitted from the feed while dated tasks in range are returned

### Requirement: Kanban por status com card fiscal
The system SHALL support a kanban view with exactly four columns matching the lifecycle (A fazer, Em progresso, Concluída, Dispensada); each card SHALL display process name + reference month, client name, due date, assignee, priority and department, plus a lock marker when cascade blocks the task; status changes happen through explicit advance/return buttons calling the task API (no drag-and-drop in v1); the board SHALL share the same task listing filters (process, client, assignee, department, priority, due range).

#### Scenario: Board das tasks de março
- **WHEN** a member opens the tasks board filtered to March 2026
- **THEN** every dated task appears in its status column with process, client, due date, assignee and priority visible

#### Scenario: Avanço pelo board respeita cascata
- **WHEN** an authorized member clicks advance on a cascade-blocked card
- **THEN** the API refuses, the card stays in place and the board shows the refusal feedback with the lock marker kept

### Requirement: Agregação por cliente para a visão agrupada
The system SHALL provide a client-grouped task payload that the frontend renders as Cliente > Processo > Task (the `getGroupedRowModel` pattern with `grouping ['client_id','process_id']`), carrying per-client and per-process totals and completion ratios.

#### Scenario: Agrupamento cliente-processo-task
- **WHEN** the grouped payload is requested for March 2026
- **THEN** each client entry lists its processes of the month and each process lists its tasks with statuses

### Requirement: Reagendamento do prazo via API

The system SHALL accept `due_on` (nullable date `YYYY-MM-DD`) in `PATCH /tasks/{id}`; `admin` and `operador` members of the task's Account SHALL be able to reschedule or clear the due date; rescheduling SHALL NOT alter status, cascade position, completion timestamp or dismissal reason and SHALL NOT trigger cascade validation (moving the date is not an advancement); `user` members SHALL receive 403; malformed dates SHALL receive 422; support-mode writes SHALL be audited like other task writes; the calendar feed SHALL reflect the new date on subsequent requests.

#### Scenario: Reagendar com sucesso

- **WHEN** an `operador` patches a task with a valid `due_on`
- **THEN** the new due date is persisted and returned, with status and timestamps unchanged

#### Scenario: Limpar o prazo

- **WHEN** an authorized member patches a task with `due_on` null
- **THEN** the task has no due date and drops out of the calendar feed

#### Scenario: User tenta reagendar

- **WHEN** a `user` member patches a task's `due_on`
- **THEN** the system responds 403 and the task is unchanged

#### Scenario: Data malformada recusada

- **WHEN** an authorized member patches a task with a non-date `due_on`
- **THEN** the system responds 422 and the task is unchanged

#### Scenario: Cascata não bloqueia reagendamento

- **WHEN** step 2 of a cascade process is rescheduled while step 1 is still A fazer
- **THEN** the move succeeds because only status advancement is cascade-gated

#### Scenario: Reagendamento em suporte auditado

- **WHEN** a super_admin in support mode reschedules a task
- **THEN** the write is recorded in the support log with resource, verb and identifiers
