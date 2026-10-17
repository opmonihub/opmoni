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
