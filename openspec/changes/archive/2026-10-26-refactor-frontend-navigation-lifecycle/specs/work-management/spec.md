## ADDED Requirements

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
