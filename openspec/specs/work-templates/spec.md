# work-templates Specification

## Purpose
Permite que cada Account defina modelos de rotinas fiscais mensais com etapas completas, execução em cascata e associação por regra dinâmica (regimes + Tags) com exceções, gerando automaticamente um processo por cliente a cada competência.

## Requirements

### Requirement: Modelo pertence ao Account com recorrência mensal e cascata
The system SHALL associate every process template with exactly one Account, storing name, optional description, monthly recurrence settings (generation day, due day, active flag), and a cascade flag that enforces sequential step execution. The template's due day SHALL set the due date of each generated process; the due day of each blueprint step SHALL set the due date of the task generated from it. Both SHALL be resolved as a fixed day of the reference month, capped at the month's length.

#### Scenario: Criação de modelo PGDAS com cascata
- **WHEN** an `admin` or `operador` creates a template named PGDAS with generation day 1, due day 20 and cascade enabled
- **THEN** the system stores the template in the current Account and returns it with its recurrence and cascade settings

#### Scenario: Vencimento do processo e prazo da task
- **WHEN** a template with due day 20 and a step with due day 5 generates reference month 2026-03
- **THEN** the process is due on 2026-03-20 and the task generated from that step is due on 2026-03-05

#### Scenario: User tenta criar modelo
- **WHEN** a `user` member attempts to create a template
- **THEN** the system responds 403 and creates nothing

### Requirement: Blueprint completo de tasks do modelo
The system SHALL allow each template to hold an ordered list of blueprint steps with title, an optional reference to a department of the same Account (`department_id`, nullable), optional description, due day as a fixed day-of-month (1–31), priority (`low|medium|high|urgent`, default `medium`), display order, and an optional default assignee who MUST be a member of the same Account; step payloads SHALL return `department_id` and `department` (`{id, name, color}` with the department's current name, or `null`); a `department_id` that does not belong to the current Account SHALL be rejected with 422.

#### Scenario: Blueprint com duas etapas
- **WHEN** an authorized member saves blueprint steps "validar pró-labore" (order 1, department Fiscal, due day 3, priority medium) and "verificar valor informado" (order 2, department Pessoal, due day 5, priority high)
- **THEN** the system stores both steps in order with their department references and returns them with the template, each step carrying the department id, current name and color

#### Scenario: Etapa sem departamento
- **WHEN** an authorized member saves a blueprint step with `department_id` null
- **THEN** the system stores the step without department and returns `department` null

#### Scenario: Departamento de outra Account
- **WHEN** a blueprint step names a `department_id` that belongs to a different Account
- **THEN** the system responds 422 and stores nothing

#### Scenario: Responsável padrão fora do account
- **WHEN** a blueprint step names a default assignee who is not a member of the current Account
- **THEN** the system rejects the request with a validation error

#### Scenario: Dia de vencimento inválido
- **WHEN** a blueprint step declares a due day outside 1–31
- **THEN** the system rejects the request with a validation error

### Requirement: Associação por regra dinâmica com exceções
The system SHALL resolve eligible clients per template as: active clients of the same Account matching the regime rule (empty regime list means all regimes) AND the tag rule (empty tag list means all tags, otherwise at least one of the listed tags), PLUS explicitly added exceptions (`added`) MINUS explicitly removed exceptions (`removed`); exception entries MUST reference clients of the same Account.

#### Scenario: PGDAS para Simples Nacional com exceção removida
- **WHEN** the PGDAS template targets regime `simple_national` and client X (Simples) is listed as `removed`
- **THEN** client X is excluded from generation while other Simples clients remain eligible

#### Scenario: Cliente avulso adicionado fora da regra
- **WHEN** client Y (Lucro presumido) is listed as `added` on the PGDAS template
- **THEN** client Y is eligible even though its regime is outside the rule

#### Scenario: Cliente de outro account em exceção
- **WHEN** an exception names a client that belongs to a different Account
- **THEN** the system rejects the request with a validation error

#### Scenario: Preview de elegíveis
- **WHEN** an authorized member requests the eligibility preview of a template
- **THEN** the system returns the resolved client list with per-client match reason (rule, added) without creating any process

### Requirement: Geração mensal idempotente por cliente (manual e agendada)
The system SHALL generate at most one process per (template, client, reference month); each generated process copies a snapshot of the step (title, due day resolved to a real date capped at month length, priority, description, assignee) plus the step's department reference into tasks with initial status A fazer; repeated generation for the same triple SHALL return the existing processes without duplicates; generated data SHALL be frozen against later rule or registration changes, except the department, whose current name is always shown and whose deletion leaves the task without department.

#### Scenario: Geração manual para dez empresas
- **WHEN** an `admin` or `operador` triggers generation for the PGDAS template and reference month 2026-03 with ten eligible clients
- **THEN** the system creates exactly ten processes (one per client) plus one task per blueprint step in each, with due dates on the step's fixed day of March 2026 and the step's department reference

#### Scenario: Geração repetida não duplica
- **WHEN** generation is triggered twice for the same template and reference month over the same eligible set
- **THEN** the second call returns the already created processes and creates no new tasks

#### Scenario: Agendamento diário
- **WHEN** the daily scheduler runs and an active monthly template has reached its generation day for the current reference month with eligible clients lacking a process
- **THEN** the system generates the missing (template, client, month) processes automatically under the same idempotency rule

#### Scenario: Mudança de regime congela o gerado
- **WHEN** a client changes regime after its March process was generated
- **THEN** the March process and its tasks remain unchanged; the new regime only affects April onward

#### Scenario: Etapa sem departamento gera task sem departamento
- **WHEN** a blueprint step has no department and the template generates March 2026
- **THEN** the generated task has `department_id` null

### Requirement: Ativação e desativação do modelo
The system SHALL allow activating and deactivating a template; the scheduler SHALL skip inactive templates while manual generation and preview remain available.

#### Scenario: Modelo desativado
- **WHEN** a template is deactivated and the scheduler runs past its generation day
- **THEN** no automatic process is created for that month
