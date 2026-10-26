# work-management Specification

## Purpose

Organize office work through Account-scoped recurring templates, generated processes, assigned tasks, and calendar views.

## Requirements

### Requirement: Work resources respect role and relationship boundaries
Processes, templates, tasks, and work views SHALL be scoped to the selected Account. Members SHALL read and `admin`/`operador` SHALL perform supported writes. Client, tag, template, department, and assignee references SHALL be validated against the Account; an assignee constrained by a department SHALL belong to that department. Direct foreign work identifiers SHALL return 404 and foreign payload references SHALL return 422.

#### Scenario: Foreign template department
- **WHEN** a template payload references another Account's department
- **THEN** the API returns 422 without storing the relationship

#### Scenario: Read-only task update
- **WHEN** a `user` member attempts to update a task
- **THEN** the API returns 403 and task progress remains unchanged

### Requirement: Preview does not generate processes
Template preview SHALL show eligible clients and inclusion/exclusion reasons without creating processes. Explicit generation and scheduled recurrence SHALL use the template and reference period to create associated processes/tasks without duplicating an already generated client/template/period occurrence.

#### Scenario: Repeat monthly generation
- **WHEN** the same eligible client/template/reference month is generated again
- **THEN** the existing occurrence is not duplicated

### Requirement: Task progress and calendar views are consistent
Task updates SHALL retain valid statuses, assignees, deadlines, and process progress. Work client/process/task views SHALL expose consistent Account-scoped derived state. Calendar queries SHALL require valid date boundaries and SHALL not return an unrestricted cross-Account task set.

#### Scenario: Invalid calendar range
- **WHEN** a calendar request omits required date boundaries
- **THEN** the request returns validation errors rather than an unbounded result

### Requirement: Work competence and route state have one synchronization owner
Month-scoped Work views SHALL share one valid reference month under the selected authority and synchronize it to the URL without duplicate navigation. A missing or invalid month SHALL normalize at most once to the established valid month. A valid URL or browser-history month SHALL select the corresponding displayed competence. Synchronization SHALL preserve unrelated supported query parameters and SHALL NOT duplicate an unchanged logical list read. Returning between month-scoped views SHALL preserve competence; authority changes SHALL discard the prior private navigation state.

#### Scenario: Enter a scoped view without a month
- **WHEN** the user enters Tarefas or Clientes without a valid reference-month parameter
- **THEN** one URL replacement adds the valid shared month and one initial logical dataset is requested for that competence

#### Scenario: Change month and use history
- **WHEN** the user changes competence, moves between supported Work views and follows browser history containing a valid month
- **THEN** controls, URL and list query agree on that month without competing replacements or duplicate reset-driven reads

#### Scenario: Invalid month and unrelated parameters
- **WHEN** a Work URL contains an invalid month and other supported filters
- **THEN** the month uses the established validated fallback, other supported parameters survive, and no invalid-month data request is admitted
