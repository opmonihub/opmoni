# work-calendar Specification

## Purpose
O calendário do Work dá ao escritório a visão mensal, semanal e diária dos vencimentos das rotinas fiscais por cliente, com navegação compartilhável por URL, filtros operacionais híbridos e reagendamento de prazo por arraste com atualização otimista.

## Requirements

### Requirement: Grade mensal com chips legíveis

The system SHALL render the month view as a fixed 6-row × 7-column grid covering the visible month (leading/trailing days completing the edge weeks); each day cell SHALL show the day number (today visually distinct) and its tasks as chips of `dot + truncated title` colored by status; days holding more tasks than the per-day limit SHALL render the first N chips and collapse the remainder into a `+N more` overflow control that opens a popover listing the hidden tasks.

#### Scenario: Mês cheio mostra títulos, não dots

- **WHEN** a member opens the month view for a month with dated tasks
- **THEN** each task appears as a chip with its visible title inside its due-date cell

#### Scenario: Overflow colapsa em +N

- **WHEN** a day holds more tasks than the per-day limit
- **THEN** only the first N render as chips and a `+N` control reveals the rest in a popover

#### Scenario: Dia atual destacado

- **WHEN** the visible month contains today
- **THEN** today's cell is visually distinct from the other cells

#### Scenario: Tasks sem prazo ficam fora da grade

- **WHEN** tasks of the current Account have no due date
- **THEN** they never appear in any calendar cell

### Requirement: Visões semana e dia em lista vertical

The system SHALL provide week (7 day columns) and day (single day column) views rendering each day's tasks as a vertical list ordered by status priority then title, each row carrying status dot, title, client/process names, department, priority and assignee; because tasks carry only a date with no time, the system SHALL NOT render an hourly time grid.

#### Scenario: Semana lista sete dias

- **WHEN** a member switches to the week view
- **THEN** seven day columns render, each listing that day's tasks vertically

#### Scenario: Dia lista um dia com estado vazio

- **WHEN** a member opens the day view for a date with no tasks
- **THEN** the day renders with an explicit empty state instead of a blank grid

### Requirement: Header com título, switcher e navegação

The system SHALL render a header with the current period title (month name + year), a Day/Week/Month switcher, previous/next controls and a Today button; keyboard shortcuts `t` (today) and arrow keys (previous/next period) SHALL work unless the user is editing a field.

#### Scenario: Trocar de visão pelo switcher

- **WHEN** a member clicks Week in the switcher
- **THEN** the week view renders for the currently focused date and the URL updates

#### Scenario: Atalho Today

- **WHEN** a member presses `t` outside an input
- **THEN** the calendar jumps to the view containing today

### Requirement: Sidebar com mini-calendário e filtros híbridos

The system SHALL render a sidebar with a mini month picker that navigates the main view, status visibility toggles (A fazer, Em progresso, Concluída, Dispensada — all on by default) that filter the rendered chips, and compact controls exposing the same operational filters as the task listing (process, client, assignee, department, priority); clearing the filters SHALL restore the unfiltered feed.

#### Scenario: Toggle de status filtra os chips

- **WHEN** a member switches off the Concluída toggle
- **THEN** concluded tasks disappear from every rendered day without a new fetch

#### Scenario: Mini-calendário navega

- **WHEN** a member picks a day in the mini-calendário
- **THEN** the main view navigates to the period containing that date

### Requirement: URL compartilhável da visão

The system SHALL encode the calendar position in the query string (`view=month|week|day`, `date=YYYY-MM-DD`); missing or invalid values SHALL fall back to the month view of the current date; every navigation (prev/next, Today, switcher, mini-calendário) SHALL update the URL so a copied link renders the same view.

#### Scenario: Link compartilhado reproduz a visão

- **WHEN** a member opens a link with a valid view and date query
- **THEN** the calendar renders exactly that view and date

#### Scenario: Query inválida cai no mês atual

- **WHEN** a member opens the calendar with a missing or malformed view/date query
- **THEN** the month view of the current date renders

### Requirement: Popover da tarefa com ações do board

The system SHALL open a popover when a member clicks a task chip, showing title, client/process names, department, priority, due date, assignee and status; members with work-management permission SHALL get the same actions as the task board (advance, return, assign responsible, dismiss with required reason) calling the same task API; members without that permission SHALL see details plus the process link only; the popover SHALL always link to the process detail.

#### Scenario: Avançar pelo popover

- **WHEN** an authorized member clicks advance inside the task popover
- **THEN** the status updates via the task API and the chip reflects the new status

#### Scenario: Leitura sem ações

- **WHEN** a member without work-management permission opens the task popover
- **THEN** details and the process link render with no action buttons

#### Scenario: Dispensa exige motivo no popover

- **WHEN** an authorized member dismisses a task from the popover without a reason
- **THEN** the dismissal is refused until a reason is provided

### Requirement: Reagendamento por arraste com rollback

The system SHALL allow members with work-management permission to drag a task chip onto another day cell, moving the chip immediately (optimistic update) and persisting the new `due_on` via the task API; on API failure the chip SHALL roll back to its origin day and a toast SHALL explain the refusal; drag SHALL only change the due date, never the status; dismissed tasks SHALL NOT be draggable.

#### Scenario: Arraste persiste a nova data

- **WHEN** an authorized member drops a chip on another day and the API accepts it
- **THEN** the chip stays on the target day with the new due date

#### Scenario: Falha reverte o chip

- **WHEN** an authorized member drops a chip on another day and the API refuses it
- **THEN** the chip returns to its origin day and an error toast explains why

#### Scenario: Dispensada não arrasta

- **WHEN** a member tries to drag a dismissed task chip
- **THEN** the chip is not draggable

### Requirement: Carga, vazio e erro

The system SHALL render skeleton placeholders while the month feed loads, an explicit empty state when the visible range has no dated tasks, and an error alert with a retry action when the feed request fails.

#### Scenario: Feed vazio

- **WHEN** the visible range has no dated tasks
- **THEN** an empty state explains that generated routines with due dates appear there

#### Scenario: Erro com nova tentativa

- **WHEN** the calendar feed request fails
- **THEN** an error alert with a retry button renders instead of the grid
