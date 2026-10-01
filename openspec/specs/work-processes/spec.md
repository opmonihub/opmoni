# work-processes Specification

## Purpose
Permite que cada Account acompanhe um processo de rotina por cliente e por competência, com status, vencimento e progresso derivado das tasks, congelado após a geração.

## Requirements

### Requirement: Processo por cliente por modelo por mês
The system SHALL represent each generated process with its Account, source template, client (same Account), reference month (first day of month), name derived from template and month, status and due date; the triple (template, client, reference month) SHALL be unique per Account. The system SHALL also accept manual processes that carry only a name, without template, client or reference month.

#### Scenario: PGDAS 03/2026 da empresa X
- **WHEN** the PGDAS template generates reference month 2026-03 for eligible client X
- **THEN** the system creates one process (PGDAS template, client X, 2026-03) with tasks cloned from the blueprint snapshot

#### Scenario: Processo manual livre
- **WHEN** an `admin` or `operador` creates a process with only a name
- **THEN** the system stores it without template, client or reference month

### Requirement: Listagem por competência, cliente e status
The system SHALL provide process listing filterable by template, reference month, client and status, ordered by reference month descending and then by name, exposing per-process total and associated-client counts for the model table.

#### Scenario: Filtrar PGDAS de março
- **WHEN** a member filters processes by the PGDAS template and reference month 2026-03
- **THEN** one process per eligible client of that month is returned

#### Scenario: Filtrar por cliente
- **WHEN** a member filters processes by client X
- **THEN** only processes of client X in the current Account are returned

### Requirement: Progresso derivado das tasks
The system SHALL expose per-process progress as total, concluded (Concluída + Dispensada), open task counts plus a completion ratio derived from its tasks.

#### Scenario: Progresso parcial
- **WHEN** a process has 4 tasks with 1 concluded and 1 dismissed
- **THEN** its detail reports total 4, completed 1, dismissed 1, open 2 and 25% completion

### Requirement: Detalhe com tasks ordenadas
The system SHALL return process detail with its client, template reference and its tasks in blueprint order, each task carrying status, due date, department, priority, assignee and cascade position.

#### Scenario: Abrir processo do mês da empresa X
- **WHEN** a member opens the PGDAS 03/2026 process of client X
- **THEN** the system returns the process with client X and its ordered tasks

### Requirement: Congelamento do gerado
The system SHALL NOT alter generated processes or their tasks when the template rule, client regime/tags or blueprint change afterwards; such changes SHALL only affect future reference months; the department is excluded from this freeze: generated tasks SHALL show the current name of the referenced department, and deleting that department SHALL leave them without department; changing which department a blueprint step references SHALL only affect future reference months.

#### Scenario: Blueprint muda após geração
- **WHEN** a blueprint step is edited after March generation
- **THEN** March processes keep the snapshot values while April processes use the new ones

#### Scenario: Renomear departamento reflete no gerado
- **WHEN** the Fiscal department is renamed to Tributário after March generation
- **THEN** March tasks that reference it show Tributário

#### Scenario: Trocar o departamento da etapa não altera o gerado
- **WHEN** a blueprint step is moved from Fiscal to Pessoal after March generation
- **THEN** March tasks keep referencing Fiscal while April tasks reference Pessoal
